import { test, expect } from '@playwright/test';
import { trackPageErrors } from './helpers';

test.describe('Pricing screen @smoke', () => {
  test('guests can read the plans and switch to yearly billing', async ({ page }) => {
    const tracker = trackPageErrors(page);

    // `/pricing` is in the guard's public list on purpose: the price is part of
    // the pitch, and a guest must be able to read it before creating an account.
    await page.goto('/pricing');

    await expect(page.getByRole('heading', { level: 1, name: '料金プラン' })).toBeVisible();

    // The three paid tiers and the free allowance, with the monthly prices the
    // API publishes (config/billing.php).
    await expect(page.getByRole('heading', { name: 'Entry' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Standard' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Pro' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Free' })).toBeVisible();
    await expect(page.getByText(/¥799/)).toBeVisible();

    // Yearly billing replaces the monthly figure and shows the per-month
    // equivalent, which is the number the learner actually compares.
    await page.getByRole('radio', { name: '年払い' }).click();
    await expect(page.getByText(/¥7,990/)).toBeVisible();
    await expect(page.getByText(/月あたり ¥666/)).toBeVisible();

    tracker.assertClean();
  });
});
