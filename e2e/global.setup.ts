import { test as setup, expect } from '@playwright/test';
import { SEEDED_USER, STORAGE_STATE } from './helpers';

/**
 * Signs the seeded learner in once and saves the session for the specs that are
 * not about authentication.
 *
 * Not only for speed: `login` is throttled at ten attempts per minute on
 * purpose, and a suite where every spec signs in on its own would trip that
 * guard on the second consecutive run and blame the application for it.
 */
setup('authenticate the seeded learner once', async ({ page }) => {
  await page.goto('/login');
  await page.locator('input[type="email"]').fill(SEEDED_USER.email);
  await page.locator('input[type="password"]').fill(SEEDED_USER.password);
  await page.getByRole('button', { name: 'Войти' }).click();

  await expect(page).toHaveURL(/\/roadmap$/);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  await page.context().storageState({ path: STORAGE_STATE });
});
