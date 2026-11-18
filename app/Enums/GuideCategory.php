<?php

namespace App\Enums;

enum GuideCategory: string
{
    case Preparation = 'preparation';
    case Mechanical = 'mechanical';
    case Medical = 'medical';
    case RoadSafety = 'road_safety';
    case Weather = 'weather';
    case Emergency = 'emergency';

    /** Safety-critical guides publish only after an expert verified in this field reviewed them. */
    public function expertScope(): ?ExpertScope
    {
        return match ($this) {
            self::Mechanical => ExpertScope::Mechanical,
            self::Medical, self::Emergency => ExpertScope::Medical,
            self::RoadSafety => ExpertScope::RoadSafety,
            default => null,
        };
    }
}
