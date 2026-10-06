import { test, expect } from '@playwright/test';
import { loginAsSeededUser } from './helpers';

test.describe('Spaced repetition review screen @smoke', () => {
  test('/review renders without console errors, showing either review items or its documented empty state', async ({ page }) => {
    const consoleErrors: string[] = [];
    const pageErrors: Error[] = [];

    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    page.on('pageerror', (exception) => {
      pageErrors.push(exception);
    });

    // 1. Authenticate with seeded user
    await loginAsSeededUser(page);

    // 2. Navigate to /review
    await page.goto('/review');

    // 3. Page must not produce uncaught exceptions or console errors
    expect(pageErrors).toEqual([]);
    expect(consoleErrors).toEqual([]);

    // 4. Must display either review items or the documented empty state
    // (e.g. heading "Повторение", active review items, or empty state text)
    const reviewContent = page.getByTestId('review-items')
      .or(page.getByTestId('review-empty'))
      .or(page.getByTestId('review-card'))
      .or(page.getByRole('heading', { name: /повторен/i }))
      .or(page.getByText(/нет повторений|карточек для повторения|все повторено|очередь повторений/i));
    await expect(reviewContent.first()).toBeVisible();

    // 5. Must not render a generic unhandled 404 page
    await expect(page.getByText('Page not found: /review')).toHaveCount(0);

    // 6. Final assert that no console errors or page errors were produced
    expect(pageErrors).toEqual([]);
    expect(consoleErrors).toEqual([]);
  });
});
