<?php

namespace App\Actions\Accounts;

use App\Enums\ContributionStatus;
use App\Enums\EventStatus;
use App\Models\AccountDeletionRequest;
use App\Models\Contribution;
use App\Models\ContributionReport;
use App\Models\Event;
use App\Models\EventUpdate;
use App\Models\ModerationEvidence;
use App\Models\OwnershipTransfer;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\AccountDeletionMail;
use App\Notifications\EventChanged;
use App\Notifications\TripClosed;
use App\Support\Audit;
use App\Support\PendingFiles;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The deletion run for one due request (design §3). One transaction for every database change, so a failure leaves
 * the request scheduled and untouched for the next run; files are only queued (PendingFiles), and the caller removes
 * them after the commit (the daily command does, and retries failures). Re-running a finished request does nothing.
 */
final class ProcessAccountDeletion
{
    public const EVIDENCE_DAYS = 30;

    private const UNREVIEWED_PLACES = ['draft', 'pending_review', 'changes_requested', 'rejected'];

    /** @return 'done'|'skipped'|'failed' */
    public function run(AccountDeletionRequest $request): string
    {
        $mail = null;
        $notices = [];
        try {
            $result = DB::transaction(function () use ($request, &$mail, &$notices) {
                $req = AccountDeletionRequest::whereKey($request->id)->lockForUpdate()->first();
                if (! $req || $req->status !== 'scheduled' || $req->run_after->isFuture()) {
                    return 'skipped';
                }
                $user = User::whereKey($req->user_id)->lockForUpdate()->first();
                $counts = $user ? $this->erase($user, $req->choices ?? [], $notices) : ['already_gone' => 1];
                $mail = $user?->email;
                // D-10: only choice categories stay, never which contribution got which choice.
                $req->update(['status' => 'done', 'completed_at' => now(), 'counts' => $counts, 'choices' => (object) array_count_values($req->choices ?? []), 'last_error' => null]);
                Audit::record('account.deleted', $req, null, $counts);

                return 'done';
            });
        } catch (Throwable $e) {
            // Rolled back: nothing was deleted. Only the exception class is kept (never data); the next run retries.
            AccountDeletionRequest::whereKey($request->id)->update(['attempts' => DB::raw('attempts + 1'), 'last_error' => mb_substr($e::class, 0, 200), 'updated_at' => now()]);
            report($e);

            return 'failed';
        }
        if ($result === 'done') {
            foreach ($notices as [$people, $notification]) {
                Notification::send($people, $notification);
            }
            if ($mail) {
                Notification::route('mail', $mail)->notify(new AccountDeletionMail('completed'));
            }
        }

        return $result;
    }

    /** @param array<string, string> $choices */
    private function erase(User $user, array $choices, array &$notices): array
    {
        $id = $user->id;
        $counts = [];
        // Offers that nobody answered lapse; accepted ones already moved the event or trip.
        $handed = OwnershipTransfer::where(['from_user_id' => $id, 'status' => 'accepted'])->pluck('subject_type');
        $counts['events_handed_over'] = $handed->filter(fn ($t) => $t === 'event')->count();
        $counts['trips_handed_over'] = $handed->filter(fn ($t) => $t === 'trip')->count();
        $counts['offers_expired'] = OwnershipTransfer::where('status', 'offered')->where(fn ($q) => $q->where('from_user_id', $id)->orWhere('to_user_id', $id))
            ->update(['status' => 'expired', 'responded_at' => now()]);

        // Events (D-02): upcoming ones with riders are cancelled and kept without an organizer; the rest are deleted.
        $counts['events_cancelled'] = $counts['events_deleted'] = 0;
        foreach (Event::where('organizer_id', $id)->lockForUpdate()->get() as $event) {
            if (DeletionPlan::needsHandover($event)) {
                $body = 'The organizer closed their account and nobody took the event over, so it is cancelled.';
                $event->forceFill(['status' => EventStatus::Cancelled, 'organizer_id' => null])->save();
                EventUpdate::create(['event_id' => $event->id, 'author_id' => null, 'kind' => 'cancellation', 'body' => $body]);
                $people = $event->registrations()->whereIn('status', array_map(fn ($s) => $s->value, DeletionPlan::ACTIVE_REGISTRATIONS))->with('user')->get()->pluck('user');
                $notices[] = [$people, new EventChanged($event, 'cancellation', $body)];
                Audit::record('event.cancelled', $event, null, ['status' => 'cancelled', 'reason' => 'organizer_account_deleted', 'notified' => $people->count()]);
                $counts['events_cancelled']++;
            } else {
                $event->delete();
                $counts['events_deleted']++;
            }
        }

        // Group trips (D-03): nobody accepted, so the trip is deleted and the other members are told (title only).
        $counts['trips_deleted'] = 0;
        foreach (Trip::where('owner_id', $id)->lockForUpdate()->get() as $trip) {
            $others = $trip->members()->where('users.id', '!=', $id)->get();
            if ($others->isNotEmpty()) {
                $notices[] = [$others, new TripClosed($trip->title)];
            }
            $trip->delete();
            $counts['trips_deleted']++;
        }

        // O8: the text of this rider's contributions, and of the place suggestions deleted below, is redacted in the
        // audit history by the privileged database function (ids, dates, actions and actors stay; each redaction is
        // itself recorded as `audit.redacted`). Moderation evidence keeps its own restricted copy for 30 days.
        $counts['audit_entries_redacted'] = 0;
        $subjects = ['contributions' => Contribution::where('author_id', $id)->pluck('id'),
            'places' => DB::table('places')->where('submitted_by', $id)->whereIn('status', self::UNREVIEWED_PLACES)->pluck('id')];
        foreach ($subjects as $type => $ids) {
            $entries = DB::table('audit_logs')->where('subject_type', $type)->whereIn('subject_id', $ids)
                ->whereIn('action', ['contribution.edited', 'contribution.correction_added', 'place.resubmitted'])->pluck('id');
            foreach ($entries as $entry) {
                $counts['audit_entries_redacted'] += DB::selectOne('select mrn_redact_audit_text(?, ?) as n', [$entry, 'account_deletion'])->n > 0 ? 1 : 0;
            }
        }

        // Contributions: restricted evidence for open cases first, then the rider's choice for public ones; everything
        // else, and any public contribution without a recorded choice, is deleted (never kept without consent).
        $counts['contributions_deleted'] = $counts['contributions_anonymised'] = $counts['photos_removed'] = $counts['evidence_held'] = 0;
        foreach (Contribution::where('author_id', $id)->with(['media', 'reports'])->lockForUpdate()->get() as $c) {
            $held = [];
            if (DeletionPlan::hasOpenCase($c)) {
                $held = $this->holdEvidence($c, $user, 'open_case_on_author_deletion');
                $counts['evidence_held']++;
            }
            $choice = DeletionPlan::isEligible($c) ? ($choices[$c->id] ?? 'delete') : 'delete';
            if ($choice !== 'anonymise') {
                foreach ($c->media as $m) {
                    if (! in_array($m->path, $held, true)) {
                        PendingFiles::queue($m->disk, $m->path);
                    }
                }
                $counts['photos_removed'] += $c->media->count();
            }
            if ($choice === 'delete') {
                $c->delete(); // cascades its photo rows, confirmations and reports
                $counts['contributions_deleted']++;

                continue;
            }
            if ($choice === 'anonymise_without_photos') {
                $c->media()->delete();
            }
            $c->forceFill(['author_id' => null, 'author_deleted_at' => now()])->save();
            $counts['contributions_anonymised']++;
        }

        // Open reports this rider filed on other riders' content stay available to moderators as evidence only.
        foreach (ContributionReport::where('reporter_id', $id)->whereNull('resolved_at')->get() as $r) {
            $counts['evidence_held']++;
            ModerationEvidence::create(['contribution_id' => $r->contribution_id, 'reason' => 'open_report_by_deleted_rider', 'held_until' => now()->addDays(self::EVIDENCE_DAYS),
                'snapshot' => ['author_ref' => $this->ref($id), 'report' => ['category' => $r->category, 'reason' => $r->reason, 'filed_at' => $r->created_at?->toIso8601String()]], 'files' => []]);
        }
        $counts['reports_deleted'] = ContributionReport::where('reporter_id', $id)->delete();

        // Confirmations given: removed, and each report's last-confirmed date recomputed from the confirmations left.
        // D-11: a report that the community threshold made "community corroborated" goes back to "published" when the
        // riders still confirming it fall below the threshold. Counted: every remaining distinct confirmer other than
        // the author, without the time window or other riders' current standing, so only this removal can change the
        // outcome. A status a moderator set is a staff decision and is kept; the report itself stays published.
        $confirmed = DB::table('contribution_confirmations')->where('user_id', $id)->distinct()->pluck('contribution_id');
        $counts['confirmations_deleted'] = DB::table('contribution_confirmations')->where('user_id', $id)->delete();
        $counts['corroborations_withdrawn'] = 0;
        $threshold = (int) Settings::get('trust.corroboration_threshold');
        foreach ($confirmed as $cid) {
            $last = DB::table('contribution_confirmations')->where('contribution_id', $cid)->max('confirmed_on');
            Contribution::whereKey($cid)->update(['last_confirmed_on' => $last]);
            $c = Contribution::whereKey($cid)->lockForUpdate()->first();
            if ($c?->status !== ContributionStatus::CommunityCorroborated || ! $this->corroboratedByThreshold($c)) {
                continue;
            }
            $left = DB::table('contribution_confirmations')->where('contribution_id', $cid)
                ->when($c->author_id, fn ($q, $author) => $q->where('user_id', '!=', $author))->distinct()->count('user_id');
            if ($left < $threshold) {
                $c->forceFill(['status' => ContributionStatus::Published])->save();
                Audit::record('contribution.corroboration_withdrawn', $c, ['status' => 'community_corroborated'],
                    ['status' => 'published', 'reason' => 'confirmations_removed_on_account_deletion', 'riders' => $left, 'threshold' => $threshold]);
                $counts['corroborations_withdrawn']++;
            }
        }

        // Unreviewed place suggestions are the rider's own drafts; published places stay (submitted_by is set null).
        $counts['places_deleted'] = DB::table('places')->where('submitted_by', $id)->whereIn('status', self::UNREVIEWED_PLACES)->delete();
        $counts['notifications_deleted'] = DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        // Places the rider held on other riders' events are freed: the waitlist moves up after the row is gone.
        $joined = DB::table('event_registrations')->where('user_id', $id)->pluck('event_id');

        // The user row: cascades sessions, emergency contacts, saved items, verifications, roles, memberships, invitations,
        // registrations, place corrections and contribution suspensions; sets the remaining actor links to null.
        $user->delete();
        foreach (Event::whereIn('id', $joined)->whereIn('status', ['published', 'full'])->get() as $event) {
            \App\Actions\Events\EventCapacity::settle($event);
        }

        return $counts;
    }

    /** @return list<string> photo paths now owned by the evidence (removed when it is disposed) */
    private function holdEvidence(Contribution $c, User $author, string $reason): array
    {
        $files = $c->media->map(fn ($m) => ['disk' => $m->disk, 'path' => $m->path])->values()->all();
        ModerationEvidence::create([
            'contribution_id' => $c->id, 'reason' => $reason, 'held_until' => now()->addDays(self::EVIDENCE_DAYS), 'files' => $files,
            'snapshot' => [
                'author_ref' => $this->ref($author->id),
                'author' => ['display_name' => $author->display_name, 'username' => $author->username],
                'contribution' => $c->only(['kind', 'title', 'body', 'observed_on', 'status', 'details', 'author_correction']) + ['submitted_at' => $c->created_at?->toIso8601String()],
                'reports' => $c->reports->whereNull('resolved_at')->map(fn ($r) => ['category' => $r->category, 'reason' => $r->reason, 'filed_at' => $r->created_at?->toIso8601String()])->values()->all(),
                'photos' => $c->media->map(fn ($m) => ['alt' => $m->alt])->values()->all(),
            ],
        ]);

        return array_column($files, 'path');
    }

    /** The latest move to "community corroborated" came from the threshold (ContributionController), not a moderator. */
    private function corroboratedByThreshold(Contribution $c): bool
    {
        $entry = DB::table('audit_logs')->where(['subject_type' => 'contributions', 'subject_id' => $c->id])
            ->whereRaw("new_data->>'status' = ?", ['community_corroborated'])->orderByDesc('id')->first();

        return $entry !== null && (json_decode((string) $entry->new_data, true)['reason'] ?? null) === 'threshold';
    }

    /** A stable reference to the deleted account inside its own evidence, without being a lookup key to anything else. */
    private function ref(string $userId): string
    {
        return substr(hash_hmac('sha256', $userId, (string) config('app.key')), 0, 16);
    }
}
