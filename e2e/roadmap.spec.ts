import { test, expect } from '@playwright/test';
import { gotoAsSeededUser } from './helpers';

test.describe('Roadmap screen @smoke', () => {
  test('the browser Roadmap GraphQL request answers 200 and lesson cards render', async ({ page }) => {
    // 1. Set up listener for the browser's Roadmap GraphQL network response.
    // urql sends queries via GET by default (with query param operationName=Roadmap).
    const roadmapResponsePromise = page.waitForResponse((response) => {
      const url = response.url();
      if (!url.includes('/graphql')) {
        return false;
      }
      return url.includes('Roadmap') || (response.request().postData()?.includes('Roadmap') ?? false);
    });

    // 2. Open the roadmap with the session the setup project saved. This spec is
    // not about authentication — the login flow has its own specs — and an
    // extra sign-in here would count against the ten-per-minute login limit.
    await gotoAsSeededUser(page, '/roadmap');

    // 3. Assert on the response status and payload (README decision 20 defect class guard)
    const roadmapResponse = await roadmapResponsePromise;
    expect(roadmapResponse.status()).toBe(200);

    const json = await roadmapResponse.json();
    expect(json.errors).toBeUndefined();
    expect(json.data?.roadmap).toBeDefined();
    expect(json.data.roadmap.title).toBe('English · A1–B1');
    expect(Array.isArray(json.data.roadmap.lessonCards)).toBe(true);
    expect(json.data.roadmap.lessonCards.length).toBeGreaterThan(0);

    // 4. Assert on rendered DOM
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('English · A1–B1');

    // Lesson cards render as <article> components (LessonCardItem.vue)
    const cardArticles = page.locator('article');
    await expect(cardArticles.first()).toBeVisible();
    const count = await cardArticles.count();
    expect(count).toBe(json.data.roadmap.lessonCards.length);

    // Verify first lesson card content
    await expect(cardArticles.first().getByText(/Урок 1\b/)).toBeVisible();
    await expect(cardArticles.first().getByRole('heading', { level: 2 })).toHaveText('Present Simple');
  });
});
