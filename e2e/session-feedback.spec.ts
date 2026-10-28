import { test, expect } from '@playwright/test';
import { gotoAsSeededUser, trackPageErrors } from './helpers';

const SPINNER = '.animate-spin, [role="progressbar"], [data-testid="loading"]';

test.describe('Session feedback screen @smoke', () => {
  test('/reflect/999999 for a non-existent session renders a clear not-found state (no crash, no infinite spinner)', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/reflect/999999');
    await expect(page).toHaveURL(/\/reflect\/999999$/);

    // 3. No uncaught runtime crashes
    tracker.assertClean();

    // 4. Must not remain stuck in an infinite spinner
    await expect(page.locator(SPINNER)).toHaveCount(0);

    // 5. Must render the screen's own not-found heading: this route belongs to
    // the learner, so an id that is not theirs has to say so rather than spin.
    await expect(page.getByRole('heading', { level: 1, name: 'セッションが見つかりません' })).toBeVisible();

    // 6. Must not be the unhandled generic 404 page
    await expect(page.getByText('Page not found: /reflect/999999')).toHaveCount(0);

    // 7. Must not log unhandled errors
    tracker.assertClean();
  });

  // The breakdown moved from /session/:id to /reflect/:id; the old address is kept
  // as a redirect, and that redirect is part of the behaviour worth guarding.
  test('/session/999999 still redirects to /reflect/999999 and renders the same not-found state', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/session/999999');
    await expect(page).toHaveURL(/\/reflect\/999999$/);

    tracker.assertClean();
    await expect(page.locator(SPINNER)).toHaveCount(0);
    await expect(page.getByRole('heading', { level: 1, name: 'セッションが見つかりません' })).toBeVisible();
    await expect(page.getByText('Page not found: /session/999999')).toHaveCount(0);
    tracker.assertClean();
  });
});
