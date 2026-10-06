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

    // 4. The screen itself must be there — asserted on its own heading rather
    // than on "something that mentions review", so a blank page cannot pass.
    await expect(page.getByRole('heading', { level: 1, name: 'Очередь повторений' })).toBeVisible();

    // 5. And it must have resolved to one of its two real states: the queue, or
    // the honest "nothing is due" panel. Which one depends on the seeded
    // learner's data, so both are accepted — but nothing else is.
    const queue = page.getByRole('heading', { name: /К повторению: \d+/ });
    const emptyState = page.getByRole('heading', { name: 'Все карточки повторены!' });
    await expect(queue.or(emptyState).first()).toBeVisible();

    // 6. Must not render a generic unhandled 404 page
    await expect(page.getByText('Page not found: /review')).toHaveCount(0);

    // 7. Final assert that no console errors or page errors were produced
    expect(pageErrors).toEqual([]);
    expect(consoleErrors).toEqual([]);
  });
});
