<?php

namespace App\Enums;

enum VerificationKind: string
{
    case Identity = 'identity';
    case TripContributor = 'trip_contributor';
    case ExpertReviewer = 'expert_reviewer';
    case EventOrganizer = 'event_organizer';

    /** Kinds riders can apply for online. Identity checks need documents, which are not supported yet. */
    public static function applicable(): array
    {
        return [self::EventOrganizer, self::TripContributor, self::ExpertReviewer];
    }

    /** Organizer and expert decisions belong to administrators; the rest to any staff. */
    public function needsAdmin(): bool
    {
        return $this === self::EventOrganizer || $this === self::ExpertReviewer;
    }
}
