<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Production marks the session and XSRF-TOKEN cookies Secure
 * (infra/terraform/ecs.tf sets SESSION_SECURE_COOKIE=true, and the ALB
 * terminates TLS). The local stack serves plain http, where a Secure cookie
 * would be dropped by the browser and every session would silently die — so
 * both sides of that switch are pinned here.
 */
class SecureCookiesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_session_and_xsrf_cookies_are_secure_when_configured(): void
    {
        $response = $this->csrfCookieResponse(secure: true);

        $session = $this->cookie($response->headers->getCookies(), (string) config('session.cookie'));
        $xsrf = $this->cookie($response->headers->getCookies(), 'XSRF-TOKEN');

        $this->assertInstanceOf(Cookie::class, $session, 'The session cookie must be sent.');
        $this->assertTrue($session->isSecure(), 'The session cookie must be Secure.');
        $this->assertInstanceOf(Cookie::class, $xsrf, 'The XSRF-TOKEN cookie must be sent.');
        $this->assertTrue($xsrf->isSecure(), 'The XSRF-TOKEN cookie must be Secure.');
    }

    public function test_cookies_are_not_secure_for_the_local_http_stack(): void
    {
        $response = $this->csrfCookieResponse(secure: false);

        $session = $this->cookie($response->headers->getCookies(), (string) config('session.cookie'));
        $xsrf = $this->cookie($response->headers->getCookies(), 'XSRF-TOKEN');

        $this->assertInstanceOf(Cookie::class, $session, 'The session cookie must be sent.');
        $this->assertFalse($session->isSecure(), 'A Secure cookie would never reach the local http origin.');
        $this->assertInstanceOf(Cookie::class, $xsrf, 'The XSRF-TOKEN cookie must be sent.');
        $this->assertFalse($xsrf->isSecure());
    }

    /**
     * Laravel only writes the session cookie for a persistent session driver,
     * and the suite runs on `array`. The `database` driver is what the local
     * stack and production both use, and the sessions table exists in the test
     * database, so the flag under test is the Secure attribute itself.
     */
    private function csrfCookieResponse(bool $secure): TestResponse
    {
        config([
            'session.driver' => 'database',
            'session.secure' => $secure,
        ]);

        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();

        return $response;
    }

    /**
     * @param  list<Cookie>  $cookies
     */
    private function cookie(array $cookies, string $name): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
