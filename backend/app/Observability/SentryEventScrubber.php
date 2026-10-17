<?php

namespace App\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Redacts learner speech transcripts, practice prompts, and corrections from
 * Sentry events and breadcrumbs before they leave the process (plan §5, §7).
 *
 * Mirrors the privacy scrubber in `agent/sentry_integration.py`: we ship the
 * shape of a failure and its session/timing tags, never the learner's words.
 */
final class SentryEventScrubber
{
    public const string REDACTED = '[REDACTED]';

    /**
     * Normalized (lowercase, underscores) keys that may carry learner speech,
     * prompts, or credentials.
     *
     * @var list<string>
     */
    private const array SENSITIVE_KEYS = [
        'transcript',
        'practice_prompt',
        'practiceprompt',
        'user_utterance',
        'userutterance',
        'correction',
        'explanation',
        'last_user_transcript',
        'text_content',
        'raw_text_content',
        'user_speech',
        'learner_speech',
        'user_transcript',
        'usertranscript',
        'learner_transcript',
        'user_input',
        'learner_utterance',
        'utterance',
        'password',
        'password_confirmation',
    ];

    public static function scrubEvent(Event $event, ?EventHint $hint = null): ?Event
    {
        $extra = $event->getExtra();
        if ($extra !== []) {
            /** @var array<string, mixed> $scrubbedExtra */
            $scrubbedExtra = self::scrubValue($extra);
            $event->setExtra($scrubbedExtra);
        }

        foreach ($event->getContexts() as $name => $contextData) {
            if (is_array($contextData) && $contextData !== []) {
                /** @var array<string, mixed> $scrubbedContext */
                $scrubbedContext = self::scrubValue($contextData);
                $event->setContext($name, $scrubbedContext);
            }
        }

        $request = $event->getRequest();
        if ($request !== []) {
            /** @var array<string, mixed> $scrubbedRequest */
            $scrubbedRequest = self::scrubValue($request);
            $event->setRequest($scrubbedRequest);
        }

        $breadcrumbs = $event->getBreadcrumbs();
        if ($breadcrumbs !== []) {
            $scrubbedBreadcrumbs = [];
            foreach ($breadcrumbs as $breadcrumb) {
                $scrubbed = self::scrubBreadcrumb($breadcrumb);
                if ($scrubbed instanceof Breadcrumb) {
                    $scrubbedBreadcrumbs[] = $scrubbed;
                }
            }
            $event->setBreadcrumb($scrubbedBreadcrumbs);
        }

        return $event;
    }

    public static function scrubBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        foreach ($breadcrumb->getMetadata() as $key => $value) {
            $scrubbed = self::isSensitiveKey((string) $key)
                ? self::REDACTED
                : self::scrubValue($value);

            $breadcrumb = $breadcrumb->withMetadata((string) $key, $scrubbed);
        }

        return $breadcrumb;
    }

    public static function scrubValue(mixed $data): mixed
    {
        if (is_array($data)) {
            $scrubbed = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && self::isSensitiveKey($key)) {
                    $scrubbed[$key] = self::REDACTED;
                } else {
                    $scrubbed[$key] = self::scrubValue($value);
                }
            }

            return $scrubbed;
        }

        return $data;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }
}
