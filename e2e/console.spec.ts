import { test, expect } from '@playwright/test';
import { SEEDED_USER } from './helpers';

test.describe('Console and network error guards @smoke', () => {
  test('walking login -> roadmap -> onboarding produces no uncaught errors, no console errors, and no failed graphql responses', async ({ page }) => {
    const pageErrors: Error[] = [];
    const consoleErrors: string[] = [];
    const failedGraphqlResponses: { url: string; status: number; statusText: string }[] = [];

    // Capture uncaught exceptions
    page.on('pageerror', (exception) => {
      pageErrors.push(exception);
    });

    // Capture console errors
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
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
    expect(pageErrors).toEqual([]);
    expect(consoleErrors).toEqual([]);
    expect(failedGraphqlResponses).toEqual([]);
  });
});
