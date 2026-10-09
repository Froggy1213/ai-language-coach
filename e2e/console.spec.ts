import { test, expect } from '@playwright/test';

// This spec is about the guest-to-learner flow itself, so it starts signed out
// rather than inheriting the session the setup project saved.
test.use({ storageState: { cookies: [], origins: [] } });
import { SEEDED_USER, trackPageErrors } from './helpers';

test.describe('Console and network error guards @smoke', () => {
  test('walking login -> roadmap -> onboarding produces no uncaught errors, no console errors, and no failed graphql responses', async ({ page }) => {
    const tracker = trackPageErrors(page);

    // 1. Visit /login and authenticate
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Вход' })).toBeVisible();

    await page.locator('input[type="email"]').fill(SEEDED_USER.email);
    await page.locator('input[type="password"]').fill(SEEDED_USER.password);
    await page.getByRole('button', { name: 'Войти' }).click();

    // 2. Arrive at /roadmap
    await expect(page).toHaveURL(/\/roadmap$/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('article').first()).toBeVisible();

    // 3. Navigate to /onboarding
    const onboardingLink = page.getByRole('link', { name: 'Проверить уровень' });
    await expect(onboardingLink).toBeVisible();
    await onboardingLink.click();

    await expect(page).toHaveURL(/\/onboarding$/);
    await expect(page.getByRole('heading', { name: 'Проверка уровня' })).toBeVisible();
    await expect(page.getByText('Прежде чем записывать')).toBeVisible();

    // 4. Assert clean execution
    tracker.assertClean();
  });
});
