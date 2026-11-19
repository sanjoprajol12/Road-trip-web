<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionStatus;
use App\Models\Contribution;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Changes the status only. The rider's own words are never rewritten; staff notes live in moderation_note. */
class ModerateContribution
{
    public function __invoke(Contribution $c, ContributionStatus $to, ?string $note, ?string $sourceUrl = null, ?string $sourceTitle = null): Contribution
    {
        if (! in_array($to, $c->status->next(), true)) {
            throw ValidationException::withMessages(['status' => ["A {$c->status->value} contribution cannot become {$to->value}."]]);
        }
        if ($to->needsReason() && mb_strlen(trim((string) $note)) < 10) {
            throw ValidationException::withMessages(['moderation_note' => ['Give a reason of at least 10 characters.']]);
        }
        if ($to === ContributionStatus::OfficiallySourced && ! $sourceUrl) {
            throw ValidationException::withMessages(['source_url' => ['Officially sourced reports need the source link.']]);
        }

        return DB::transaction(function () use ($c, $to, $note, $sourceUrl, $sourceTitle) {
            $old = $c->only(['status', 'moderation_note']);
            $c->forceFill(['status' => $to, 'moderation_note' => $note ? trim($note) : $c->moderation_note, 'source_url' => $sourceUrl ?? $c->source_url]);
            // A decision is also a review of an addendum the author added meanwhile, unless the item is pulled.
            if ($c->hasCorrectionToReview() && $to !== ContributionStatus::Flagged) {
                $c->forceFill(['author_correction_reviewed_at' => now(), 'correction_request' => null, 'correction_requested_at' => null]);
            }
            $c->save();
            if ($to !== ContributionStatus::Flagged) {
                // Reports were right if the item was pulled or marked stale; otherwise the moderator dismissed them.
                $resolution = in_array($to, [ContributionStatus::Rejected, ContributionStatus::Outdated], true) ? 'upheld' : 'dismissed';
                $c->reports()->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by_staff_id' => auth('staff')->id(), 'resolution' => $resolution]);
            }
            if ($to === ContributionStatus::OfficiallySourced) {
                $c->syncSources([['title' => $sourceTitle ?: 'Official source', 'url' => $sourceUrl, 'accessed_on' => today()->toDateString()]]);
            }
            Audit::record('contribution.'.$to->value, $c, $old, $c->only(['status', 'moderation_note', 'author_id', 'kind']));

            return $c;
        });
    }
}
