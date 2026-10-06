<?php

namespace App\Observability;

/**
 * The sink used when no metrics backend is configured (plan §7).
 *
 * Deliberately silent rather than logging: the default local setup has no
 * CloudWatch account, and a warning per turn would be noise a developer learns
 * to ignore — which is exactly how a real delivery failure gets missed later.
 */
final class NullMetricPublisher implements MetricPublisher
{
    public function putMany(array $data): void
    {
        // Nothing to do: the pipeline must behave identically with no sink.
    }
}
