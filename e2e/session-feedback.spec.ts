import { test, expect } from '@playwright/test';
import { loginAsSeededUser } from './helpers';

test.describe('Session feedback screen @smoke', () => {
  test('/session/999999 for a non-existent session renders a clear not-found state (no crash, no infinite spinner)', async ({ page }) => {
    const pageErrors: Error[] = [];
    const consoleErrors: string[] = [];

    page.on('pageerror', (exception) => {
      pageErrors.push(exception);
    });

    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    // 1. Authenticate with seeded user
    await loginAsSeededUser(page);

    // 2. Navigate to non-existent session route
    await page.goto('/session/999999');

    // 3. No uncaught runtime crashes
    expect(pageErrors).toEqual([]);

    // 4. Must not remain stuck in an infinite spinner
    const spinner = page.locator('.animate-spin, [role="progressbar"], [data-testid="loading"]');
    await expect(spinner).toHaveCount(0);

    // 5. Must render a clear application not-found state (not an unhandled page)
    const notFoundMessage = page.getByTestId('session-not-found')
      .or(page.getByRole('heading', { name: /сессия не найдена|не найдено/i }))
      .or(page.getByText(/сессия не найдена|сессия.*не существует|не удалось найти/i));
    await expect(notFoundMessage.first()).toBeVisible();

    // 6. Must not be the unhandled generic 404 page
    await expect(page.getByText('Page not found: /session/999999')).toHaveCount(0);

    // 7. Must not log unhandled errors
    expect(consoleErrors).toEqual([]);
  });
});
