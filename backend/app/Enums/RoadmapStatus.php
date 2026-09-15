<?php

namespace App\Enums;

enum RoadmapStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';
}
