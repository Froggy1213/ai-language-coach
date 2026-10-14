import { test as setup } from '@playwright/test';
import { loginAsSeededUser, STORAGE_STATE } from './helpers';

/**
 * Signs the seeded learner in once and saves the session for the specs that are
 * not about authentication.
 *
 * Not only for speed: `login` is throttled at ten attempts per minute on
 * purpose, and a suite where every spec signs in on its own would trip that
 * guard on the second consecutive run and blame the application for it.
 *
 * `loginAsSeededUser` owns the assertions for "a real sign-in happened" (the
 * login form, the redirect to the 今日 home screen, the greeting heading and the
 * signed-in header), so the definition of a good session lives in one place.
 */
setup('authenticate the seeded learner once', async ({ page }) => {
  await loginAsSeededUser(page);

  await page.context().storageState({ path: STORAGE_STATE });
});
