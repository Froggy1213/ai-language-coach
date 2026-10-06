<?php

namespace Tests\Feature\Observability;

use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\ServiceProvider;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

/**
 * Plan §7 requires Sentry for Laravel, and §1 makes it mandatory before the AWS
 * deploy. The package is auto-discovered and inert without a DSN, so what needs
 * pinning is the part a future edit could silently break: that no PII is ever
 * attached, that an unconfigured app has nowhere to report to, and that a
 * configured one really delivers an exception.
 */
class SentryConfigurationTest extends TestCase
{
    private ?HubInterface $previousHub = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The hub is process-global; a stub transport left installed would
        // swallow every later test's reports.
        $this->previousHub = SentrySdk::getCurrentHub();
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->previousHub);

        parent::tearDown();
    }

    public function test_the_sdk_is_discovered_and_boots_with_the_application(): void
    {
        $this->assertTrue(class_exists(ServiceProvider::class));
        $this->assertTrue($this->app->bound(HubInterface::class));
    }

    public function test_personal_data_is_never_attached_by_default(): void
    {
        // The learner's speech is personal data; the SDK's default has to stay
        // off, which is exactly the kind of default an upgrade can flip.
        $this->assertFalse((bool) config('sentry.send_default_pii'));
    }

    public function test_without_a_dsn_there_is_nothing_to_report_to(): void
    {
        $this->assertEmpty(config('sentry.dsn'));

        $client = $this->app->make(HubInterface::class)->getClient();

        $this->assertTrue(
            $client === null || $client->getOptions()->getDsn() === null,
            'An application without a DSN must not be pointed at a Sentry instance.',
        );
    }

    public function test_a_configured_dsn_delivers_a_captured_exception(): void
    {
        $transport = new class implements TransportInterface
        {
            /** @var list<Event> */
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $builder = ClientBuilder::create([
            'dsn' => 'https://public@example.com/1',
            'environment' => 'testing',
            'release' => 'test-release',
            'send_default_pii' => false,
        ]);
        $builder->setTransport($transport);

        $this->app->instance(ClientBuilder::class, $builder);
        $this->app->forgetInstance(HubInterface::class);

        SentrySdk::setCurrentHub($this->app->make(HubInterface::class));

        $eventId = \Sentry\captureException(new RuntimeException('The voice worker lost its LLM'));

        $this->assertNotNull($eventId);
        $this->assertCount(1, $transport->events);
        $this->assertSame('testing', $transport->events[0]->getEnvironment());
        $this->assertSame('test-release', $transport->events[0]->getRelease());
        $this->assertSame(RuntimeException::class, $transport->events[0]->getExceptions()[0]->getType());
    }
}
