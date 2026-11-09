<script setup lang="ts">
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { roadmap as copy, today } from '~/constants/strings'
import { count, COUNTER } from '~/utils/format'

/**
 * ロードマップ — the learning path.
 *
 * A **Compare/Explore** surface: the learner is reading the full A1–C1 grammar
 * graph or sequence to understand where they are, which topics are unlocked,
 * and which topics need practice from recent conversations.
 */
const {
  roadmap,
  readyCard,
  completedCount,
  totalCount,
  allDone,
  view,
  setView,
  generating,
  generateError,
  generate,
  fetching,
  error,
} = useRoadmap()
</script>

<template>
  <section class="space-y-8">
    <!-- The page title is ours, not the API's: `roadmap.title` is generated
         server-side in the learner's *old* interface language. -->
    <PageHeader :title="copy.title" :lead="copy.subtitle" />

    <p v-if="fetching && !roadmap" class="flex items-center gap-3 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ copy.title }}…
    </p>

    <UiNotice
      v-else-if="error && !roadmap"
      :message="graphQLErrorMessageFor(error, { fallback: copy.loadError })"
    />

    <template v-else-if="roadmap">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="w-full max-w-md flex-1">
          <UiProgress
            :value="completedCount"
            :total="totalCount"
            :label="copy.progress"
          />
        </div>

        <!-- View segmented control (マップ / リスト) -->
        <div
          class="inline-flex rounded-full border border-[var(--line-strong)] bg-[var(--surface)] p-0.5"
          role="group"
          :aria-label="copy.title"
        >
          <button
            type="button"
            class="rounded-full px-3.5 py-1 text-[0.8125rem] font-medium transition-colors"
            :class="
              view === 'graph'
                ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
                : 'text-[var(--ink-muted)] hover:text-[var(--ink)]'
            "
            :aria-pressed="view === 'graph'"
            data-testid="roadmap-view-graph"
            @click="setView('graph')"
          >
            {{ copy.views.graph }}
          </button>
          <button
            type="button"
            class="rounded-full px-3.5 py-1 text-[0.8125rem] font-medium transition-colors"
            :class="
              view === 'list'
                ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
                : 'text-[var(--ink-muted)] hover:text-[var(--ink)]'
            "
            :aria-pressed="view === 'list'"
            data-testid="roadmap-view-list"
            @click="setView('list')"
          >
            {{ copy.views.list }}
          </button>
        </div>
      </div>

      <p v-if="allDone" class="flex items-center gap-2 text-[0.9375rem] text-[var(--color-sage-400)]">
        <span class="size-1.5 rounded-full bg-[var(--color-sage-400)]" />
        {{ copy.allDone }}
      </p>

      <RoadmapGraph
        v-if="view === 'graph'"
        :cards="roadmap.lessonCards"
        :recommended-card-id="readyCard?.id ?? null"
      />

      <ol v-else class="mt-2">
        <RoadmapLesson
          v-for="(card, index) in roadmap.lessonCards"
          :key="card.id"
          :card="card"
          :recommended="card.id === readyCard?.id"
          :last="index === roadmap.lessonCards.length - 1"
        />
      </ol>

      <p class="tnum pt-2 text-[0.8125rem] text-[var(--ink-faint)]">
        {{ count(totalCount, COUNTER.lessons) }}
      </p>
    </template>

    <!-- No roadmap yet: one explanation, one primary action, one alternative. -->
    <div v-else class="space-y-6">
      <PageHeader :title="copy.empty" :lead="copy.emptyBody" :display="false" />

      <UiNotice v-if="generateError" :message="generateError" />

      <div class="flex flex-wrap items-center gap-3">
        <UiButton variant="primary" size="lg" :loading="generating" @click="generate">
          {{ generating ? copy.building : copy.build }}
        </UiButton>
        <UiButtonLink to="/level" variant="ghost" size="lg">
          {{ today.takeAssessment }}
        </UiButtonLink>
      </div>
    </div>
  </section>
</template>
