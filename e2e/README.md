# End-to-End (E2E) Smoke Test Suite

This suite provides real-browser smoke tests for the **AI Language Coach** platform using [@playwright/test](https://playwright.dev/).

It guards against full-stack integration defects that cannot be surfaced by backend unit/feature tests or mocked HTTP suites (e.g. README architectural decisions 20, 21, 26, 27, 34, 35: urql Vue injection issues, CORS channel-authorization omissions, Pusher/Echo envelope mismatches, and containerized environment variable drops).

---

## Architecture & Browser Requirements

- **Test Runner**: Playwright (`@playwright/test`).
- **Browser Execution**: Configured for Chromium (`workers: 1`, `fullyParallel: false` to avoid database session contention on the shared seeded user).
- **Cached Engine**: The `@playwright/test` version matches the cached Chromium revision (`chromium-1243` at `~/Library/Caches/ms-playwright`), avoiding any browser binary download. Google Chrome (`/Applications/Google Chrome.app`) can also be targeted via `channel: 'chrome'` if desired.

---

## Running Against Local Stack

### 1. Fully Containerized Stack (`docker compose --profile full`)

Ensure all 9 containers are running and healthy:
```bash
docker compose --profile full up -d
docker ps
```

Verify services:
- **Nuxt SPA**: http://127.0.0.1:3000
- **Laravel API**: http://127.0.0.1:8000
- **Reverb (WebSocket)**: 127.0.0.1:8081
- **MinIO**: 127.0.0.1:9000 / 9001
- **LiveKit Server**: 127.0.0.1:7880
- **MySQL 8.0**: 127.0.0.1:3306
- **Redis 7**: 127.0.0.1:6379

Run the tests:
```bash
# Run all E2E specs
npm run e2e

# Run only @smoke tagged specs
npm run e2e:smoke

# Run with headed browser for interactive inspection
npm run e2e:headed

# Open HTML test report
npm run e2e:report
```

### 2. Hybrid Host Setup

If running infrastructure services in Docker and application runtimes on the host:
```bash
# Start background services
docker compose up -d mysql redis minio livekit voice-agent

# Backend runtime (in backend/)
php artisan serve
php artisan queue:work
php artisan reverb:start

# Frontend runtime (in frontend/)
npm run dev
```

From repository root, run:
```bash
npm run e2e:smoke
```

---

## Environment Overrides

The suite supports environment overrides via standard environment variables:

| Variable | Default | Purpose |
| :--- | :--- | :--- |
| `E2E_BASE_URL` | `http://127.0.0.1:3000` | Nuxt SPA frontend URL. **Crucial**: Always use `127.0.0.1:3000` instead of `localhost:3000` to maintain Laravel Sanctum cookie stateful domain alignment. |
| `E2E_API_URL` | `http://127.0.0.1:8000` | Laravel GraphQL / REST API URL. |
| `CI` | `undefined` | When set to `true`, enables retry on failure (`retries: 1`) and forbids `.only`. |

Example:
```bash
E2E_BASE_URL=http://127.0.0.1:3000 npm run e2e:smoke
```

---

## How the suite authenticates (and why not in every spec)

`e2e/global.setup.ts` is a Playwright **setup project**: it signs the seeded
learner in once, saves the session to `.auth/user.json`, and the `chromium`
project reuses it (`storageState`). Only the specs that are *about* the flow —
`auth.spec.ts` (guest redirect, sign-in, sign-out) and `console.spec.ts` (the
console/network guard through the login journey) — start from a clean context
and sign in themselves with `test.use({ storageState: { cookies: [], origins: [] } })`.

This is not just speed. `login` is throttled at **ten attempts per minute** on
purpose (plan §7), so a suite where all specs authenticated on their own would
trip that guard on a second consecutive run and report a rate limit as an
application failure. A run performs three sign-ins, so back-to-back runs stay
well inside the limit. If you add a spec, use `gotoAsSeededUser(page, path)`
unless you are specifically testing authentication.

## Seeded Test Account

The tests rely on the pre-seeded account:
- **Email**: `test@example.com`
- **Password**: `password`

Helper function `loginAsSeededUser(page)` in `e2e/helpers.ts` provides a consistent login flow across tests.

---

## Rebuilding the stack before running

The suite drives the **running** stack, and the `--profile full` images bake the
application code in — there are no bind mounts. After changing backend or
frontend code you must rebuild the image, not just restart the container:

```bash
DOCKER_BUILDKIT=0 docker compose build web api horizon reverb voice-agent
docker compose up -d
```

Forgetting this is exactly how a suite can look broken when the application is
fine: a stale API image served a GraphQL schema without the `User.email` field
the new SPA asked for, so every sign-in landed back on `/login` with
`Cannot query field "email" on type "User"`. The classic builder
(`DOCKER_BUILDKIT=0`) is used here because buildx needs to write outside the
workspace.

## Deliberate Non-Coverage

To keep the smoke suite fast, deterministic, and runnable without third-party vendor accounts:
1. **Physical Microphone / Audio Hardware**: Browser media permissions are evaluated through UI affordances and consent states rather than piping simulated audio hardware.
2. **Third-Party AI Vendor Calls**: External live pipelines (Deepgram STT transcription, DeepSeek LLM reasoning, Cartesia TTS voice generation) are deliberately not invoked in smoke runs to prevent billing and external network instability.
3. **Seeded Live Voice Session**: Live WebRTC voice sessions with real agent dispatch (`/practice/{cardId}`) require a live agent worker and audio stream lifecycle, which are exercised in dedicated voice integration harnesses rather than fast UI smoke tests.

---

## How to Add a Spec

1. Create a new spec file in `e2e/<feature>.spec.ts`.
2. Add `@smoke` to the test suite or test description:
   ```ts
   import { test, expect } from '@playwright/test';
   import { loginAsSeededUser } from './helpers';

   test.describe('My Feature @smoke', () => {
     test('verifies key interaction', async ({ page }) => {
       await loginAsSeededUser(page);
       // ... assertions
     });
   });
   ```
3. Ensure the test is independent and leaves no corrupted state for subsequent tests.
