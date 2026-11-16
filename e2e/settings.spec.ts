import { test, expect } from '@playwright/test';
import { gotoAsSeededUser, trackPageErrors } from './helpers';

/**
 * 設定 is a hub with one screen per concern (`/settings/account`,
 * `/settings/billing`, `/settings/appearance`, `/settings/privacy`). This smoke
 * test follows the hub's own links rather than assuming one long scroll, and it
 * still never presses the destructive button: it asserts the consent evidence
 * and the deletion guard are on screen, against the real API (plan §5 "Privacy",
 * §7).
 *
 * The sign-out button is asserted but deliberately not clicked here: this spec
 * runs on the session every other spec inherits from `global.setup.ts`, and
 * `logout` invalidates that session server-side. `auth.spec.ts` performs the real
 * sign-out on the session it creates itself.
 */
test.describe('Account settings @smoke', () => {
  test('the hub opens the profile, plan, appearance, consent and deletion screens and keeps the sign-out control', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/settings');

    await expect(page.getByRole('heading', { level: 1, name: '設定' })).toBeVisible();
    await expect(page.getByText('test@example.com')).toBeVisible();

    // Profile editing lives on its own screen, reached from the identity card.
    await page.getByRole('link', { name: 'プロフィールを編集', exact: true }).click();
    await expect(page).toHaveURL(/\/settings\/account$/);
    await expect(page.getByRole('heading', { level: 1, name: 'アカウント' })).toBeVisible();

    const profileForm = page.getByTestId('settings-profile-form');
    await expect(profileForm).toBeVisible();
    const nameInput = page.getByTestId('settings-profile-name');
    const saveProfileButton = page.getByTestId('settings-profile-submit');
    const originalName = await nameInput.inputValue();
    const updatedName = originalName === 'Kirie Test Learner' ? 'Kirie Alt Learner' : 'Kirie Test Learner';

    await nameInput.fill(updatedName);
    await saveProfileButton.click();
    await expect(profileForm.getByText('プロフィールを更新しました。')).toBeVisible();
    await expect(nameInput).toHaveValue(updatedName);

    await nameInput.fill(originalName);
    await saveProfileButton.click();
    await expect(nameInput).toHaveValue(originalName);
    await expect(saveProfileButton).toBeDisabled();

    await expect(page.getByRole('heading', { name: 'パスワード' })).toBeVisible();
    await expect(page.getByTestId('settings-password-form')).toBeVisible();

    // The plan screen carries the allowance and the Stripe entry points, and it
    // is the one that consumes Checkout's return query.
    await page.getByRole('link', { name: '設定に戻る', exact: true }).click();
    await page.getByRole('link', { name: /プランと利用状況/ }).click();
    await expect(page).toHaveURL(/\/settings\/billing$/);
    await expect(page.getByRole('heading', { level: 1, name: 'プランと利用状況' })).toBeVisible();
    await expect(page.getByText('残り', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'プランを変更' })).toBeVisible();

    await page.goto('/settings/billing?checkout=success');
    await expect(page.getByText('お支払いが完了しました。反映まで少し時間がかかることがあります。')).toBeVisible();
    await expect(page).toHaveURL(/\/settings\/billing$/);

    // Appearance is a real choice, not a decorative segmented control: the
    // radio state has to follow the click.
    await page.getByRole('link', { name: '設定に戻る', exact: true }).click();
    await page.getByRole('link', { name: /テーマ/ }).click();
    await expect(page).toHaveURL(/\/settings\/appearance$/);
    await expect(page.getByRole('heading', { level: 1, name: '表示' })).toBeVisible();

    const darkRadio = page.getByRole('radio', { name: /ダーク/ });
    await expect(page.getByRole('radio')).toHaveCount(2);
    await page.getByText('ダーク', { exact: true }).click();
    await expect(darkRadio).toBeChecked();

    // The data screen owns the consent record and the guarded deletion flow.
    await page.getByRole('link', { name: '設定に戻る', exact: true }).click();
    await page.getByRole('link', { name: /音声録音の同意/ }).click();
    await expect(page).toHaveURL(/\/settings\/privacy$/);
    await expect(page.getByRole('heading', { level: 1, name: 'データとプライバシー' })).toBeVisible();

    // The consent record has to be readable: the label plus either the explicit
    // "recorded" marker or the explicit "not recorded" wording — never an empty
    // gap where the answer should be.
    await expect(page.getByRole('heading', { name: '音声録音の同意' })).toBeVisible();
    await expect(page.getByText('記録あり', { exact: true }).or(page.getByText('未記録', { exact: true }))).toBeVisible();

    // Deletion exists and is guarded by both a password and a literal
    // confirmation, which is the whole point of the flow. The button stays
    // disabled until both are filled in.
    await expect(page.getByRole('heading', { name: 'アカウントの削除' })).toBeVisible();
    await expect(page.getByPlaceholder('パスワードを入力', { exact: true })).toBeVisible();
    await expect(page.getByPlaceholder('DELETE')).toBeVisible();
    const deleteButton = page.getByRole('button', { name: 'アカウントを削除' });
    await expect(deleteButton).toBeVisible();
    await expect(deleteButton).toBeDisabled();

    // Signing out stayed on the hub now that the rest moved to sub-screens.
    await page.getByRole('link', { name: '設定に戻る', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'セッション' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'ログアウト' })).toBeVisible();

    tracker.assertClean();
  });
});
