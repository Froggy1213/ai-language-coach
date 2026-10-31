<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reverb's origin gate, written down before it silently costs another evening.
 *
 * `Server::verifyOrigin()` reduces the browser's `Origin` header to its host
 * (`parse_url(..., PHP_URL_HOST)`) and matches that host against every entry of
 * `allowed_origins`. An entry copied verbatim from `CORS_ALLOWED_ORIGINS`
 * ("http://localhost:3000") can therefore never match the host "localhost": the
 * socket is refused with 4009 "Origin not allowed", the SPA reports that it
 * could not establish the realtime connection, and every feedback screen loses
 * the subscription it was waiting on.
 */
class ReverbOriginTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function origins(): array
    {
        /** @var list<string> $origins */
        $origins = config('reverb.apps.apps.0.allowed_origins');

        return $origins;
    }

    public function test_every_allowed_origin_is_a_bare_host(): void
    {
        foreach ($this->origins() as $origin) {
            $this->assertStringNotContainsString('://', $origin, $origin);
            $this->assertStringNotContainsString(':', $origin, $origin);
        }
    }

    public function test_the_local_browser_hosts_are_allowed(): void
    {
        $this->assertContains('localhost', $this->origins());
        $this->assertContains('127.0.0.1', $this->origins());
    }

    public function test_the_host_of_a_browser_origin_matches_a_pattern(): void
    {
        // What the learner's browser sends vs. what Reverb keeps of it.
        $host = parse_url('http://localhost:3000', PHP_URL_HOST);

        $matched = collect($this->origins())->contains(
            static fn (string $pattern): bool => Str::is($pattern, $host),
        );

        $this->assertTrue($matched, 'Reverb would refuse Origin http://localhost:3000 with 4009.');
    }
}
