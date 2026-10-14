import { test, expect } from '@playwright/test';
import { signedInShell, trackPageErrors } from './helpers';

/**
 * Guards the hostname the SPA dials the API on.
 *
 * `localhost` and `127.0.0.1` are two names for this machine but two *sites* for
 * the browser's cookie jar: a `SameSite=Lax` session cookie issued by one is
 * withheld from a request to the other. This stack publishes the API and the SPA
 * on both names while the built image carries a single
 * `NUXT_PUBLIC_BACKEND_URL`, so which name a learner typed is only knowable at
 * runtime — `utils/backend-endpoint.ts` aligns the configured origin with the
 * page's own hostname, and this spec is what keeps that from regressing.
 *
 * A mismatch does not look like a broken URL in the browser. It looks like a
 * working page whose sign-in silently fails (419 / unauthenticated) because the
 * cookie never arrives, which is exactly the class of defect (README decisions
 * 20, 21, 34) that only a real browser can see.
 */

/** Hostnames that all mean "this machine" but not "the same site". */
const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '::1', '[::1]']);

test.describe('Backend origin alignment @smoke', () => {
  test('every backend request stays on the hostname the SPA was served from', async ({ page }) => {
    const tracker = trackPageErrors(page);

    const backendHosts = new Set<string>();
    const failedRequests: string[] = [];
    const graphqlResponses: { url: string; status: number }[] = [];

    page.on('request', (request) => {
      const { pathname } = new URL(request.url());

      if (pathname.startsWith('/graphql') || pathname.startsWith('/sanctum/')) {
        backendHosts.add(new URL(request.url()).host);
      }
    });

    // Recorded from a listener rather than awaited with `page.waitForResponse`:
    // the app's first query can land before the call to `waitForResponse` is
    // reached, which made this spec pass or fail depending on how fast the page
    // was.
    page.on('response', (response) => {
      if (new URL(response.url()).pathname === '/graphql') {
        graphqlResponses.push({ url: response.url(), status: response.status() });
      }
    });

    page.on('requestfailed', (request) => {
      const { pathname } = new URL(request.url());

      if (pathname.startsWith('/graphql') || pathname.startsWith('/sanctum/')) {
        failedRequests.push(`${request.url()} (${request.failure()?.errorText ?? 'unknown'})`);
      }
    });

    // The signed-in home screen: it issues `me` before it renders, so the
    // requests under test are produced by simply loading the app.
    await page.goto('/');
    await expect(signedInShell(page)).toBeVisible();

    const pageUrl = new URL(page.url());
    const pageHostname = pageUrl.hostname;

    // The API answered, over the origin the SPA chose.
    expect(failedRequests).toEqual([]);
    expect(graphqlResponses.length, 'the SPA must issue at least one /graphql request on load').toBeGreaterThan(0);

    for (const response of graphqlResponses) {
      expect(response.status, `${response.url} answered ${response.status}`).toBe(200);
    }

    expect(backendHosts.size).toBeGreaterThan(0);

    // Hostname, not host: the API is on a different *port* by design, and only
    // the name has to match for the cookie to be first-party. Only a loopback
    // deployment is held to the rule — a real deployment may legitimately serve
    // the API from another origin, and that origin is configured as an absolute
    // URL that this code leaves alone.
    if (LOOPBACK_HOSTS.has(pageHostname)) {
      for (const host of backendHosts) {
        expect(host.split(':')[0]).toBe(pageHostname);
      }
    }

    // The session cookie has to live on the host the SPA calls, or the next
    // mutation is a 419. Asserted here rather than inferred from the page
    // rendering: a guest shell and a learner shell both render.
    //
    // Matched by suffix because Laravel derives the name from APP_NAME
    // (`Str::slug(...) . '-session'`), so the container and the host spell it
    // differently and a hard-coded name would pin the wrong one.
    const cookies = await page.context().cookies();
    const sessionCookies = cookies.filter((cookie) => cookie.name.endsWith('-session'));

    expect(sessionCookies.length, 'signing in must leave a session cookie').toBeGreaterThan(0);

    if (LOOPBACK_HOSTS.has(pageHostname)) {
      for (const cookie of sessionCookies) {
        expect(cookie.domain.replace(/^\./, '')).toBe(pageHostname);
      }
    }

    tracker.assertClean();
  });
});
