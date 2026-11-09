import { test, expect } from '@playwright/test';
import { gotoAsSeededUser, trackPageErrors } from './helpers';

test.describe('Spaced repetition review screen @smoke', () => {
  test('/review renders without console errors, showing either the due queue or its documented empty state', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/review');

    // 3. Page must not produce uncaught exceptions or console errors
    tracker.assertClean();

    // 4. The screen itself must be there — asserted on its own heading rather
    // than on "something that mentions review", so a blank page cannot pass.
    await expect(page.getByRole('heading', { level: 1, name: '復習' })).toBeVisible();

    // 5. And it must have resolved to one of its two real states: the recall
    // card, or the honest "nothing is due" panel. Which one depends on the
    // seeded learner's data, so both are accepted — but nothing else is.
    const card = page.locator('article').first();
    const emptyState = page.getByRole('heading', { name: '今日の復習はありません' });
    await expect(card.or(emptyState).first()).toBeVisible();

    if (await emptyState.isVisible()) {
      // The empty state has to offer the way out it documents.
      await expect(page.getByRole('link', { name: '会話をしに行く' })).toBeVisible();
    } else {
      // The queue shows one card at a time, and the card is a recall prompt:
      // the learner's own sentence, not a bare list of grammar titles.
      await expect(page.locator('article')).toHaveCount(1);
      await expect(card.getByText('あなたの発話')).toBeVisible();
    }

    // 6. Must not render a generic unhandled 404 page
    await expect(page.getByText('Page not found: /review')).toHaveCount(0);

    // 7. Final assert that no console errors or page errors were produced
    tracker.assertClean();
  });

  test('the learner recalls their own sentence, reveals the correction, and grades the item', async ({ page }) => {
    const tracker = trackPageErrors(page);

    const explanation = 'Measure nouns like kilo take a plural when they follow a number.';

    const dueItem = {
      __typename: 'ReviewItem',
      id: '9001',
      grammarPoint: {
        __typename: 'GrammarPoint',
        id: '21',
        code: 'plurals',
        title: 'Plurals & countable nouns',
        category: 'nouns',
      },
      easeFactor: 2.5,
      intervalDays: 1,
      repetitionNumber: 0,
      nextReviewAt: '2020-01-01T00:00:00Z',
      latestMistake: {
        __typename: 'Mistake',
        userUtterance: 'five kilo of rice',
        correction: 'five kilos of rice',
        explanation,
      },
    };

    let submittedQuality: number | null = null;

    await page.route(/\/graphql(\?|$)/, async (route) => {
      const request = route.request();
      const body = request.postData() ?? '';

      // urql sends queries over GET, with operationName in the query string.
      if (request.url().includes('operationName=DueReviews')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: { dueReviews: [dueItem] } }),
        });
        return;
      }

      if (body.includes('SubmitReviewResult')) {
        const parsed = JSON.parse(body);
        submittedQuality = parsed.variables?.quality ?? null;

        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              submitReviewResult: {
                ...dueItem,
                intervalDays: 6,
                repetitionNumber: 1,
                nextReviewAt: '2030-01-01T00:00:00Z',
                latestMistake: dueItem.latestMistake,
              },
            },
          }),
        });
        return;
      }

      await route.continue();
    });

    await gotoAsSeededUser(page, '/review');

    // Step 1: the learner's own sentence is the prompt, and the answer is not
    // on screen yet.
    const card = page.locator('article').first();
    await expect(card.getByText('Plurals & countable nouns')).toBeVisible();
    await expect(card.getByText('five kilo of rice')).toBeVisible();
    await expect(card.getByText(explanation)).toHaveCount(0);
    await expect(card.getByRole('button', { name: /できた/ })).toHaveCount(0);

    // Step 2: revealing shows the correction and explanation, and only then the
    // grading choices appear.
    await card.getByRole('button', { name: '答えを見る' }).click();
    await expect(card.getByText('five kilos of rice')).toBeVisible();
    await expect(card.getByText(explanation)).toBeVisible();
    await expect(page.getByText('1件 · 1 / 1')).toBeVisible();

    // Step 3: "knew it" maps to SM-2 quality 4 and closes the queue.
    await card.getByRole('button', { name: /できた/ }).click();

    await expect(page.getByRole('heading', { name: '今日の復習は完了' })).toBeVisible();
    await expect(page.getByText('1件を復習しました。')).toBeVisible();
    await expect(page.getByText(/次の復習:/)).toBeVisible();
    expect(submittedQuality).toBe(4);

    tracker.assertClean();
  });
});
