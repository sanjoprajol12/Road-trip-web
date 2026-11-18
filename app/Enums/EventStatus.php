<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Full = 'full';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /** Moves an organizer may make. Full is set and cleared by capacity, never by hand. */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Cancelled],
            self::Published, self::Full => [self::Postponed, self::Cancelled, self::Completed],
            self::Postponed => [self::Published, self::Cancelled],
            self::Cancelled, self::Completed => [],
        };
    }

    public function acceptsRegistrations(): bool
    {
        return $this === self::Published || $this === self::Full;
    }
}
