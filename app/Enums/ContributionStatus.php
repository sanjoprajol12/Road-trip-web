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

    /**
     * Allowed moderator decisions from this status. Rejected is final (the author submits again);
     * corroboration needs a published report first; officially sourced only goes stale or back to review.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::PendingReview => [self::Published, self::OfficiallySourced, self::Rejected, self::Flagged],
            self::Published => [self::CommunityCorroborated, self::OfficiallySourced, self::Outdated, self::Flagged, self::Rejected],
            self::CommunityCorroborated => [self::OfficiallySourced, self::Outdated, self::Flagged, self::Rejected],
            self::OfficiallySourced => [self::Outdated, self::Flagged],
            self::Outdated => [self::Published, self::CommunityCorroborated, self::OfficiallySourced, self::Rejected],
            self::Flagged => [self::Published, self::CommunityCorroborated, self::OfficiallySourced, self::Outdated, self::Rejected],
            self::Rejected => [],
        };
    }

    public function needsReason(): bool
    {
        return $this === self::Rejected || $this === self::Flagged;
    }
}
