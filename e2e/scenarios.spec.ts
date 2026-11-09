import { test, expect } from '@playwright/test';
import { gotoAsSeededUser, trackPageErrors } from './helpers';

/**
 * The two conversation modes, as the learner meets them (plan §3, §8, §10).
 *
 * These screens are deliberately tested without a microphone: the suite never
 * starts a real LiveKit session, because that needs a live agent worker and an
 * audio stream. What is covered here is the routing and the copy that make the
 * modes distinguishable — the topic picker, the scenario brief, and the lesson
 * screen the roadmap CTA opens. The prompt itself is pinned by
 * `agent/test_prompts.py`, and the real voice session stays a manual check.
 */
test.describe('Scenario practice screens @smoke', () => {
  test('/talk lists 自由に話す first and the catalogue, and never leaks the AI goal or complications', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/talk');
    await expect(page).toHaveURL(/\/talk$/);

    // The picker's own heading, not the tab label: 会話 is the page title.
    await expect(page.getByRole('heading', { level: 1, name: '会話' })).toBeVisible();

    // Free conversation is the first row, before the catalogue: it is a promise
    // of "no script", not one topic among many. Anchors under /talk/ are the
    // picker's rows (the header's 会話 tab points at /talk, without a slash).
    const pickerLinks = page.locator('main a[href^="/talk/"]');
    await expect(pickerLinks.first()).toHaveAttribute('href', '/talk/free_talk');
    await expect(pickerLinks.first()).toContainText('自由に話す');

    // The catalogue renders the scenarios the learner can choose between.
    await expect(page.getByRole('link', { name: /Airport check-in/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /Business meeting/ })).toBeVisible();

    // `ai_goal` and `complications` are the agent's private side of the role-play
    // (plan §7): the API withholds them and the picker must not display them.
    await expect(page.getByText(/before the desk closes/)).toHaveCount(0);
    await expect(page.getByText(/middle seat in the back row/)).toHaveCount(0);

    tracker.assertClean();
  });

  test('the Airport check-in card opens the scenario brief with the mode hint and its useful phrases', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/talk');
    await page.getByRole('link', { name: /Airport check-in/ }).click();

    await expect(page).toHaveURL(/\/talk\/airport_checkin$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Airport check-in' })).toBeVisible();

    // The scene: where we are, who the learner plays, what they want.
    await expect(page.getByText('場面', { exact: true })).toBeVisible();
    await expect(page.getByText('あなたの役', { exact: true })).toBeVisible();
    await expect(page.getByText('あなたの目的', { exact: true })).toBeVisible();

    // The one behavioural difference from a lesson, said in words rather than
    // left for the learner to read as instability.
    await expect(page.getByText('この会話は振り返りに記録されます。', { exact: true })).toBeVisible();

    // The scenario's own phrases are the screen's only support panel.
    await expect(page.getByText('I have a connecting flight to Seoul.', { exact: true })).toBeVisible();

    tracker.assertClean();
  });

  test('free conversation opens in the scenario shell without starting a voice session', async ({ page }) => {
    const tracker = trackPageErrors(page);

    // Watch the mutation the start control would send: the screen may be opened
    // and read, but nothing may join a room until the learner taps start.
    const voiceSessionRequests: string[] = [];
    page.on('request', (request) => {
      const body = request.postData() ?? '';
      if (request.url().includes('/graphql') && body.includes('requestVoiceToken')) {
        voiceSessionRequests.push(body);
      }
    });

    await gotoAsSeededUser(page, '/talk/free_talk');
    await expect(page).toHaveURL(/\/talk\/free_talk$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Free conversation' })).toBeVisible();
    await expect(page.getByText('この会話は振り返りに記録されます。', { exact: true })).toBeVisible();

    // Still idle: the orb offers to start rather than reporting a live session.
    await expect(page.getByText('タップして始めます', { exact: true })).toBeVisible();
    expect(voiceSessionRequests).toEqual([]);

    tracker.assertClean();
  });

  test('the roadmap CTA opens the lesson screen, and /practice/:id redirects to it', async ({ page }) => {
    const tracker = trackPageErrors(page);

    await gotoAsSeededUser(page, '/roadmap');

    const lessonCta = page.getByRole('link', { name: 'このレッスンを話す' });
    await expect(lessonCta).toBeVisible();

    const href = await lessonCta.getAttribute('href');
    expect(href).toMatch(/^\/lesson\/\d+$/);

    const lessonId = href!.split('/').pop()!;
    await lessonCta.click();

    await expect(page).toHaveURL(new RegExp(`/lesson/${lessonId}$`));

    // The lesson names its mode (grammar checked during the conversation) and
    // offers both starts: the orb itself and the primary button. They share the
    // same accessible label by design — the orb is the affordance.
    await expect(page.getByText('文法チェックつき', { exact: true })).toBeVisible();
    const startControls = page.getByRole('button', { name: '会話を始める' });
    await expect(startControls.first()).toBeVisible();
    expect(await startControls.count()).toBe(2);

    // Opening the lesson must not bounce the learner back to the picker or
    // forward into a session: give the router a beat, then check the address again.
    await page.waitForTimeout(500);
    await expect(page).toHaveURL(new RegExp(`/lesson/${lessonId}$`));

    // The old lesson address is still a permanent redirect, so bookmarks work.
    await gotoAsSeededUser(page, `/practice/${lessonId}`);
    await expect(page).toHaveURL(new RegExp(`/lesson/${lessonId}$`));
    await expect(page.getByText('文法チェックつき', { exact: true })).toBeVisible();

    tracker.assertClean();
  });

  test('the learner can draft a custom scenario with AI, review and edit the refined prompt, and confirm it', async ({ page }) => {
    const tracker = trackPageErrors(page);

    const draftedScenario = {
      __typename: 'Scenario',
      code: 'custom_01je2etestscenario',
      title: 'Returning a faulty laptop',
      summary: 'Explain a hardware issue at the service counter and ask for a replacement.',
      level: 'B1',
      register: 'formal',
      setting: 'The service desk of a Tokyo electronics store on Saturday afternoon.',
      aiRole: 'Store service clerk checking return policy.',
      learnerRole: 'Customer whose three-day-old laptop keeps shutting down.',
      learnerGoal: 'Explain the issue clearly and get an immediate replacement.',
      usefulPhrases: [
        'I bought this laptop three days ago, and it keeps shutting down.',
        'I need it for work this week, so a two-week repair will not work.',
        'Could you replace it with the same model today?',
      ],
      isCustom: true,
      userPrompt: '壊れたノートPCを返品・交換する練習',
    };

    let confirmedScenario: typeof draftedScenario | null = null;

    await page.route('**/graphql', async (route) => {
      const body = route.request().postData() ?? '';
      if (body.includes('DraftCustomScenario')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: { draftCustomScenario: draftedScenario } }),
        });
        return;
      }

      if (body.includes('ConfirmCustomScenario')) {
        const parsed = JSON.parse(body);
        const input = parsed.variables?.input ?? {};
        confirmedScenario = {
          ...draftedScenario,
          ...input,
        };
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: { confirmCustomScenario: confirmedScenario } }),
        });
        return;
      }

      if (body.includes('query Scenarios') && confirmedScenario) {
        const response = await route.fetch();
        const json = await response.json();
        const list = json?.data?.scenarios ?? [];
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              scenarios: [...list, confirmedScenario],
            },
          }),
        });
        return;
      }

      await route.continue();
    });

    await gotoAsSeededUser(page, '/talk');

    await page.getByTestId('open-custom-scenario-builder').click();
    await expect(page.getByTestId('custom-scenario-builder')).toBeVisible();

    await page.getByTestId('custom-scenario-prompt-input').fill('壊れたノートPCを返品・交換する練習');
    await page.getByTestId('custom-scenario-register-select').selectOption('formal');
    await page.getByTestId('custom-scenario-draft-submit').click();

    // Step 2: AI-refined prompt is presented for user confirmation and editing.
    await expect(page.getByTestId('custom-scenario-confirm-step')).toBeVisible();
    await expect(page.getByTestId('confirm-title-input')).toHaveValue('Returning a faulty laptop');
    await expect(page.getByTestId('confirm-setting-input')).toHaveValue(draftedScenario.setting);

    // Edit title before confirming and saving to list.
    await page.getByTestId('confirm-title-input').fill('Laptop exchange at Shinjuku Electronics');
    await page.getByTestId('custom-scenario-confirm-save').click();

    await expect(page.getByTestId('custom-scenarios-list')).toBeVisible();
    await expect(page.getByRole('link', { name: /Laptop exchange at Shinjuku Electronics/ })).toBeVisible();

    tracker.assertClean();
  });
});
