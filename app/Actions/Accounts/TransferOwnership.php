<?php

namespace App\Actions\Accounts;

use App\Enums\EventStatus;
use App\Enums\TripRole;
use App\Models\Event;
use App\Models\OwnershipTransfer;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\EventChanged;
use App\Notifications\OwnershipOffer;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Handing over an event (D-02) or a group trip (D-03) while the owner's deletion is scheduled. Nothing changes hands
 * until the recipient accepts. Event recipients: an approved event organizer, active, not suspended, not leaving
 * themselves. Trip recipients: any current member (viewers too). Unanswered offers lapse at the deletion run.
 */
final class TransferOwnership
{
    public function offer(User $from, string $type, string $subjectId, string $username): OwnershipTransfer
    {
        if (! $from->isDeletionScheduled()) {
            throw ValidationException::withMessages(['subject_id' => ['Offers are part of deleting your account. Request the deletion first.']]);
        }
        $to = User::where('username', $username)->first();
        if (! $to || $to->id === $from->id) {
            throw ValidationException::withMessages(['username' => ['No other rider has that username.']]);
        }
        $subject = $this->ownedSubject($from, $type, $subjectId);
        $this->assertEligible($subject, $to);

        $transfer = DB::transaction(function () use ($from, $to, $type, $subject) {
            OwnershipTransfer::where(['subject_type' => $type, 'subject_id' => $subject->id, 'status' => 'offered'])->lockForUpdate()->get()
                ->each->update(['status' => 'withdrawn', 'responded_at' => now()]);
            $t = OwnershipTransfer::create(['subject_type' => $type, 'subject_id' => $subject->id, 'from_user_id' => $from->id, 'to_user_id' => $to->id]);
            Audit::record('ownership.offered', $t, null, ['subject_type' => $type, 'subject_id' => $subject->id, 'to_user_id' => $to->id]);

            return $t;
        });
        $to->notify(new OwnershipOffer($transfer, $subject->title, 'offered', $from->display_name));

        return $transfer;
    }

    public function withdraw(User $from, OwnershipTransfer $t): void
    {
        abort_unless($t->from_user_id === $from->id, 404);
        DB::transaction(function () use ($t) {
            $n = OwnershipTransfer::whereKey($t->id)->where('status', 'offered')->update(['status' => 'withdrawn', 'responded_at' => now()]);
            if ($n !== 1) {
                throw ValidationException::withMessages(['offer' => ['This offer is no longer open.']]);
            }
            Audit::record('ownership.withdrawn', $t);
        });
    }

    public function answer(User $to, OwnershipTransfer $offer, bool $accept): void
    {
        abort_unless($offer->to_user_id === $to->id, 404); // someone else's offer looks like no offer
        $subject = DB::transaction(function () use ($to, $offer, $accept) {
            $t = OwnershipTransfer::whereKey($offer->id)->lockForUpdate()->first();
            $from = User::find($t->from_user_id);
            if ($t->status !== 'offered' || ! $from?->isDeletionScheduled()) {
                throw ValidationException::withMessages(['offer' => ['This offer is no longer open.']]);
            }
            $subject = $this->ownedSubject($from, $t->subject_type, $t->subject_id);
            if ($accept) {
                $this->assertEligible($subject, $to); // checked again: eligibility may have changed since the offer
                if ($subject instanceof Event) {
                    $subject->forceFill(['organizer_id' => $to->id])->save();
                } else {
                    $subject->forceFill(['owner_id' => $to->id])->save();
                    $subject->members()->updateExistingPivot($to->id, ['role' => TripRole::Owner->value]);
                    // The leaving rider stays an editor until the run, so a cancelled deletion still finds the trip.
                    $subject->members()->updateExistingPivot($from->id, ['role' => TripRole::Editor->value]);
                }
            }
            $t->update(['status' => $accept ? 'accepted' : 'declined', 'responded_at' => now()]);
            Audit::record($accept ? 'ownership.accepted' : 'ownership.declined', $t, null, ['subject_type' => $t->subject_type, 'subject_id' => $t->subject_id]);

            return $subject;
        });
        $offer->refresh();
        User::find($offer->from_user_id)?->notify(new OwnershipOffer($offer, $subject->title, $accept ? 'accepted' : 'declined', $to->display_name));
        if ($accept && $subject instanceof Event) {
            $people = $subject->registrations()->whereIn('status', array_map(fn ($s) => $s->value, DeletionPlan::ACTIVE_REGISTRATIONS))->with('user')->get()->pluck('user');
            Notification::send($people, new EventChanged($subject, 'update', $to->display_name.' now organizes this event.'));
        }
    }

    private function ownedSubject(User $from, string $type, string $id): Event|Trip
    {
        $subject = $type === 'event' ? Event::where(['id' => $id, 'organizer_id' => $from->id])->first() : Trip::where(['id' => $id, 'owner_id' => $from->id])->first();
        if (! $subject) {
            throw ValidationException::withMessages(['subject_id' => ['You can only hand over your own events and trips.']]);
        }
        if ($subject instanceof Event && ! DeletionPlan::needsHandover($subject)) {
            throw ValidationException::withMessages(['subject_id' => ['Only upcoming events with riders taking part can be handed over.']]);
        }
        if ($subject instanceof Trip && ! DeletionPlan::isGroupTrip($subject)) {
            throw ValidationException::withMessages(['subject_id' => ['Only trips with other members can be handed over.']]);
        }

        return $subject;
    }

    private function assertEligible(Event|Trip $subject, User $to): void
    {
        if ($to->isDeletionScheduled() || $to->isDeactivated() || ! $to->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['username' => ['This rider cannot take over.']]);
        }
        if ($subject instanceof Event) {
            if (! $to->can('create', Event::class) || $subject->status === EventStatus::Cancelled) {
                throw ValidationException::withMessages(['username' => ['Only an approved event organizer who is not suspended can take over an event.']]);
            }
        } elseif ($subject->roleOf($to) === null) {
            throw ValidationException::withMessages(['username' => ['Only a member of this trip can take it over.']]);
        }
    }
}
