<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CloudWatch Metrics
    |--------------------------------------------------------------------------
    |
    | Plan §7 asks for per-turn voice latency in CloudWatch next to a P95
    | dashboard. The agent already writes a structured `TURN_LATENCY {...}` line
    | to stdout and the infrastructure turns that into a log-based metric
    | (infra/terraform/cloudwatch.tf), but a log line is a fragile source of
    | truth: it breaks the day someone edits the format, and nothing fails
    | loudly when it does. These switches publish the same measurements from the
    | database row they are already stored in, so the dashboard keeps a second,
    | durable series.
    |
    | Disabled by default: a local run must never need AWS credentials, and the
    | sink stays a no-op so the voice pipeline behaves exactly as before.
    |
    */

    'cloudwatch' => [
        'enabled' => (bool) env('CLOUDWATCH_METRICS_ENABLED', false),

        'namespace' => (string) env('CLOUDWATCH_METRICS_NAMESPACE', 'AiLanguageCoach/Voice'),

        'region' => (string) env('CLOUDWATCH_METRICS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),

        /*
        | Every series carries this dimension, so one account can hold staging
        | and production without the two quietly averaging into a single P95.
        */
        'environment' => (string) env('CLOUDWATCH_METRICS_ENVIRONMENT', env('APP_ENV', 'production')),

        /*
        | A metrics call is never worth holding a learner's call open for. On
        | ECS the task role supplies the credentials, so the SDK's own default
        | chain is used unless a key pair is configured explicitly.
        */
        'timeout_seconds' => (int) env('CLOUDWATCH_METRICS_TIMEOUT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Voice Minutes Budget
    |--------------------------------------------------------------------------
    |
    | Plan §7 pairs AWS Budgets with a per-learner voice-minutes tracker: the
    | budget alerts on the account's spend, and this is the application-side
    | number an operator reads with `php artisan voice:usage-report` to see
    | which learner the spend belongs to.
    |
    */

    'voice_minutes' => [
        'monthly_budget_per_user' => (int) env('VOICE_MONTHLY_MINUTES_BUDGET', 300),
    ],

];
