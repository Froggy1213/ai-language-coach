import { expect, type Page } from '@playwright/test';

export const SEEDED_USER = {
  email: 'test@example.com',
  password: 'password',
};

/** Where the setup project leaves the signed-in session for the other specs. */
export const STORAGE_STATE = '.auth/user.json';

/**
 * Signs in the seeded user via the SPA login page and waits until
 * navigation to /roadmap is complete and the header is rendered.
 *
 * Only the specs that are *about* authentication should call this: the others
 * inherit the session from `global.setup.ts`, because `login` is throttled at
 * ten attempts per minute and a suite that signs in everywhere would trip that
 * guard on a second consecutive run.
 */
export async function loginAsSeededUser(page: Page): Promise<void> {
  await page.goto('/login');
  await page.locator('input[type="email"]').fill(SEEDED_USER.email);
  await page.locator('input[type="password"]').fill(SEEDED_USER.password);
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/roadmap$/);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

/**
 * Opens a page as the learner the setup project signed in, without touching the
 * login form. Fails loudly if the inherited session turned out to be a guest.
 */
export async function gotoAsSeededUser(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await expect(page).not.toHaveURL(/\/login$/);
}
