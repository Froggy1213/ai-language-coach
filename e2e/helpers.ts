import { expect, type Locator, type Page } from '@playwright/test';

export interface PageErrorTracker {
  pageErrors: Error[];
  consoleErrors: string[];
  failedGraphqlResponses: { url: string; status: number; statusText: string }[];
  /** Console errors the tracker deliberately tolerated; see IGNORED_EXTERNAL_CONSOLE_ERRORS. */
  ignoredConsoleErrors: string[];
  assertClean: () => void;
}

/**
 * The one console message this guard tolerates, and it is not application output.
 *
 * `pusher-js` opens its Reverb socket while the Echo plugin boots, so the browser
 * logs a failed WebSocket transport on *every* page load when nothing is listening
 * on the configured Reverb port (8080 by default, and Reverb is not part of the
 * stack this suite runs against). The message is emitted by the browser's network
 * stack, not by app code, and it says nothing about the screens under test.
 *
 * The pattern is deliberately exact — host, port, and the `/app/` Pusher path —
 * so that any other console error, including a Reverb failure at a different
 * address or a genuine application error, still fails the run.
 */
const IGNORED_EXTERNAL_CONSOLE_ERRORS = [
  /^WebSocket connection to 'wss?:\/\/(?:localhost|127\.0\.0\.1):8080\/app\//,
];

/**
 * Registers listeners for uncaught page errors, console errors, and failed
 * GraphQL responses (status >= 400), exposing the captured arrays and an
 * assertClean() helper to assert that all three remain empty.
 */
export function trackPageErrors(page: Page): PageErrorTracker {
  const pageErrors: Error[] = [];
  const consoleErrors: string[] = [];
  const ignoredConsoleErrors: string[] = [];
  const failedGraphqlResponses: { url: string; status: number; statusText: string }[] = [];

  // Capture uncaught exceptions
  page.on('pageerror', (exception) => {
    pageErrors.push(exception);
  });

  // Capture console errors, minus the tolerated external transport failure above
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const text = msg.text();

      if (IGNORED_EXTERNAL_CONSOLE_ERRORS.some((pattern) => pattern.test(text))) {
        ignoredConsoleErrors.push(text);
        return;
      }

      consoleErrors.push(text);
    }
  });

  // Guard against any >= 400 GraphQL response (catches Decision 21/34 defects)
  page.on('response', (response) => {
    if (response.url().includes('/graphql') && response.status() >= 400) {
      failedGraphqlResponses.push({
        url: response.url(),
        status: response.status(),
        statusText: response.statusText(),
      });
    }
  });

  return {
    pageErrors,
    consoleErrors,
    failedGraphqlResponses,
    ignoredConsoleErrors,
    assertClean: () => {
      expect(pageErrors).toEqual([]);
      expect(consoleErrors).toEqual([]);
      expect(failedGraphqlResponses).toEqual([]);
    },
  };
}

export const SEEDED_USER = {
  email: 'test@example.com',
  password: 'password',
};

/** Where the setup project leaves the signed-in session for the other specs. */
export const STORAGE_STATE = '.auth/user.json';

/**
 * The 今日 home screen greets by time of day (`greetingFor()` in
 * `frontend/app/utils/format.ts`), so the exact heading depends on when the suite
 * runs; only its shape can be asserted.
 */
export const GREETING_PATTERN = /^(おはようございます|こんにちは|こんばんは)/;

/** The desktop application header's navigation (`frontend/app/app.vue`). */
export function appNav(page: Page): Locator {
  return page.locator('header nav');
}

/**
 * The account link in the desktop application header (rendered `hidden md:block`,
 * which the Desktop Chrome viewport in `playwright.config.ts` satisfies). It
 * renders only for a signed-in learner, so its absence means the session under
 * test is a guest — which is what makes it a real bounce detector rather than a
 * URL comparison.
 */
export function signedInShell(page: Page): Locator {
  return page.locator('header a[href="/settings"]');
}

/**
 * Signs in the seeded user through the SPA login form and waits until the daily
 * home screen has rendered.
 *
 * Sign-in lands on `/` (the 今日 screen) since the redesign; `/roadmap` is no
 * longer the landing page. Only the specs that are *about* authentication should
 * call this: the others inherit the session from `global.setup.ts`, because
 * `login` is throttled at ten attempts per minute and a suite that signs in
 * everywhere would trip that guard on a second consecutive run.
 */
export async function loginAsSeededUser(page: Page): Promise<void> {
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: 'おかえりなさい' })).toBeVisible();

  await page.locator('input[type="email"]').fill(SEEDED_USER.email);
  await page.locator('input[type="password"]').fill(SEEDED_USER.password);
  await page.getByRole('button', { name: 'ログイン' }).click();

  await expect(page).toHaveURL('/');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(GREETING_PATTERN);
  await expect(signedInShell(page)).toBeVisible();
}

/**
 * Opens a page as the learner the setup project signed in, without touching the
 * login form. Fails loudly if the inherited session turned out to be a guest.
 *
 * A guest bounce from a learner route lands on `/login`, so the URL is checked
 * first for a fast, readable failure. The URL alone is not the real guard though:
 * `not.toHaveURL(/\/login$/)` also passes for a page that bounced somewhere else
 * (or for one that rendered the sign-in form at its own address), so the signed-in
 * application shell has to be on screen as well.
 */
export async function gotoAsSeededUser(page: Page, path: string): Promise<void> {
  await page.goto(path);

  await expect(page).not.toHaveURL(/\/login(\?|$)/);
  await expect(signedInShell(page)).toBeVisible();
}
