<?php

namespace Tests\Feature\Observability;

use App\Observability\CloudWatchMetricPublisher;
use App\Observability\MetricDatum;
use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The CloudWatch sink is the only place in the pipeline allowed to touch the
 * network for metrics, so its payload shape and its failure behaviour are pinned
 * here rather than discovered on a dashboard that quietly shows nothing.
 */
class CloudWatchMetricPublisherTest extends TestCase
{
    public function test_it_batches_a_turn_into_one_request_with_the_environment_dimension(): void
    {
        $captured = null;

        $client = Mockery::mock(CloudWatchClient::class);
        $client->shouldReceive('putMetricData')
            ->once()
            ->with(Mockery::on(function (array $payload) use (&$captured): bool {
                $captured = $payload;

                return true;
            }))
            ->andReturn([]);

        (new CloudWatchMetricPublisher($client, 'AiLanguageCoach/Voice', 'staging'))->putMany([
            new MetricDatum('TurnLatencyMs', 812.5, 'Milliseconds', ['Stage' => 'llm_first_token']),
            new MetricDatum('VoiceSeconds', 300.0, 'Seconds', ['Language' => 'en']),
        ]);

        $this->assertIsArray($captured);
        $this->assertSame('AiLanguageCoach/Voice', $captured['Namespace']);
        $this->assertCount(2, $captured['MetricData']);

        $turn = $captured['MetricData'][0];
        $this->assertSame('TurnLatencyMs', $turn['MetricName']);
        $this->assertSame(812.5, $turn['Value']);
        $this->assertSame('Milliseconds', $turn['Unit']);
        $this->assertSame(
            [
                ['Name' => 'Environment', 'Value' => 'staging'],
                ['Name' => 'Stage', 'Value' => 'llm_first_token'],
            ],
            $turn['Dimensions'],
        );

        $seconds = $captured['MetricData'][1];
        $this->assertSame('VoiceSeconds', $seconds['MetricName']);
        $this->assertSame('Seconds', $seconds['Unit']);
        $this->assertSame(
            [
                ['Name' => 'Environment', 'Value' => 'staging'],
                ['Name' => 'Language', 'Value' => 'en'],
            ],
            $seconds['Dimensions'],
        );
    }

    public function test_nothing_is_sent_when_there_is_nothing_to_send(): void
    {
        $client = Mockery::mock(CloudWatchClient::class);
        $client->shouldNotReceive('putMetricData');

        (new CloudWatchMetricPublisher($client, 'AiLanguageCoach/Voice', 'testing'))->putMany([]);
    }

    public function test_a_cloudwatch_failure_is_reported_and_never_breaks_the_call(): void
    {
        Exceptions::fake();

        $client = Mockery::mock(CloudWatchClient::class);
        $client->shouldReceive('putMetricData')
            ->once()
            ->andThrow(new RuntimeException('Rate exceeded'));

        (new CloudWatchMetricPublisher($client, 'AiLanguageCoach/Voice', 'testing'))
            ->putMany([new MetricDatum('VoiceSeconds', 12.0, 'Seconds')]);

        Exceptions::assertReported(
            static fn (RuntimeException $exception): bool => $exception->getMessage() === 'Rate exceeded'
        );
    }
}
