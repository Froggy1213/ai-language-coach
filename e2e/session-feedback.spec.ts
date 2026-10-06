import { test, expect } from '@playwright/test';
import { gotoAsSeededUser } from './helpers';

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

    await gotoAsSeededUser(page, '/session/999999');

    // 3. No uncaught runtime crashes
    expect(pageErrors).toEqual([]);

    // 4. Must not remain stuck in an infinite spinner
    const spinner = page.locator('.animate-spin, [role="progressbar"], [data-testid="loading"]');
    await expect(spinner).toHaveCount(0);

    // 5. Must render the screen's own not-found heading: this route belongs to
    // the learner, so an id that is not theirs has to say so rather than spin.
    await expect(page.getByRole('heading', { level: 1, name: 'Сессия не найдена' })).toBeVisible();

    // 6. Must not be the unhandled generic 404 page
    await expect(page.getByText('Page not found: /session/999999')).toHaveCount(0);

    // 7. Must not log unhandled errors
    expect(consoleErrors).toEqual([]);
  });
});
