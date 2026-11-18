<?php

namespace App\Enums;

/** Fields an expert reviewer can be verified in. Matches guide categories that need expert review. */
enum ExpertScope: string
{
    case Mechanical = 'mechanical';
    case Medical = 'medical';
    case RoadSafety = 'road_safety';
}
