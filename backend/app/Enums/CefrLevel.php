<?php

namespace App\Enums;

/**
 * CEFR proficiency band. V1 covers A1–C1; C2 is out of scope (plan §0).
 */
enum CefrLevel: string
{
    case A1 = 'A1';
    case A2 = 'A2';
    case B1 = 'B1';
    case B2 = 'B2';
    case C1 = 'C1';
}
