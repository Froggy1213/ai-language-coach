<script setup lang="ts">
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { roadmap as copy, today } from '~/constants/strings'
import { count, COUNTER } from '~/utils/format'

/**
 * ロードマップ — the learning path.
 *
 * A **Compare/Explore** surface: the learner is reading a sequence to understand
 * where they are in it. So it is one continuous vertical path with the current
 * stop marked, not a grid of equal-weight cards.
 */
const {
  roadmap,
  completedCount,
  totalCount,
  allDone,
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
      <div class="max-w-md">
        <UiProgress
          :value="completedCount"
          :total="totalCount"
          :label="copy.progress"
        />
      </div>

      <p v-if="allDone" class="flex items-center gap-2 text-[0.9375rem] text-[var(--color-sage-400)]">
        <span class="size-1.5 rounded-full bg-[var(--color-sage-400)]" />
        {{ copy.allDone }}
      </p>

      <ol class="mt-2">
        <RoadmapLesson
          v-for="(card, index) in roadmap.lessonCards"
          :key="card.id"
          :card="card"
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
