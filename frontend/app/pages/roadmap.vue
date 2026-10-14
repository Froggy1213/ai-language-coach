<script setup lang="ts">
import { useQuery } from '@urql/vue'
import type { CombinedError } from '@urql/vue'
import { GENERATE_ROADMAP_MUTATION, ROADMAP_QUERY } from '~/graphql/documents'
import type { Roadmap } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { roadmap as copy } from '~/constants/strings'
import { count, COUNTER } from '~/utils/format'

/**
 * ロードマップ — the learning path.
 *
 * A **Compare/Explore** surface: the learner is reading a sequence to understand
 * where they are in it. So it is one continuous vertical path with the current
 * stop marked, not a grid of equal-weight cards.
 */
const { $urql } = useNuxtApp()

const { data, fetching, error, executeQuery } = useQuery<{ roadmap: Roadmap | null }>({
  query: ROADMAP_QUERY,
  requestPolicy: 'network-only',
})

const roadmap = computed(() => data.value?.roadmap ?? null)

const completedCount = computed(
  () => roadmap.value?.lessonCards.filter((card) => card.status === 'completed').length ?? 0,
)

const totalCount = computed(() => roadmap.value?.lessonCards.length ?? 0)

const allDone = computed(() => totalCount.value > 0 && completedCount.value >= totalCount.value)

const generating = ref(false)
const generateError = ref<string | null>(null)

async function generate(): Promise<void> {
  generating.value = true
  generateError.value = null

  try {
    const { error: failure } = await $urql.mutation(GENERATE_ROADMAP_MUTATION, {}).toPromise()

    if (failure) {
      generateError.value = graphQLErrorMessageFor(failure as CombinedError, {
        fallback: copy.buildError,
      })
    } else {
      // The mutation is idempotent and reports only the title; the card list is
      // the query's job.
      await executeQuery({ requestPolicy: 'network-only' })
    }
  } catch {
    generateError.value = copy.buildError
  } finally {
    generating.value = false
  }
}
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
          レベル判定を受ける
        </UiButtonLink>
      </div>
    </div>
  </section>
</template>
