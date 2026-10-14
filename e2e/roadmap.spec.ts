import { test, expect } from '@playwright/test';
import { gotoAsSeededUser } from './helpers';

test.describe('Roadmap screen @smoke', () => {
  test('the browser Roadmap GraphQL request answers 200 and the lesson rail renders', async ({ page }) => {
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
    await expect(page).toHaveURL(/\/roadmap$/);

    // 3. Assert on the response status and payload (README decision 20 defect class guard)
    const roadmapResponse = await roadmapResponsePromise;
    expect(roadmapResponse.status()).toBe(200);

    const json = await roadmapResponse.json();
    expect(json.errors).toBeUndefined();
    expect(json.data?.roadmap).toBeDefined();
    expect(json.data.roadmap.title).toBe('English · A1–B1');
    expect(Array.isArray(json.data.roadmap.lessonCards)).toBe(true);
    expect(json.data.roadmap.lessonCards.length).toBeGreaterThan(0);

    // The seeded learner's path starts at Present Simple (A1, tenses).
    const firstCard = json.data.roadmap.lessonCards[0];
    expect(firstCard.grammarPoint.title).toBe('Present Simple');
    expect(firstCard.orderIndex).toBe(1);

    // 4. Assert on rendered DOM. The page title is the app's own copy now:
    // `roadmap.title` is generated server-side in the learner's old interface
    // language and is deliberately not rendered (frontend/app/pages/roadmap.vue).
    await expect(page.getByRole('heading', { level: 1, name: 'ロードマップ' })).toBeVisible();

    // Lesson cards render as the rail's <li> nodes (RoadmapLesson.vue), one per
    // card in the payload.
    const lessons = page.locator('ol > li');
    await expect(lessons.first()).toBeVisible();
    expect(await lessons.count()).toBe(json.data.roadmap.lessonCards.length);
    await expect(page.getByText(`${json.data.roadmap.lessonCards.length}レッスン`)).toBeVisible();

    // Verify first lesson content: the rail node is numbered by the API's
    // orderIndex, and the row is headed by the grammar point it names.
    const firstLesson = lessons.first();
    await expect(firstLesson.locator('span.tnum')).toHaveText(String(firstCard.orderIndex));
    await expect(firstLesson.getByRole('heading', { level: 2 })).toHaveText(firstCard.grammarPoint.title);
  });
});
