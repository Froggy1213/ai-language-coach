# E2E — local laws

Playwright smoke suite against a live stack. Reference: [`README.md`](README.md); product context:
[`../AGENTS.md`](../AGENTS.md).

## Laws

1. **It needs a running stack.** `docker compose --profile full up -d` with healthy containers, or
   `E2E_BASE_URL` pointed at one. The suite exists to catch full-stack defects that mocked suites
   cannot see, so running it against stubs defeats its purpose.
2. **`E2E_CHANNEL=chrome` is mandatory on this machine.** Playwright's own Chromium is not
   installed; without the flag the run dies at browser launch, not in the application.
3. **`workers: 1`, `fullyParallel: false`** — the specs share one seeded user, so parallelism buys
   database session contention instead of speed.
4. **`helpers.ts` tolerates exactly one console message** (Echo's failed dial when Reverb is down).
   Do not widen that filter to silence a new error.
5. **The tag is what selects a spec.** `@smoke` sits in the `test.describe` title; `npm run e2e:smoke`
   is `playwright test --grep @smoke`. A new spec without the tag never runs in the smoke job.
6. **Live voice is out of scope here.** Vendor calls (Deepgram, DeepSeek, Cartesia) and real WebRTC
   sessions are exercised elsewhere, deliberately.

## Running

`E2E_CHANNEL=chrome npm run e2e:smoke` (whole suite: `npm run e2e`).
