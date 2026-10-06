import { test, expect } from '@playwright/test';
import { loginAsSeededUser } from './helpers';

test.describe('Onboarding assessment screen @smoke', () => {
  test('/onboarding renders its consent step with a visible recording affordance', async ({ page }) => {
    // 1. Authenticate and navigate to /onboarding
    await loginAsSeededUser(page);

    await page.goto('/onboarding');
    await expect(page).toHaveURL(/\/onboarding$/);

    // 2. Main title and consent step container
    await expect(page.getByRole('heading', { level: 1, name: 'Проверка уровня' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Прежде чем записывать' })).toBeVisible();

    // 3. Consent checkbox and continue button
    const consentCheckbox = page.getByRole('checkbox', {
      name: 'Согласен на запись и обработку голоса для оценки уровня.',
    });
    await expect(consentCheckbox).toBeVisible();
    expect(await consentCheckbox.isChecked()).toBe(false);

    const continueButton = page.getByRole('button', { name: 'Продолжить' });
    await expect(continueButton).toBeVisible();
    await expect(continueButton).toBeDisabled();

    // 4. Checking the consent checkbox enables continue
    await consentCheckbox.check();
    expect(await consentCheckbox.isChecked()).toBe(true);
    await expect(continueButton).toBeEnabled();

    // 5. Proceed to the ready stage and verify the visible recording affordance
    await continueButton.click();

    const startRecordingButton = page.getByRole('button', { name: 'Начать запись' });
    await expect(startRecordingButton).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Расскажите о себе' })).toBeVisible();
  });
});
