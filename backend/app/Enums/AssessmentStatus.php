<?php

namespace App\Enums;

/**
 * Lifecycle of an async assessment: STT + LLM analysis run as a Horizon job.
 */
enum AssessmentStatus: string
{
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
