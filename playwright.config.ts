import { defineConfig, devices } from '@playwright/test';

/**
 * The Nuxt dev server binds `localhost` (IPv6 `::1` on macOS), not `127.0.0.1`,
 * and the backend's Sanctum stateful-domain list contains `localhost:3000`. Both
 * the base URL and the browser's cookie jar have to agree on that hostname, or
 * the session cookie is set for one origin and never sent to the other.
 */
const BASE_URL = process.env.E2E_BASE_URL || 'http://localhost:3000';

/**
 * Playwright's own Chromium download is not always present on a developer
 * machine; an installed Chrome is. `E2E_CHANNEL=chrome` opts into it.
 */
const CHANNEL = process.env.E2E_CHANNEL === 'chrome' ? { channel: 'chrome' as const } : {};

/**
 * Accept a certificate the machine does not trust. Needed to point the suite at
 * a real deployment shape: Caddy issues itself a certificate for `localhost`
 * from its own CA, so an otherwise-correct `https://localhost` run fails at the
 * TLS handshake before any application code executes. Staging behind a private
 * CA is the same case. Off unless asked for, so a hostname mismatch in a public
 * deployment is still a failure rather than something silently tolerated.
 */
const IGNORE_HTTPS = process.env.E2E_IGNORE_HTTPS === 'true' ? { ignoreHTTPSErrors: true } : {};

/**
 * See https://playwright.dev/docs/test-configuration.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  timeout: 30_000,
  expect: {
    timeout: 10_000,
  },
  reporter: [
    ['list'],
    ['html', { open: 'never' }],
  ],
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off',
    ...CHANNEL,
    ...IGNORE_HTTPS,
  },
  projects: [
    // One sign-in for the whole run: `login` is throttled at ten attempts per
    // minute on purpose, so a suite where every spec authenticates on its own
    // would hit that guard on the second consecutive run.
    {
      name: 'setup',
      testMatch: /global\.setup\.ts/,
    },
    {
      name: 'chromium',
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        ...CHANNEL,
        storageState: '.auth/user.json',
      },
    },
  ],
});
