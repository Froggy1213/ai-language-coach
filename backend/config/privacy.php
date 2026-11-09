<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Privacy & Data Retention Configuration
    |--------------------------------------------------------------------------
    |
    | Defines retention periods for voice-session and assessment transcripts and
    | for orphan audio files stored in S3.
    |
    */

    'transcript_retention_days' => (int) env('PRIVACY_TRANSCRIPT_RETENTION_DAYS', 90),

    'orphan_audio_retention_days' => (int) env('PRIVACY_ORPHAN_AUDIO_RETENTION_DAYS', 7),
];
