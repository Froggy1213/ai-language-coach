import { expect, type Page } from '@playwright/test';

export const SEEDED_USER = {
  email: 'test@example.com',
  password: 'password',
};

/**
 * Signs in the seeded user via the SPA login page and waits until
 * navigation to /roadmap is complete and the header is rendered.
 */
export async function loginAsSeededUser(page: Page): Promise<void> {
  await page.goto('/login');
  await page.locator('input[type="email"]').fill(SEEDED_USER.email);
  await page.locator('input[type="password"]').fill(SEEDED_USER.password);
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/roadmap$/);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}
