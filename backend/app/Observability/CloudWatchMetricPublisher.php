<?php

namespace App\Observability;

use Aws\CloudWatch\CloudWatchClient;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Ships the voice pipeline's measurements to CloudWatch (plan §7).
 *
 * One `PutMetricData` call per turn rather than one per stage: CloudWatch bills
 * per request, and the stages of a turn only mean anything together — a P95 over
 * TTS is read next to the LLM's, not instead of it.
 *
 * Delivery is best-effort by design. A metrics endpoint that is throttled, slow
 * or unreachable must never fail a voice session, but its failure must not be
 * silent either — the exception is reported (Sentry, in a configured
 * environment) and swallowed.
 */
final class CloudWatchMetricPublisher implements MetricPublisher
{
    public function __construct(
        private readonly CloudWatchClient $client,
        private readonly string $namespace,
        private readonly string $environment,
    ) {}

    public function putMany(array $data): void
    {
        if ($data === []) {
            return;
        }

        $timestamp = CarbonImmutable::now('UTC')->toIso8601ZuluString();

        try {
            $this->client->putMetricData([
                'Namespace' => $this->namespace,
                'MetricData' => array_map(
                    fn (MetricDatum $datum): array => [
                        'MetricName' => $datum->name,
                        'Value' => $datum->value,
                        'Unit' => $datum->unit,
                        'Timestamp' => $timestamp,
                        'Dimensions' => $this->dimensions($datum),
                    ],
                    $data,
                ),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The environment is always attached, so two environments in one account
     * cannot average into one series; the caller's dimensions come after it.
     *
     * @return list<array{Name: string, Value: string}>
     */
    private function dimensions(MetricDatum $datum): array
    {
        $dimensions = ['Environment' => $this->environment] + $datum->dimensions;

        $formatted = [];

        foreach ($dimensions as $name => $value) {
            $formatted[] = ['Name' => $name, 'Value' => $value];
        }

        return $formatted;
    }
}
