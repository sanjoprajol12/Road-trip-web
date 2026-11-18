<?php

namespace App\Enums;

enum ContributionKind: string
{
    case Trip = 'trip';
    case RoadReport = 'road_report';
    case Accommodation = 'accommodation';
    case Fuel = 'fuel';
    case Mechanic = 'mechanic';
    case Recommendation = 'recommendation';
    case Unexpected = 'unexpected';
    case Observation = 'observation';
}
