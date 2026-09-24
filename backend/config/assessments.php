<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assessment Audio Uploads
    |--------------------------------------------------------------------------
    |
    | Onboarding audio goes straight from the browser to S3 with a presigned
    | POST, so the limits live in the upload policy rather than in a middleware:
    | S3 rejects an oversized file or a wrong content type before it is stored
    | (plan §5). submitAssessment re-checks the object it finds in the bucket,
    | because a client can always lie about what it uploaded.
    |
    */

    'key_prefix' => 'assessments',

    'max_size_bytes' => 15 * 1024 * 1024,

    /*
    | Browsers report the recorder's codec as a parameter (`audio/webm;codecs=opus`),
    | so a content type is matched on its base type, never on the full header.
    */
    'allowed_mime_types' => [
        'audio/webm',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
    ],

    /*
    | How long a learner has to complete the upload before the signature expires.
    */
    'upload_url_ttl_minutes' => 10,

    /*
    | A batch transcription of a full-length recording takes longer than the
    | framework's 30-second HTTP default. The Deepgram package issues the
    | request itself, so the limit is applied globally in AppServiceProvider
    | rather than per call.
    */
    'transcription_timeout_seconds' => 180,

    /*
    |--------------------------------------------------------------------------
    | CEFR Analysis
    |--------------------------------------------------------------------------
    |
    | The async half of the pipeline. Plan §1 puts DeepSeek on the async roles
    | because latency does not matter here. The provider is always named
    | explicitly: `ai.default` points at OpenAI, and a prompt without a provider
    | would silently go there. The model id is configuration rather than a
    | constant because it must be confirmed against the account before the AWS
    | deploy (§7 checklist).
    |
    */

    'analysis' => [
        'provider' => env('ASSESSMENT_ANALYSIS_PROVIDER', 'deepseek'),
        'model' => env('ASSESSMENT_ANALYSIS_MODEL', 'deepseek-chat'),
        'timeout_seconds' => (int) env('ASSESSMENT_ANALYSIS_TIMEOUT', 120),
    ],

];
