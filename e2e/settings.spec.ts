import { test, expect } from '@playwright/test';
import { gotoAsSeededUser } from './helpers';

/**
 * The settings screen is where a learner sees what was recorded about them and
 * where they can delete the account, so the smoke test walks it without ever
 * pressing the destructive button: it asserts the consent evidence and the
 * deletion guard are actually on screen, in a real browser, against the real
 * API (plan §5 "Privacy", §7).
 */
test.describe('Account settings screen @smoke', () => {
  test('/settings shows the consent record and the guarded deletion flow', async ({ page }) => {
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

    await gotoAsSeededUser(page, '/settings');

    await expect(page.getByRole('heading', { level: 1, name: 'Настройки аккаунта' })).toBeVisible();
    await expect(page.getByText('test@example.com')).toBeVisible();

    // The consent record has to be readable: either a timestamp or the explicit
    // "not recorded yet" wording — never an empty gap where the answer should be.
    await expect(page.getByText(/согласие|не зафиксировано|не записано/i).first()).toBeVisible();

    // Deletion exists and is guarded by both a password and a literal
    // confirmation, which is the whole point of the flow.
    await expect(page.getByRole('heading', { name: 'Удаление аккаунта' })).toBeVisible();
    await expect(page.getByPlaceholder('Введите пароль')).toBeVisible();
    await expect(page.getByPlaceholder('DELETE')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Удалить аккаунт' })).toBeVisible();

    expect(pageErrors).toEqual([]);
    expect(consoleErrors).toEqual([]);
  });
});
