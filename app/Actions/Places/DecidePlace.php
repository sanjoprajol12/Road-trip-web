<?php

namespace App\Actions\Places;

use App\Models\Place;
use App\Models\StaffAccount;
use App\Models\User;
use App\Notifications\PlaceReviewed;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place where a place's status changes by staff decision. Two callers:
 * - lifecycle (places.publish): publish, unpublish, request_changes, reject, archive on any place;
 * - review (places.review): only rider suggestions, only approve / request_changes / reject from review states.
 * The row is locked and re-read, so a stale or repeated decision cannot overwrite a newer one. Rider text is never
 * changed here; only status, the reviewer and the reason.
 */
class DecidePlace
{
    private const LIFECYCLE = [
        'publish' => ['draft', 'pending_review', 'changes_requested', 'archived'],
        'unpublish' => ['published'],
        'request_changes' => ['pending_review'],
        'reject' => ['pending_review', 'changes_requested'],
        'archive' => ['draft', 'published', 'rejected'],
    ];

    private const REVIEW = [
        'approve' => ['pending_review'],
        'request_changes' => ['pending_review'],
        'reject' => ['pending_review', 'changes_requested'],
    ];

    private const TO = ['publish' => 'published', 'approve' => 'published', 'unpublish' => 'draft', 'request_changes' => 'changes_requested', 'reject' => 'rejected', 'archive' => 'archived'];

    public function __invoke(StaffAccount $staff, Place $place, string $decision, ?string $note, bool $reviewOnly): Place
    {
        $rules = $reviewOnly ? self::REVIEW : self::LIFECYCLE;
        if (! isset($rules[$decision])) {
            throw ValidationException::withMessages(['decision' => ['This decision is not available here.']]);
        }
        $note = trim((string) $note);
        if (in_array($decision, ['request_changes', 'reject'], true) && mb_strlen($note) < 10) {
            throw ValidationException::withMessages(['note' => ['Give the reason (at least 10 characters); the rider sees it.']]);
        }

        $place = DB::transaction(function () use ($staff, $place, $decision, $note, $rules, $reviewOnly) {
            $p = Place::whereKey($place->id)->lockForUpdate()->firstOrFail();
            if ($reviewOnly && ! $p->submitted_by) {
                abort(404); // staff-created places are not part of the review queue
            }
            if ($decision === 'request_changes' && ! $p->submitted_by) {
                throw ValidationException::withMessages(['decision' => ['Only rider suggestions can be sent back for changes.']]);
            }
            if ($p->submitted_by && $staff->isSamePersonAs($p->submitted_by)) {
                throw ValidationException::withMessages(['decision' => ['This suggestion comes from your own rider account. Another staff member must review it.']]);
            }
            if (! in_array($p->status, $rules[$decision], true)) {
                throw ValidationException::withMessages(['decision' => ["A {$p->status} place cannot be given this decision (it may already have been decided)."]]);
            }
            $to = self::TO[$decision];
            $old = $p->only(['status', 'moderation_note']);
            $p->forceFill(['status' => $to, 'reviewed_by_staff_id' => $staff->id,
                'moderation_note' => in_array($decision, ['request_changes', 'reject'], true) ? $note : $p->moderation_note,
                'published_at' => $to === 'published' ? ($p->published_at ?? now()) : $p->published_at])->save();
            // Same audit action names for both callers, so a place has one readable history; `via` tells them apart.
            $action = $decision === 'approve' ? 'publish' : $decision;
            Audit::record('place.'.$action, $p, $old, $p->only(['status', 'moderation_note']) + ['note' => $note ?: null, 'via' => $reviewOnly ? 'review' : 'lifecycle']);

            return $p;
        });

        if ($place->submitted_by && in_array($decision, ['publish', 'approve', 'request_changes', 'reject'], true)) {
            User::find($place->submitted_by)?->notify(new PlaceReviewed($place, $place->status));
        }

        return $place;
    }
}
