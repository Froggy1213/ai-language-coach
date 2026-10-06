<?php

namespace App\Observability;

/**
 * One measurement on its way to the metrics sink (plan §7).
 *
 * A value object rather than an array so the publisher and its callers agree on
 * units and dimensions at the type level: a metric whose unit is guessed is a
 * metric whose dashboard is a guess.
 */
final readonly class MetricDatum
{
    /**
     * @param  array<string, string>  $dimensions  extra dimensions beyond the environment
     */
    public function __construct(
        public string $name,
        public float $value,
        public string $unit = 'None',
        public array $dimensions = [],
    ) {}
}
