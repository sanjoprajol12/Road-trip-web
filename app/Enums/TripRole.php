<?php

namespace App\Enums;

enum TripRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function rank(): int
    {
        return match ($this) { self::Owner => 3, self::Editor => 2, self::Viewer => 1 };
    }
}
