<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Resets Lighthouse GraphQL subscription storage in Redis before each test.
 *
 * Laravel's `Illuminate\Foundation\Testing\TestCase::setUpTraits()` automatically
 * calls `setUp{TraitName}()` for every trait used by a test class during `parent::setUp()`.
 * This ensures Redis subscription keys are cleared before any test method runs without
 * requiring explicit calls inside test `setUp()` methods.
 */
trait ClearsSubscriptionStorage
{
    /**
     * Automatically invoked during TestCase::setUp() via Laravel's setUpTraits() hook.
     */
    protected function setUpClearsSubscriptionStorage(): void
    {
        $this->clearSubscriptionStorage();
    }

    /**
     * Deletes all raw GraphQL subscription keys from Redis.
     *
     * Subscriptions outlive the test database — they live in Redis — and a
     * subscriber whose user has been wiped cannot be restored (its context
     * holds the model), so reading it back throws. Start each test from an
     * empty subscription storage instead; deleting the raw keys avoids the
     * restore that `subscribersByTopic()` would do.
     */
    protected function clearSubscriptionStorage(): void
    {
        $redis = Redis::connection(config('lighthouse.subscriptions.broadcasters.echo.connection', 'default'));
        $prefix = (string) config('database.redis.options.prefix', '');

        foreach ($redis->keys('*graphql.*') as $key) {
            $unprefixed = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
            $redis->del($unprefixed);
        }
    }
}
