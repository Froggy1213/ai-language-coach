import { test, expect } from '@playwright/test';
import { gotoAsSeededUser, trackPageErrors } from './helpers';

test.describe('Spaced repetition review screen @smoke', () => {
  test('/review renders without console errors, showing either the due queue or its documented empty state', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/review');

    // 3. Page must not produce uncaught exceptions or console errors
    expect(tracker.pageErrors).toEqual([]);
    expect(tracker.consoleErrors).toEqual([]);

    // 4. The screen itself must be there — asserted on its own heading rather
    // than on "something that mentions review", so a blank page cannot pass.
    await expect(page.getByRole('heading', { level: 1, name: '復習' })).toBeVisible();

    // 5. And it must have resolved to one of its two real states: the queue, or
    // the honest "nothing is due" panel. Which one depends on the seeded
    // learner's data, so both are accepted — but nothing else is.
    // The count line is matched without anchors on purpose: `getByText` matches a
    // regex against the element's raw text node, which keeps its indentation.
    const dueCount = page.getByText(/\d+ 件/).first();
    const queue = page.locator('article').first();
    const emptyState = page.getByRole('heading', { name: '今日の復習はありません' });
    await expect(queue.or(emptyState).first()).toBeVisible();
    await expect(dueCount.or(emptyState)).toBeVisible();

    if (await emptyState.isVisible()) {
      // The empty state has to offer the way out it documents.
      await expect(page.getByRole('link', { name: '会話をしに行く' })).toBeVisible();
    } else {
      // A queue that claims N items has to render N items, each with the grading
      // scale at the bottom of its card.
      const claimed = Number((await dueCount.textContent())?.replace(/\D/g, ''));
      expect(claimed).toBeGreaterThan(0);
      await expect(page.locator('article')).toHaveCount(claimed);
      await expect(page.locator('article').first().getByText('このルールをどれくらい覚えていましたか？')).toBeVisible();
    }

    // 6. Must not render a generic unhandled 404 page
    await expect(page.getByText('Page not found: /review')).toHaveCount(0);

    // 7. Final assert that no console errors or page errors were produced
    expect(tracker.pageErrors).toEqual([]);
    expect(tracker.consoleErrors).toEqual([]);
  });
});
