<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recurring Mistake Detection
    |--------------------------------------------------------------------------
    |
    | Plan §3 repeat-error detection policy:
    |   SELECT grammar_point_id, COUNT(DISTINCT session_id) AS session_count
    |   FROM mistakes
    |   WHERE user_id = ? AND created_at >= NOW() - INTERVAL 7 DAY
    |   GROUP BY grammar_point_id
    |   HAVING session_count >= 3;
    |
    | Grammar points flagged here are prioritized for review cards and practice.
    | The rolling window looks back `recurring_window_days` from now (UTC).
    | A grammar point must appear in at least `recurring_session_threshold`
    | distinct voice sessions within that window to be considered recurring.
    |
    */

    'recurring_window_days' => (int) env('REVIEW_RECURRING_WINDOW_DAYS', 7),

    'recurring_session_threshold' => (int) env('REVIEW_RECURRING_SESSION_THRESHOLD', 3),

];
