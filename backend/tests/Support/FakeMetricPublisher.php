<?php

namespace Tests\Support;

use App\Observability\MetricDatum;
use App\Observability\MetricPublisher;

final class FakeMetricPublisher implements MetricPublisher
{
    /** @var list<MetricDatum> */
    public array $published = [];

    public function putMany(array $data): void
    {
        $this->published = array_merge($this->published, $data);
    }
}
