<script setup lang="ts">
import { useMutation } from '@urql/vue'
import {
  COMPLETE_LESSON_CARD_MUTATION,
  FINISH_VOICE_SESSION_MUTATION,
} from '~/graphql/documents'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { common, lesson } from '~/constants/strings'

/**
 * レッスン — the grammar-lesson conversation screen.
 *
 * One task, one screen: talk to the AI tutor about the card's grammar point.
 * There is no transcript, no lesson list and no analytics here, because every
 * one of those competes with the only thing the learner is supposed to be doing.
 * Reference material is available but collapsed, and the review of mistakes
 * happens later on 振り返り.
 *
 * The route names the card (`/lesson/:cardId`) rather than resolving "the lesson
 * in progress": the course tab lights up for it, and 今日 / ロードマップ link
 * here directly. Scenarios live at `/talk` and do not complete a lesson card.
 */
const route = useRoute()

const { roadmap, fetching, error: queryError } = useRoadmap({
  requestPolicy: 'cache-and-network',
})

const cardId = computed(() => String(route.params.cardId ?? ''))

const card = computed(() => {
  if (!roadmap.value) {
    return null
  }

  return roadmap.value.lessonCards.find((candidate) => candidate.id === cardId.value) ?? null
})

const emptyState = computed<{ title: string; lead: string } | null>(() => {
  if (!roadmap.value) {
    return { title: lesson.noCards, lead: lesson.noCardsBody }
  }
  if (!card.value) {
    return { title: lesson.notFound, lead: lesson.notFoundBody }
  }
  if (card.value.status === 'locked') {
    return { title: lesson.notReady, lead: lesson.notReadyBody }
  }
  return null
})

const audioElement = ref<HTMLAudioElement | null>(null)

const {
  status,
  error,
  sessionId,
  agentPresent,
  elapsedSeconds,
  orbState,
  energy,
  start,
  leave,
} = useConversation({ audioElement })

const completeLessonMutation = useMutation(COMPLETE_LESSON_CARD_MUTATION)
const finishVoiceMutation = useMutation(FINISH_VOICE_SESSION_MUTATION)
const finishPending = ref(false)

async function finish(): Promise<void> {
  if (finishPending.value) {
    return
  }

  finishPending.value = true

  try {
    // Tell the backend the session is over before dropping the room. Without
    // this call it only learns about the end from LiveKit's `room_finished`
    // webhook, which arrives minutes later once the room's timeouts expire —
    // until then `/reflect/:id` shows "in progress" and no mistakes. Best
    // effort: if the mutation fails, `leave()` and the ended state below must
    // still happen, and the webhook finishes the session afterwards.
    if (sessionId.value) {
      try {
        await finishVoiceMutation.executeMutation({ id: sessionId.value })
      } catch {
        // Ignored on purpose: see above, the webhook is the backstop.
      }
    }

    await leave()
    if (card.value && card.value.status === 'ready') {
      try {
        await completeLessonMutation.executeMutation({ lessonCardId: card.value.id })
      } catch {
        // Non-fatal: the reflect link is shown regardless.
      }
    }
  } finally {
    finishPending.value = false
  }
}

function begin(): void {
  if (card.value) {
    void start({ lessonCardId: card.value.id })
  }
}

// Leaving the screen must not leave the microphone open.
onBeforeUnmount(() => {
  void leave()
})

const ended = computed(() => status.value === 'ended')
</script>

<template>
  <section class="mx-auto flex max-w-2xl flex-col">
    <p v-if="fetching && !roadmap" class="flex items-center gap-3 py-16 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ lesson.title }}…
    </p>

    <UiNotice
      v-else-if="queryError && !roadmap"
      :message="graphQLErrorMessageFor(queryError, { fallback: lesson.loadError })"
    />

    <div v-else-if="emptyState" class="space-y-5 py-10">
      <PageHeader :title="emptyState.title" :lead="emptyState.lead" />
      <UiButtonLink to="/roadmap" variant="primary" size="lg">
        {{ common.backToRoadmap }}
      </UiButtonLink>
    </div>

    <template v-else-if="card">
      <!-- Lesson identity. Small on purpose: the orb is the subject here. The
           mode pill names what makes this screen different from a scenario. -->
      <header class="mb-6 flex items-start justify-between gap-4">
        <div class="min-w-0 space-y-1">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <p class="label">#{{ card.orderIndex }} · {{ card.grammarPoint.category }}</p>
            <span class="rounded-full bg-[var(--accent-dim)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.08em] text-[var(--accent)]">
              {{ lesson.mode }}
            </span>
          </div>
          <h1 class="truncate text-[1.0625rem] leading-snug font-medium">
            {{ card.grammarPoint.title }}
          </h1>
        </div>

        <NuxtLink
          v-if="!ended"
          to="/roadmap"
          class="shrink-0 rounded-full border border-[var(--line)] px-3 py-1.5 text-[0.8125rem] text-[var(--ink-muted)] transition hover:border-[var(--line-strong)] hover:text-[var(--ink)]"
        >
          {{ lesson.picker }}
        </NuxtLink>
      </header>

      <!-- Once the session ends the stage is replaced by the outcome: leaving a
           dead orb on screen would invite a tap that does nothing. -->
      <div v-if="ended" class="space-y-6 py-6">
        <div class="space-y-3 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6">
          <p class="label">{{ lesson.endedTitle }}</p>
          <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ lesson.endedBody }}</p>
          <div class="flex flex-wrap items-center gap-3 pt-1">
            <UiButtonLink v-if="sessionId" :to="`/reflect/${sessionId}`" variant="primary" size="lg">
              {{ lesson.seeFeedback }}
            </UiButtonLink>
            <UiButton variant="quiet" size="lg" @click="begin">{{ lesson.again }}</UiButton>
          </div>
        </div>

        <CheatSheetPanel :cheat-sheet="card.cheatSheet" />
      </div>

      <template v-else>
        <div class="py-2">
          <ConversationStage
            :state="orbState"
            :energy="energy"
            :status="status"
            :elapsed-seconds="elapsedSeconds"
            :agent-present="agentPresent"
            :error="error"
            @start="begin"
            @finish="finish"
          />
        </div>

        <div class="mt-10 space-y-3">
          <p class="label">{{ lesson.topic }}</p>
          <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ card.practicePrompt }}</p>
        </div>

        <div class="mt-7">
          <CheatSheetPanel :cheat-sheet="card.cheatSheet" />
        </div>
      </template>
    </template>

    <!-- Remote audio is attached to this element by the LiveKit room. -->
    <audio ref="audioElement" class="sr-only" autoplay playsinline />
  </section>
</template>
