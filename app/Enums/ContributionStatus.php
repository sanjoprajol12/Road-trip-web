<?php

namespace App\Enums;

enum ContributionStatus: string
{
    case PendingReview = 'pending_review';
    case Published = 'published';
    case CommunityCorroborated = 'community_corroborated';
    case OfficiallySourced = 'officially_sourced';
    case Outdated = 'outdated';
    case Rejected = 'rejected';
    case Flagged = 'flagged';

    /** Statuses anyone may see. Publication means a report passed review, not that it is true or current. */
    public static function visible(): array
    {
        return [self::Published->value, self::CommunityCorroborated->value, self::OfficiallySourced->value, self::Outdated->value];
    }

    public function needsReason(): bool
    {
        return $this === self::Rejected || $this === self::Flagged;
    }
}
