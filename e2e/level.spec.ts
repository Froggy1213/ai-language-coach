import { test, expect } from '@playwright/test';
import { gotoAsSeededUser } from './helpers';

/**
 * The CEFR placement screen. The old `/onboarding` address is now a redirect to
 * `/level` (frontend/app/pages/onboarding.vue), and the recording flow — with the
 * same consent gate — lives at `/level`.
 */
test.describe('Level assessment screen @smoke', () => {
  test('/level renders its consent gate and only then the recording affordance', async ({ page }) => {
    await gotoAsSeededUser(page, '/level');
    await expect(page).toHaveURL(/\/level$/);

    // 2. Main title and consent step container
    await expect(page.getByRole('heading', { level: 1, name: 'レベル判定' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: '録音の前に' })).toBeVisible();

    // 3. Consent checkbox and continue button
    const consentCheckbox = page.getByRole('checkbox', {
      name: 'レベル判定のための録音と音声処理に同意します。',
    });
    await expect(consentCheckbox).toBeVisible();
    expect(await consentCheckbox.isChecked()).toBe(false);

    const continueButton = page.getByRole('button', { name: '同意して進む' });
    await expect(continueButton).toBeVisible();
    await expect(continueButton).toBeDisabled();

    // 4. Checking the consent checkbox enables continue
    await consentCheckbox.check();
    expect(await consentCheckbox.isChecked()).toBe(true);
    await expect(continueButton).toBeEnabled();

    // 5. Proceed to the ready stage and verify the visible recording affordance
    await continueButton.click();

    await expect(page.getByRole('heading', { level: 2, name: '自己紹介を話してください' })).toBeVisible();
    const startRecordingButton = page.getByRole('button', { name: '録音を開始' });
    await expect(startRecordingButton).toBeVisible();
  });

  test('/onboarding still redirects to /level', async ({ page }) => {
    await gotoAsSeededUser(page, '/onboarding');

    await expect(page).toHaveURL(/\/level$/);
    await expect(page.getByRole('heading', { level: 1, name: 'レベル判定' })).toBeVisible();
  });
});
