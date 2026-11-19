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
    public function __invoke(Contribution $c, ContributionStatus $to, ?string $note, ?string $sourceUrl = null): Contribution
    {
        if ($to->needsReason() && mb_strlen(trim((string) $note)) < 10) {
            throw ValidationException::withMessages(['moderation_note' => ['Give a reason of at least 10 characters.']]);
        }
        if ($to === ContributionStatus::OfficiallySourced && ! $sourceUrl) {
            throw ValidationException::withMessages(['source_url' => ['Officially sourced reports need the source link.']]);
        }

        return DB::transaction(function () use ($c, $to, $note, $sourceUrl) {
            $old = $c->only(['status', 'moderation_note']);
            $c->forceFill(['status' => $to, 'moderation_note' => $note ? trim($note) : $c->moderation_note, 'source_url' => $sourceUrl ?? $c->source_url])->save();
            if ($to !== ContributionStatus::Flagged) {
                $c->reports()->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by_staff_id' => auth('staff')->id()]);
            }
            Audit::record('contribution.'.$to->value, $c, $old, $c->only(['status', 'moderation_note', 'author_id', 'kind']));

            return $c;
        });
    }
}
