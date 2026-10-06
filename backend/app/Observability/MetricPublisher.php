<?php

namespace App\Observability;

/**
 * The metrics sink the voice pipeline writes to (plan §7).
 *
 * An interface rather than a direct CloudWatch call so the pipeline does not
 * know whether anything is listening: locally it resolves to
 * `NullMetricPublisher`, and the tests resolve it to a recorder. Delivery is
 * always best-effort — see `CloudWatchMetricPublisher`.
 */
interface MetricPublisher
{
    /**
     * @param  list<MetricDatum>  $data
     */
    public function putMany(array $data): void;
}
