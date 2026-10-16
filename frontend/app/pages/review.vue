<script setup lang="ts">
import { common, review as copy } from '~/constants/strings'
import { countWithUnit, formatReviewDate, COUNTER } from '~/utils/format'

/**
 * 復習 — spaced repetition.
 *
 * A **Operate** surface: the learner is working through a queue and answering one
 * question per item. So each item is a single decision, the grading scale is
 * always in the same place, and there is no summary chrome competing with the
 * queue itself.
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
const graded = ref<Record<string, number>>({})

async function handleRefresh(): Promise<void> {
  refreshing.value = true

  try {
    await refresh()
  } finally {
    refreshing.value = false
  }
}

/**
 * Slider tint per grade: clay at "no idea", sage at "perfect".
 *
 * Six equal-weight buttons would read as a row of identical choices. The tint is
 * what carries "this is a scale", so the learner can answer without reading.
 */
const GRADE_TINTS = [
  'border-[color-mix(in_oklab,var(--color-clay-400)_40%,transparent)] text-[var(--color-clay-400)] hover:bg-[color-mix(in_oklab,var(--color-clay-500)_14%,transparent)]',
  'border-[color-mix(in_oklab,var(--color-clay-400)_32%,transparent)] text-[color-mix(in_oklab,var(--color-clay-400)_88%,var(--ink))] hover:bg-[color-mix(in_oklab,var(--color-clay-500)_11%,transparent)]',
  'border-[color-mix(in_oklab,var(--color-amber-bright)_34%,transparent)] text-[var(--color-amber-bright)] hover:bg-[color-mix(in_oklab,var(--color-amber-bright)_12%,transparent)]',
  'border-[var(--line-strong)] text-[var(--ink-muted)] hover:bg-[var(--surface)]',
  'border-[color-mix(in_oklab,var(--color-sage-400)_30%,transparent)] text-[color-mix(in_oklab,var(--color-sage-400)_90%,var(--ink))] hover:bg-[color-mix(in_oklab,var(--color-sage-500)_11%,transparent)]',
  'border-[color-mix(in_oklab,var(--color-sage-400)_46%,transparent)] text-[var(--color-sage-400)] hover:bg-[color-mix(in_oklab,var(--color-sage-500)_16%,transparent)]',
] as const

async function grade(grammarPointId: string, quality: number): Promise<void> {
  graded.value[grammarPointId] = quality
  const saved = await submitReview(grammarPointId, quality)

  if (!saved) {
    delete graded.value[grammarPointId]
  }
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

    <!-- Nothing due: an honest empty state with somewhere to go next, never a
         disabled nav item. -->
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

    <div v-else class="space-y-4">
      <p class="tnum label">{{ dueReviews.length }} {{ COUNTER.items }}</p>

      <article
        v-for="item in dueReviews"
        :key="item.id"
        class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6"
      >
        <header class="space-y-1">
          <p class="label">{{ item.grammarPoint.category }}</p>
          <h2 class="text-[1.0625rem] leading-snug font-medium">{{ item.grammarPoint.title }}</h2>
        </header>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[0.8125rem] text-[var(--ink-faint)]">
          <span class="tnum">{{ copy.interval }} {{ countWithUnit(item.intervalDays, COUNTER.days) }}</span>
          <span class="tnum">{{ copy.repetitions }} {{ countWithUnit(item.repetitionNumber, COUNTER.times) }}</span>
          <span class="tnum">Ease {{ item.easeFactor.toFixed(2) }}</span>
          <span class="tnum">{{ formatReviewDate(item.nextReviewAt) }}</span>
        </div>

        <div class="space-y-2.5 border-t border-[var(--line)] pt-4">
          <p class="text-[0.875rem] text-[var(--ink-muted)]">{{ copy.prompt }}</p>

          <div class="grid grid-cols-6 gap-1.5 sm:gap-2">
            <button
              v-for="entry in copy.grades"
              :key="entry.quality"
              type="button"
              :disabled="submittingId === item.grammarPoint.id"
              class="tnum flex min-h-12 items-center justify-center rounded-xl border text-[1rem] font-semibold transition disabled:cursor-not-allowed disabled:opacity-40"
              :class="GRADE_TINTS[entry.quality]"
              :aria-label="entry.desc"
              :title="entry.desc"
              @click="grade(item.grammarPoint.id, entry.quality)"
            >
              <UiSpinner v-if="submittingId === item.grammarPoint.id && graded[item.grammarPoint.id] === entry.quality" class="size-3.5" />
              <template v-else>{{ entry.quality }}</template>
            </button>
          </div>

          <div class="flex justify-between text-[0.75rem] text-[var(--ink-faint)]">
            <span>{{ copy.scaleLow }}</span>
            <span class="hidden sm:inline">{{ copy.scaleMid }}</span>
            <span>{{ copy.scaleHigh }}</span>
          </div>

          <p v-if="itemErrors[item.grammarPoint.id]" class="text-[0.8125rem] text-[var(--color-clay-400)]">
            {{ itemErrors[item.grammarPoint.id] }}
          </p>
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
