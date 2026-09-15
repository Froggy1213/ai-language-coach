<?php

namespace App\Enums;

enum LessonCardStatus: string
{
    case Locked = 'locked';
    case Ready = 'ready';
    case Completed = 'completed';
}
