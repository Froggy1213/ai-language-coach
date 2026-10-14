import { test, expect } from '@playwright/test';

// This spec is about the guest-to-learner flow itself, so it starts signed out
// rather than inheriting the session the setup project saved.
test.use({ storageState: { cookies: [], origins: [] } });
import { GREETING_PATTERN, appNav, loginAsSeededUser, trackPageErrors } from './helpers';

test.describe('Console and network error guards @smoke', () => {
  test('walking login -> roadmap -> level assessment produces no uncaught errors, no console errors, and no failed graphql responses', async ({ page }) => {
    const tracker = trackPageErrors(page);

    // 1. Visit /login and authenticate
    await loginAsSeededUser(page);

    // 2. The daily home screen renders the next lesson to speak
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(GREETING_PATTERN);
    await expect(page.locator('article').first()).toBeVisible();

    // 3. Roadmap
    await appNav(page).getByRole('link', { name: 'ロードマップ', exact: true }).click();
    await expect(page).toHaveURL(/\/roadmap$/);
    await expect(page.getByRole('heading', { level: 1, name: 'ロードマップ' })).toBeVisible();
    await expect(page.locator('ol > li').first()).toBeVisible();

    // 4. Navigate to the level assessment screen — the old /onboarding, which was
    // deleted. Its only entry point from the app shell is the home screen's level
    // stat, so the walk goes back through 今日.
    await appNav(page).getByRole('link', { name: '今日', exact: true }).click();
    await expect(page).toHaveURL('/');
    await page.getByRole('link', { name: '判定を受け直す' }).click();

    await expect(page).toHaveURL(/\/level$/);
    await expect(page.getByRole('heading', { level: 1, name: 'レベル判定' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: '録音の前に' })).toBeVisible();
    await expect(page.getByRole('checkbox')).toBeVisible();

    // 5. Assert clean execution
    tracker.assertClean();
  });
});
