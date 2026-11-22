<?php

namespace App\Actions\Accounts;

use App\Enums\ContributionStatus;
use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\OwnershipTransfer;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What an account deletion touches, read live from the database, so the request screen, the validation and the final
 * run use the same rules (design ACCOUNT_DELETION_AND_EXPORT.md §2.2, §2.3).
 */
final class DeletionPlan
{
    /** Registrations whose riders are told about a cancellation or a new organizer (as in EventController::announce). */
    public const ACTIVE_REGISTRATIONS = [RegistrationStatus::Approved, RegistrationStatus::Pending, RegistrationStatus::Waitlisted, RegistrationStatus::Invited];

    /** Public contributions: the rider chooses for each. Everything else (pending, rejected, flagged) is always deleted. */
    public static function isEligible(Contribution $c): bool
    {
        return in_array($c->status->value, ContributionStatus::visible(), true);
    }

    /** An unresolved report against it, or flagged: a copy goes to restricted moderation evidence at the run (D-04). */
    public static function hasOpenCase(Contribution $c): bool
    {
        return $c->status === ContributionStatus::Flagged || $c->reports()->whereNull('resolved_at')->exists();
    }

    /** @return Collection<int, Contribution> */
    public static function contributions(User $u): Collection
    {
        return Contribution::where('author_id', $u->id)->withCount('media')->orderByDesc('created_at')->get();
    }

    /** @return list<string> ids that need a choice */
    public static function eligibleIds(User $u): array
    {
        return self::contributions($u)->filter(fn ($c) => self::isEligible($c))->pluck('id')->values()->all();
    }

    /** Upcoming events with riders still taking part: transferred if someone accepts, otherwise cancelled with notice. */
    public static function needsHandover(Event $e): bool
    {
        return ! in_array($e->status, [EventStatus::Cancelled, EventStatus::Completed], true) && $e->ends_at->isFuture()
            && $e->registrations()->whereIn('status', array_map(fn ($s) => $s->value, self::ACTIVE_REGISTRATIONS))->exists();
    }

    /** A group trip (another member) is offered to a member; a trip only the rider uses is deleted. */
    public static function isGroupTrip(Trip $t): bool
    {
        return $t->members()->where('users.id', '!=', $t->owner_id)->exists();
    }

    /** The screen and API summary of everything the deletion touches. */
    public static function summary(User $u): array
    {
        $offers = OwnershipTransfer::where('from_user_id', $u->id)->whereIn('status', ['offered', 'declined'])->with('to:id,display_name,username')
            ->latest()->get()->groupBy(fn ($o) => $o->subject_type.':'.$o->subject_id);
        $offerOf = fn (string $type, string $id) => ($o = $offers->get("{$type}:{$id}")?->first())
            ? ['id' => $o->id, 'status' => $o->status, 'to' => ['display_name' => $o->to->display_name, 'username' => $o->to->username]] : null;

        return [
            'contributions' => self::contributions($u)->map(fn (Contribution $c) => [
                'id' => $c->id, 'title' => $c->title, 'kind' => $c->kind->value, 'status' => $c->status->value,
                'eligible' => self::isEligible($c), 'photos' => $c->media_count, 'open_case' => self::hasOpenCase($c),
            ])->values(),
            'events' => Event::where('organizer_id', $u->id)->orderBy('starts_at')->get()->map(fn (Event $e) => [
                'id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at->toIso8601String(), 'status' => $e->status->value,
                'participants' => $e->registrations()->whereIn('status', array_map(fn ($s) => $s->value, self::ACTIVE_REGISTRATIONS))->count(),
                'outcome' => self::needsHandover($e) ? 'transfer_or_cancel' : 'delete', 'offer' => $offerOf('event', $e->id),
            ])->values(),
            'trips' => Trip::where('owner_id', $u->id)->orderBy('starts_on')->get()->map(fn (Trip $t) => [
                'id' => $t->id, 'title' => $t->title,
                'members' => $t->members()->where('users.id', '!=', $u->id)->get(['users.id', 'display_name', 'username'])
                    ->map(fn ($m) => ['display_name' => $m->display_name, 'username' => $m->username, 'role' => $m->pivot->role])->values(),
                'outcome' => self::isGroupTrip($t) ? 'transfer_or_delete' : 'delete', 'offer' => $offerOf('trip', $t->id),
            ])->values(),
        ];
    }
}
