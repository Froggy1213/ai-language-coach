<script setup lang="ts">
import { useMutation } from '@urql/vue'
import { COMPLETE_LESSON_CARD_MUTATION } from '~/graphql/documents'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { common, talk } from '~/constants/strings'

/**
 * 会話 — the conversation screen.
 *
 * One task, one screen: talk to the AI tutor. There is no transcript, no lesson
 * list and no analytics here, because every one of those competes with the only
 * thing the learner is supposed to be doing. Reference material is available but
 * collapsed, and the review of mistakes happens later on 振り返り.
 *
 * `/talk` resolves "the lesson in progress" and forwards to `/talk/:id`, so the
 * URL always names what is actually being practised.
 */
const route = useRoute()

const { roadmap, readyCard, fetching, error: queryError } = useRoadmap({
  requestPolicy: 'cache-and-network',
})

const requestedId = computed(() => (route.params.id ? String(route.params.id) : ''))

const card = computed(() => {
  if (!roadmap.value) {
    return null
  }

  if (requestedId.value) {
    return roadmap.value.lessonCards.find((candidate) => candidate.id === requestedId.value) ?? null
  }

  return readyCard.value
})

const emptyState = computed<{ title: string; lead: string } | null>(() => {
  if (roadmap.value && !readyCard.value && !requestedId.value) {
    return { title: talk.noCards, lead: talk.noCardsBody }
  }
  if (!roadmap.value) {
    return { title: talk.noCards, lead: talk.noCardsBody }
  }
  if (!card.value) {
    return { title: talk.notFound, lead: talk.notFoundBody }
  }
  if (card.value.status === 'locked') {
    return { title: talk.notReady, lead: talk.notReadyBody }
  }
  return null
})

// `/talk` on its own is a redirect, not a screen.
watchEffect(() => {
  if (!requestedId.value && readyCard.value) {
    void navigateTo(`/talk/${readyCard.value.id}`, { replace: true })
  }
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
const finishPending = ref(false)

async function finish(): Promise<void> {
  finishPending.value = true

  try {
    await leave()
    if (card.value && card.value.status === 'ready') {
      try {
        await completeLessonMutation.executeMutation({ lessonCardId: card.value.id })
      } catch {
        // Non-fatal: navigation to reflect/feedback proceeds regardless
      }
    }
  } finally {
    finishPending.value = false
  }
}

function begin(): void {
  if (card.value) {
    void start(card.value.id)
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
      {{ talk.title }}…
    </p>

    <UiNotice
      v-else-if="queryError && !roadmap"
      :message="graphQLErrorMessageFor(queryError, { fallback: talk.loadError })"
    />

    <div v-else-if="emptyState" class="space-y-5 py-10">
      <PageHeader :title="emptyState.title" :lead="emptyState.lead" />
      <UiButtonLink to="/roadmap" variant="primary" size="lg">
        {{ common.backToRoadmap }}
      </UiButtonLink>
    </div>

    <template v-else-if="card">
      <!-- Lesson identity. Small on purpose: the orb is the subject here. -->
      <header class="mb-6 flex items-start justify-between gap-4">
        <div class="min-w-0 space-y-0.5">
          <p class="label">#{{ card.orderIndex }} · {{ card.grammarPoint.category }}</p>
          <h1 class="truncate text-[1.0625rem] leading-snug font-medium">
            {{ card.grammarPoint.title }}
          </h1>
        </div>

        <NuxtLink
          v-if="!ended"
          to="/roadmap"
          class="shrink-0 rounded-full border border-[var(--line)] px-3 py-1.5 text-[0.8125rem] text-[var(--ink-muted)] transition hover:border-[var(--line-strong)] hover:text-[var(--ink)]"
        >
          {{ talk.picker }}
        </NuxtLink>
      </header>

      <!-- Once the session ends the stage is replaced by the outcome: leaving a
           dead orb on screen would invite a tap that does nothing. -->
      <div v-if="ended" class="space-y-6 py-6">
        <div class="space-y-3 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6">
          <p class="label">{{ talk.endedTitle }}</p>
          <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ talk.endedBody }}</p>
          <div class="flex flex-wrap items-center gap-3 pt-1">
            <UiButtonLink v-if="sessionId" :to="`/reflect/${sessionId}`" variant="primary" size="lg">
              {{ talk.seeFeedback }}
            </UiButtonLink>
            <UiButton variant="quiet" size="lg" @click="begin">{{ talk.again }}</UiButton>
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
          <p class="label">{{ talk.topic }}</p>
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
