<script setup lang="ts">
import type { VoiceSessionStatus } from '~/types/graphql'
import { formatDuration } from '~/utils/format'
import { common, reflect } from '~/constants/strings'

/**
 * 振り返り — the review of one finished conversation.
 *
 * A dedicated screen rather than a section bolted onto the conversation: the two
 * jobs have opposite needs. Talking wants no text on screen at all; reviewing
 * wants the mistakes and nothing else. Splitting them is what lets each one be
 * quiet.
 */
const route = useRoute()
const sessionId = computed(() => (route.params.id ? String(route.params.id) : ''))

const { session, mistakes, loading, error, analyzing, refresh } = useSessionFeedback(sessionId)

const refreshing = ref(false)
const stoppedWaiting = ref(false)

const isAnalyzing = computed(() => analyzing.value && !stoppedWaiting.value)

async function handleRefresh(): Promise<void> {
  refreshing.value = true

  try {
    await refresh()
    stoppedWaiting.value = true
  } finally {
    refreshing.value = false
  }
}

const STATUS_LABELS: Record<VoiceSessionStatus, string> = {
  pending: reflect.statusPending,
  active: reflect.statusActive,
  completed: reflect.statusCompleted,
  failed: reflect.statusFailed,
  abandoned: reflect.statusAbandoned,
}

const FAIL_REASONS: Record<string, string> = {
  voice_fleet_busy: reflect.failVoiceBusy,
  voice_start_failed: reflect.failVoiceStart,
  livekit_server_shutdown: reflect.failLivekitShutdown,
  livekit_room_open_failed: reflect.failLivekitRoom,
  stt_failed: reflect.failStt,
  tts_failed: reflect.failTts,
  llm_failed: reflect.failLlm,
  agent_error: reflect.failAgent,
}

function failReason(reason: string | null | undefined): string {
  if (!reason) {
    return reflect.failUnknown
  }

  return FAIL_REASONS[reason] ?? reason
}

/** Mistakes grouped by the grammar point they were matched to. */
const groups = computed(() => {
  const byPoint = new Map<string, { id: string, title: string, category: string, items: typeof mistakes.value }>()

  for (const mistake of mistakes.value) {
    const point = mistake.grammarPoint
    const key = point?.id || point?.title || 'other'

    if (!byPoint.has(key)) {
      byPoint.set(key, {
        id: point?.id ?? '',
        title: point?.title ?? reflect.grammar,
        category: point?.category ?? reflect.general,
        items: [],
      })
    }

    byPoint.get(key)!.items.push(mistake)
  }

  return Array.from(byPoint.values())
})

const durationLabel = computed(() => formatDuration(session.value?.durationSec ?? 0))
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-8">
    <p v-if="loading && !session" class="flex items-center gap-3 py-16 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ reflect.loading }}
    </p>

    <div v-else-if="!session" class="space-y-5 py-10">
      <PageHeader :title="reflect.notFound" :lead="reflect.notFoundBody" />
      <UiButtonLink to="/roadmap" variant="primary" size="lg">{{ common.backToRoadmap }}</UiButtonLink>
    </div>

    <template v-else>
      <header class="space-y-3">
        <NuxtLink
          to="/talk"
          class="inline-flex items-center gap-1.5 text-[0.8125rem] text-[var(--ink-faint)] transition-colors hover:text-[var(--ink)]"
        >
          <span aria-hidden="true">←</span>
          {{ reflect.backToTalk }}
        </NuxtLink>

        <div class="space-y-1">
          <p class="label">
            {{ session.lessonCard?.grammarPoint?.category ?? reflect.general }}
          </p>
          <h1 class="display text-[1.5rem] leading-snug tracking-[0.01em] sm:text-[1.75rem]">
            {{ session.lessonCard?.grammarPoint?.title ?? reflect.title }}
          </h1>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[0.8125rem] text-[var(--ink-faint)]">
          <span>{{ STATUS_LABELS[session.status] }}</span>
          <span v-if="durationLabel" class="tnum">{{ reflect.duration }} {{ durationLabel }}</span>
        </div>
      </header>

      <UiNotice v-if="error" tone="warn">
        {{ error }}
        <template #actions>
          <UiButton variant="ghost" size="sm" :loading="refreshing" @click="handleRefresh">
            {{ common.retryShort }}
          </UiButton>
        </template>
      </UiNotice>

      <UiNotice v-if="session.status === 'failed' || session.failReason" :title="reflect.failReason">
        {{ failReason(session.failReason) }}
      </UiNotice>

      <!-- Analysis in flight. The orb's job is done; this is a plain status row. -->
      <div
        v-if="isAnalyzing"
        class="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-4 py-4"
      >
        <UiSpinner />
        <div class="min-w-0 flex-1">
          <p class="text-[0.9375rem] font-medium">{{ reflect.analyzing }}</p>
          <p class="text-[0.8125rem] leading-relaxed text-[var(--ink-faint)]">{{ reflect.analyzingBody }}</p>
        </div>
        <UiButton variant="quiet" size="sm" :loading="refreshing" @click="handleRefresh">
          {{ reflect.checkNow }}
        </UiButton>
      </div>

      <template v-else>
        <!-- An empty mistake list is only good news once the analysis has
             actually run. Without the `analyzed` guard a session whose analysis
             never completed — or whose completion push never arrived — was
             presented as a flawless conversation, which is the one reading the
             learner must be able to trust. -->
        <UiNotice
          v-if="mistakes.length === 0 && session.analyzed"
          tone="success"
          :title="reflect.noMistakes"
        >
          {{ reflect.noMistakesBody }}
        </UiNotice>

        <UiNotice
          v-else-if="mistakes.length === 0"
          tone="warn"
          :title="reflect.analysisUnfinished"
        >
          {{ reflect.analysisUnfinishedBody }}
        </UiNotice>

        <div v-else class="space-y-8">
          <section v-for="group in groups" :key="group.id || group.title" class="space-y-3">
            <header class="flex items-baseline gap-3">
              <h2 class="text-[1rem] font-medium">{{ group.title }}</h2>
              <span class="tnum text-[0.8125rem] text-[var(--ink-faint)]">{{ group.items.length }}</span>
            </header>

            <div class="space-y-3">
              <MistakeItem v-for="mistake in group.items" :key="mistake.id" :mistake="mistake" />
            </div>
          </section>
        </div>
      </template>

      <footer class="flex flex-wrap items-center gap-3 border-t border-[var(--line)] pt-6">
        <UiButtonLink to="/talk" variant="primary">
          {{ reflect.backToTalk }}
        </UiButtonLink>
        <UiButtonLink to="/review" variant="quiet">
          {{ reflect.toReview }}
        </UiButtonLink>
      </footer>
    </template>
  </section>
</template>
