<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { common, review as copy } from '~/constants/strings'
import { countWithUnit, formatReviewDate, COUNTER } from '~/utils/format'

/**
 * 復習 — spaced repetition as recall, not self-report.
 *
 * One card at a time. The learner's own sentence from the conversation that
 * produced the item is the prompt; the correction stays hidden until they have
 * tried to produce it, and only then do they grade how the recall felt. The
 * queue shrinks in `useReviews`, so the current item is always its first row and
 * `reviewedCount` keeps the progress denominator stable while it does.
 */
const {
  dueReviews,
  recurringMistakes,
  loading,
  error,
  submittingId,
  itemErrors,
  submitReview,
  refresh,
} = useReviews()

const refreshing = ref(false)
const revealed = ref(false)
const pendingQuality = ref<number | null>(null)
const reviewedCount = ref(0)
const lastIntervalDays = ref<number | null>(null)
const lastNextReviewAt = ref<string | null>(null)

const currentItem = computed(() => dueReviews.value[0] ?? null)
const totalCount = computed(() => reviewedCount.value + dueReviews.value.length)
const completed = computed(() => reviewedCount.value > 0 && dueReviews.value.length === 0)
const isSubmitting = computed(() => submittingId.value === currentItem.value?.grammarPoint.id)

// A new card means a new question: never carry the previous answer into it.
watch(
  () => currentItem.value?.id,
  () => {
    revealed.value = false
  },
)

/**
 * Yet another tint per grade, but this time the colour reinforces the label
 * rather than replacing it: a fail is clay, a shaky pass amber, a clean pass
 * sage. Four wide targets beat six bare digits on a phone.
 */
const GRADE_TINTS: Record<number, string> = {
  0: 'border-[color-mix(in_oklab,var(--color-clay-400)_40%,transparent)] hover:bg-[color-mix(in_oklab,var(--color-clay-500)_12%,transparent)]',
  3: 'border-[color-mix(in_oklab,var(--color-amber-bright)_36%,transparent)] hover:bg-[color-mix(in_oklab,var(--color-amber-bright)_10%,transparent)]',
  4: 'border-[color-mix(in_oklab,var(--color-sage-400)_32%,transparent)] hover:bg-[color-mix(in_oklab,var(--color-sage-500)_9%,transparent)]',
  5: 'border-[color-mix(in_oklab,var(--color-sage-400)_50%,transparent)] hover:bg-[color-mix(in_oklab,var(--color-sage-500)_16%,transparent)]',
}

async function handleRefresh(): Promise<void> {
  refreshing.value = true

  try {
    reviewedCount.value = 0
    lastIntervalDays.value = null
    lastNextReviewAt.value = null
    revealed.value = false
    await refresh()
  } finally {
    refreshing.value = false
  }
}

async function grade(quality: number): Promise<void> {
  const item = currentItem.value

  if (!item || isSubmitting.value) {
    return
  }

  pendingQuality.value = quality
  const updated = await submitReview(item.grammarPoint.id, quality)
  pendingQuality.value = null

  if (!updated) {
    return
  }

  reviewedCount.value += 1
  lastIntervalDays.value = updated.intervalDays
  lastNextReviewAt.value = updated.nextReviewAt
  revealed.value = false
}
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-8">
    <PageHeader :title="copy.title" :lead="copy.subtitle">
      <UiButton
        variant="ghost"
        size="sm"
        class="mt-2 -ml-3.5"
        :loading="refreshing || loading"
        @click="handleRefresh"
      >
        {{ refreshing ? common.refreshing : common.refresh }}
      </UiButton>
    </PageHeader>

    <UiNotice v-if="error" :message="error">
      <template #actions>
        <UiButton variant="ghost" size="sm" :loading="refreshing" @click="handleRefresh">
          {{ common.retryShort }}
        </UiButton>
      </template>
    </UiNotice>

    <p v-if="loading && dueReviews.length === 0" class="flex items-center gap-3 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ copy.due }}…
    </p>

    <!-- The learner finished today's queue: say so, and when the next item is
         due, instead of silently swapping back to the "nothing due" panel. -->
    <div
      v-else-if="completed"
      class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-7"
    >
      <div class="space-y-2">
        <h2 class="display text-[1.25rem] leading-snug">{{ copy.doneTitle }}</h2>
        <p class="max-w-[44ch] text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
          {{ copy.doneBody }}
        </p>
        <p class="tnum text-[0.875rem] text-[var(--ink-faint)]">
          {{ countWithUnit(reviewedCount, COUNTER.items) }}{{ copy.doneCountSuffix }}
        </p>
      </div>
      <p v-if="lastNextReviewAt" class="tnum text-[0.875rem] text-[var(--ink-muted)]">
        {{ copy.nextAt }}: {{ formatReviewDate(lastNextReviewAt) }}
      </p>
      <UiButtonLink to="/talk" variant="primary" size="lg">{{ copy.emptyCta }}</UiButtonLink>
    </div>

    <!-- Nothing due at all: an honest empty state with somewhere to go next. -->
    <div
      v-else-if="dueReviews.length === 0"
      class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-7"
    >
      <div class="space-y-2">
        <h2 class="display text-[1.25rem] leading-snug">{{ copy.empty }}</h2>
        <p class="max-w-[44ch] text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
          {{ copy.emptyBody }}
        </p>
      </div>
      <UiButtonLink to="/talk" variant="primary" size="lg">{{ copy.emptyCta }}</UiButtonLink>
    </div>

    <div v-else-if="currentItem" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
        <p class="label">{{ copy.due }}</p>
        <p class="tnum label">
          {{ countWithUnit(totalCount, COUNTER.items) }} · {{ reviewedCount + 1 }} / {{ totalCount }}
        </p>
      </div>

      <p v-if="lastIntervalDays !== null" class="text-[0.8125rem] text-[var(--ink-faint)]">
        {{ copy.scheduledPrefix }}{{ countWithUnit(lastIntervalDays, COUNTER.days) }}{{ copy.scheduledSuffix }}
      </p>

      <article class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
        <header class="space-y-1">
          <p class="label">{{ currentItem.grammarPoint.category }}</p>
          <h2 class="text-[1.0625rem] leading-snug font-medium">{{ currentItem.grammarPoint.title }}</h2>
        </header>

        <div class="space-y-5 border-t border-[var(--line)] pt-4">
          <template v-if="currentItem.latestMistake">
            <div class="space-y-2">
              <p class="label">{{ copy.yourUtterance }}</p>
              <p class="text-[1.0625rem] leading-[1.7] text-[var(--ink)]">
                「{{ currentItem.latestMistake.userUtterance }}」
              </p>
            </div>

            <div v-if="!revealed" class="space-y-3">
              <p class="text-[0.9375rem] text-[var(--ink-muted)]">{{ copy.recallPrompt }}</p>
              <UiButton variant="primary" size="lg" @click="revealed = true">
                {{ copy.reveal }}
              </UiButton>
            </div>

            <div v-else class="space-y-4">
              <div class="space-y-1">
                <p class="label">{{ copy.correction }}</p>
                <p class="text-[1.0625rem] leading-[1.7] font-medium text-[var(--color-sage-400)]">
                  {{ currentItem.latestMistake.correction }}
                </p>
              </div>
              <div class="space-y-1">
                <p class="label">{{ copy.explanation }}</p>
                <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
                  {{ currentItem.latestMistake.explanation }}
                </p>
              </div>
            </div>
          </template>

          <p v-else class="text-[0.9375rem] text-[var(--ink-muted)]">{{ copy.noMistakePrompt }}</p>

          <div v-if="!currentItem.latestMistake || revealed" class="space-y-2.5">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <button
                v-for="entry in copy.grades"
                :key="entry.quality"
                type="button"
                :disabled="isSubmitting"
                class="flex min-h-14 flex-col items-start justify-center gap-0.5 rounded-xl border px-3 py-2 text-left transition disabled:cursor-not-allowed disabled:opacity-40"
                :class="GRADE_TINTS[entry.quality]"
                @click="grade(entry.quality)"
              >
                <UiSpinner
                  v-if="isSubmitting && pendingQuality === entry.quality"
                  class="size-3.5"
                />
                <template v-else>
                  <span class="text-[0.9375rem] leading-tight font-semibold text-[var(--ink)]">
                    {{ entry.label }}
                  </span>
                  <span class="text-[0.75rem] leading-snug text-[var(--ink-faint)]">{{ entry.desc }}</span>
                </template>
              </button>
            </div>

            <p
              v-if="itemErrors[currentItem.grammarPoint.id]"
              class="text-[0.8125rem] text-[var(--color-clay-400)]"
            >
              {{ itemErrors[currentItem.grammarPoint.id] }}
            </p>
          </div>
        </div>
      </article>
    </div>

    <!-- Recurring mistakes are context, not a second queue, so they sit below
         the fold behind a rule rather than beside the work. -->
    <section
      v-if="recurringMistakes && recurringMistakes.length > 0"
      class="space-y-4 border-t border-[var(--line)] pt-8"
    >
      <header class="space-y-1">
        <h2 class="text-[1rem] font-medium">{{ copy.recurring }}</h2>
        <p class="text-[0.875rem] text-[var(--ink-muted)]">{{ copy.recurringBody }}</p>
      </header>

      <ul class="divide-y divide-[var(--line)]">
        <li
          v-for="recurring in recurringMistakes"
          :key="recurring.grammarPoint.id"
          class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"
        >
          <div class="min-w-0">
            <p class="label">{{ recurring.grammarPoint.category }}</p>
            <p class="text-[0.9375rem]">{{ recurring.grammarPoint.title }}</p>
          </div>
          <p class="tnum shrink-0 text-[0.8125rem] text-[var(--ink-faint)]">
            {{ copy.totalMistakes }} {{ countWithUnit(recurring.mistakeCount, COUNTER.times) }}
          </p>
        </li>
      </ul>
    </section>
  </section>
</template>
