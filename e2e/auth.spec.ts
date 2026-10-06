import { test, expect } from '@playwright/test';

test.describe('Authentication flow @smoke', () => {
  test('guest visiting /roadmap is redirected to /login, signs in, and signs out', async ({ page }) => {
    // 1. A guest visiting /roadmap lands on /login
    await page.goto('/roadmap');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Вход' })).toBeVisible();

    // 2. Signing in with the seeded account reaches /roadmap
    await page.locator('input[type="email"]').fill('test@example.com');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: 'Войти' }).click();

    await expect(page).toHaveURL(/\/roadmap$/);

    // 3. Roadmap renders the roadmap title and the «Проверить уровень» affordance
    // Roadmap title for seeded user is "English · A1–B1"
    const heading = page.getByRole('heading', { level: 1 });
    await expect(heading).toBeVisible();
    await expect(heading).toHaveText('English · A1–B1');

    // Affordance in header nav: "Проверить уровень"
    const levelCheckLink = page.getByRole('link', { name: 'Проверить уровень' });
    await expect(levelCheckLink).toBeVisible();

    // 4. Signing out returns to /login
    const signOutButton = page.getByRole('button', { name: 'Выйти' });
    await expect(signOutButton).toBeVisible();
    await signOutButton.click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Вход' })).toBeVisible();
  });
});
