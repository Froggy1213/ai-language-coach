import { test, expect } from '@playwright/test';
import { GREETING_PATTERN, appNav, loginAsSeededUser, signedInShell } from './helpers';

// This spec is about the guest-to-learner flow itself, so it starts signed out
// rather than inheriting the session the setup project saved. That also keeps the
// sign-out at the end of this test from invalidating the session every other spec
// inherits: this test signs in its own session, and `logout` only destroys the
// session that made the request.
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Authentication flow @smoke', () => {
  test('a guest sees the scrollytelling landing page on /, is bounced from learner routes to /login, signs in to the daily home, and signs out from /settings', async ({ page }) => {
    // 0. A guest visiting / sees the scrollytelling landing page
    await page.goto('/');
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('読む英語から、');
    await expect(page.getByRole('tab', { name: /1分間の声診断/ })).toBeVisible();

    // 1. A guest visiting a learner route lands on /login
    await page.goto('/roadmap');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'おかえりなさい' })).toBeVisible();

    // 2. Signing in with the seeded account reaches the daily home screen. This is
    // the redesign's landing page: sign-in used to go to /roadmap.
    await loginAsSeededUser(page);
    await expect(page).toHaveURL('/');
    // The heading is a time-of-day greeting, so only its shape is asserted.
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(GREETING_PATTERN);

    // 3. The signed-in shell is really on screen: the desktop nav offers the four
    // destinations and the account link that opens /settings.
    const nav = appNav(page);
    await expect(nav.getByRole('link', { name: '今日', exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'ロードマップ', exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: '会話', exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: '復習', exact: true })).toBeVisible();
    await expect(signedInShell(page)).toBeVisible();

    // 4. The level-assessment affordance moved out of the old header link and now
    // lives on the home screen's level stat; it still opens the assessment.
    const levelLink = page.getByRole('link', { name: '判定を受け直す' });
    await expect(levelLink).toBeVisible();
    await levelLink.click();
    await expect(page).toHaveURL(/\/level$/);
    await expect(page.getByRole('heading', { level: 1, name: 'レベル判定' })).toBeVisible();

    // 5. Signing out now lives on /settings and returns to /login.
    await page.goto('/settings');
    const signOutButton = page.getByRole('button', { name: 'ログアウト' });
    await expect(signOutButton).toBeVisible();
    await signOutButton.click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'おかえりなさい' })).toBeVisible();
  });
});
