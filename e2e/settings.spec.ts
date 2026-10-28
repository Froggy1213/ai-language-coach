import { test, expect } from '@playwright/test';
import { gotoAsSeededUser, trackPageErrors } from './helpers';

/**
 * The settings screen is where a learner sees what was recorded about them, where
 * they sign out, and where they can delete the account, so the smoke test walks it
 * without ever pressing the destructive button: it asserts the consent evidence
 * and the deletion guard are actually on screen, in a real browser, against the
 * real API (plan §5 "Privacy", §7).
 *
 * The sign-out button is asserted but deliberately not clicked here: this spec
 * runs on the session every other spec inherits from `global.setup.ts`, and
 * `logout` invalidates that session server-side. `auth.spec.ts` performs the real
 * sign-out on the session it creates itself.
 */
test.describe('Account settings screen @smoke', () => {
  test('/settings shows the consent record, the sign-out control and the guarded deletion flow', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/settings');

    await expect(page.getByRole('heading', { level: 1, name: '設定' })).toBeVisible();
    await expect(page.getByText('test@example.com')).toBeVisible();

    // The consent record has to be readable: the label plus either the explicit
    // "recorded" marker or the explicit "not recorded" wording — never an empty
    // gap where the answer should be. Exact strings rather than an anchored
    // regex, because a regex is matched against the element's raw text node
    // (whitespace and all) while string matching normalizes it.
    await expect(page.getByText('音声録音の同意', { exact: true })).toBeVisible();
    await expect(page.getByText('記録あり', { exact: true }).or(page.getByText('未記録', { exact: true }))).toBeVisible();

    // Signing out lives here now that the old header link is gone.
    await expect(page.getByRole('heading', { name: 'セッション' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'ログアウト' })).toBeVisible();

    // Deletion exists and is guarded by both a password and a literal
    // confirmation, which is the whole point of the flow. The button stays
    // disabled until both are filled in.
    await expect(page.getByRole('heading', { name: 'アカウントの削除' })).toBeVisible();
    await expect(page.getByPlaceholder('パスワードを入力')).toBeVisible();
    await expect(page.getByPlaceholder('DELETE')).toBeVisible();
    const deleteButton = page.getByRole('button', { name: 'アカウントを削除' });
    await expect(deleteButton).toBeVisible();
    await expect(deleteButton).toBeDisabled();

    tracker.assertClean();
  });
});
