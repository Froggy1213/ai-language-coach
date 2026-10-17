<?php

namespace Tests\Feature\Observability;

use App\Observability\SentryEventScrubber;
use RuntimeException;
use Sentry\Breadcrumb;
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
        $this->assertSame([SentryEventScrubber::class, 'scrubEvent'], config('sentry.before_send'));
        $this->assertSame([SentryEventScrubber::class, 'scrubBreadcrumb'], config('sentry.before_breadcrumb'));
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

    public function test_a_configured_dsn_delivers_a_captured_exception_via_laravel_report(): void
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
            'before_send' => [SentryEventScrubber::class, 'scrubEvent'],
            'before_breadcrumb' => [SentryEventScrubber::class, 'scrubBreadcrumb'],
        ]);
        $builder->setTransport($transport);

        $this->app->instance(ClientBuilder::class, $builder);
        $this->app->forgetInstance(HubInterface::class);

        SentrySdk::setCurrentHub($this->app->make(HubInterface::class));

        report(new RuntimeException('The voice worker lost its LLM'));

        $this->assertCount(1, $transport->events);
        $this->assertSame('testing', $transport->events[0]->getEnvironment());
        $this->assertSame('test-release', $transport->events[0]->getRelease());
        $this->assertSame(RuntimeException::class, $transport->events[0]->getExceptions()[0]->getType());
    }

    public function test_scrubber_redacts_learner_transcripts_and_prompts_while_keeping_diagnostics(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'session_id' => 42,
            'turn_id' => 'sp-101',
            'stt_final' => 250.0,
            'transcript' => 'I have visited Rome yesterday.',
            'practice_prompt' => 'Talk about your last holiday.',
            'nested' => [
                'user_utterance' => 'I has a dog.',
                'correction' => 'I have a dog.',
                'explanation' => 'Use have with first person singular.',
                'password' => 'secret123',
            ],
        ]);
        $event->setContext('voice_session', [
            'room_name' => 'lesson-42',
            'learner_speech' => 'Private spoken text',
        ]);
        $event->setBreadcrumb([
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'voice',
                'Turn recorded',
                [
                    'turn_id' => 'sp-101',
                    'transcript' => 'Private breadcrumb speech',
                ],
            ),
        ]);

        $scrubbed = SentryEventScrubber::scrubEvent($event);

        $this->assertNotNull($scrubbed);
        $extra = $scrubbed->getExtra();
        $this->assertSame(42, $extra['session_id']);
        $this->assertSame('sp-101', $extra['turn_id']);
        $this->assertSame(250.0, $extra['stt_final']);
        $this->assertSame(SentryEventScrubber::REDACTED, $extra['transcript']);
        $this->assertSame(SentryEventScrubber::REDACTED, $extra['practice_prompt']);
        $this->assertSame(SentryEventScrubber::REDACTED, $extra['nested']['user_utterance']);
        $this->assertSame(SentryEventScrubber::REDACTED, $extra['nested']['correction']);
        $this->assertSame(SentryEventScrubber::REDACTED, $extra['nested']['explanation']);
        $this->assertSame(SentryEventScrubber::REDACTED, $extra['nested']['password']);

        $contexts = $scrubbed->getContexts();
        $this->assertSame('lesson-42', $contexts['voice_session']['room_name']);
        $this->assertSame(SentryEventScrubber::REDACTED, $contexts['voice_session']['learner_speech']);

        $breadcrumbs = $scrubbed->getBreadcrumbs();
        $this->assertCount(1, $breadcrumbs);
        $this->assertSame('sp-101', $breadcrumbs[0]->getMetadata()['turn_id']);
        $this->assertSame(SentryEventScrubber::REDACTED, $breadcrumbs[0]->getMetadata()['transcript']);
    }
}
