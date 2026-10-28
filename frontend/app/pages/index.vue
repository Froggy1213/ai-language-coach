<script setup lang="ts">
import { useQuery } from '@urql/vue'
import { DUE_REVIEWS_QUERY } from '~/graphql/documents'
import type { ReviewItem } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { greetingFor, COUNTER } from '~/utils/format'
import { roadmap as roadmapCopy, today } from '~/constants/strings'

/**
 * 今日 — the daily entry point.
 *
 * This is a **Decide/Learn** surface with exactly one decision on it: speak now,
 * or do something else. Everything above the fold answers "what is next" and
 * nothing else; the numbers sit below as context, not as a dashboard.
 */
const { user } = useAuth()
const route = useRoute()

const showLanding = computed(
  () => !user.value || route.query.preview === 'landing',
)

const {
  roadmap,
  readyCard,
  completedCount,
  totalCount,
  isComplete,
  building,
  buildError,
  buildRoadmap,
  fetching,
  error,
} = useRoadmap({ pause: showLanding })

const { data: reviewsData } = useQuery<{ dueReviews: ReviewItem[] }>({
  query: DUE_REVIEWS_QUERY,
  pause: showLanding,
  requestPolicy: 'cache-and-network',
})

const dueCount = computed(() => reviewsData.value?.dueReviews?.length ?? 0)

const greeting = computed(() => greetingFor())

const dateLabel = computed(() =>
  new Intl.DateTimeFormat('ja-JP', {
    month: 'long',
    day: 'numeric',
    weekday: 'long',
  }).format(new Date()),
)

const firstName = computed(() => user.value?.name?.split(/\s+/)[0] ?? '')
</script>

<template>
  <LandingScrollytelling v-if="showLanding" />

  <template v-else>
    <section class="space-y-10">
      <header class="space-y-1.5">
        <p class="label">{{ dateLabel }}</p>
        <h1 class="display text-[1.75rem] leading-[1.25] tracking-[0.02em] sm:text-[2rem]">
          {{ greeting }}<template v-if="firstName">、{{ firstName }}さん</template>
        </h1>
      </header>

      <p v-if="fetching && !roadmap" class="flex items-center gap-3 text-[0.9375rem] text-[var(--ink-muted)]">
        <UiSpinner />
        {{ today.continueLabel }}…
      </p>

      <UiNotice
        v-else-if="error && !roadmap"
        :message="graphQLErrorMessageFor(error, { fallback: roadmapCopy.loadError })"
      />

      <!-- A roadmap exists: the only thing worth showing is the next lesson. -->
      <template v-else-if="roadmap && readyCard">
        <article class="rise overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)]">
          <!-- A wash of the accent at the top edge, so the card reads as active
               without a border colour doing all the work. -->
          <div class="h-px w-full bg-gradient-to-r from-transparent via-[var(--accent)] to-transparent opacity-60" />

          <div class="space-y-5 p-6 sm:p-7">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
              <p class="label">{{ today.continueLabel }}</p>
              <span class="tnum text-[0.75rem] text-[var(--ink-faint)]">
                #{{ readyCard.orderIndex }} · {{ readyCard.grammarPoint.category }}
              </span>
            </div>

            <h2 class="display text-[1.5rem] leading-snug tracking-[0.01em] sm:text-[1.75rem]">
              {{ readyCard.grammarPoint.title }}
            </h2>

            <p class="rounded-2xl bg-[var(--surface-sunken)] px-4 py-3.5 text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
              {{ readyCard.practicePrompt }}
            </p>

            <div class="flex flex-wrap items-center gap-3 pt-1">
              <UiButtonLink :to="`/talk/${readyCard.id}`" variant="primary" size="lg">
                {{ today.talkCta }}
              </UiButtonLink>
              <UiButtonLink to="/roadmap" variant="ghost" size="lg">
                {{ today.roadmapLink }}
              </UiButtonLink>
            </div>
          </div>
        </article>
      </template>

      <!-- Roadmap finished: celebrate once, then point at review. -->
      <UiNotice v-else-if="roadmap && isComplete" tone="success" :title="today.allDone">
        {{ today.allDoneBody }}
      </UiNotice>

      <!-- No roadmap yet: one decision, two honest paths to it. -->
      <article v-else-if="!fetching" class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-7">
        <div class="space-y-2">
          <h2 class="display text-[1.375rem] leading-snug">{{ today.noRoadmap }}</h2>
          <p class="max-w-[44ch] text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
            {{ today.noRoadmapBody }}
          </p>
        </div>

        <UiNotice v-if="buildError" :message="buildError" />

        <div class="flex flex-wrap items-center gap-3">
          <UiButtonLink to="/level" variant="primary" size="lg">
            {{ today.takeAssessment }}
          </UiButtonLink>
          <UiButton variant="quiet" size="lg" :loading="building" @click="buildRoadmap">
            {{ building ? roadmapCopy.building : today.buildFirst }}
          </UiButton>
        </div>
      </article>
    </section>

    <!-- Context, not a dashboard: three facts the learner might act on. -->
    <section v-if="roadmap" class="mt-12 grid grid-cols-2 gap-x-6 gap-y-8 border-t border-[var(--line)] pt-8 sm:grid-cols-3">
      <div class="space-y-1.5">
        <p class="label">{{ today.stats.progress }}</p>
        <p class="tnum display text-[1.5rem] leading-none">
          {{ completedCount }}<span class="text-[1rem] text-[var(--ink-faint)]"> / {{ totalCount }}</span>
        </p>
        <UiProgress :value="completedCount" :total="totalCount" />
      </div>

      <NuxtLink to="/review" class="group space-y-1.5">
        <p class="label">{{ today.stats.due }}</p>
        <p class="tnum display text-[1.5rem] leading-none transition-colors group-hover:text-[var(--accent)]">
          {{ dueCount }}<span class="ml-1 text-[0.875rem] text-[var(--ink-faint)]">{{ COUNTER.items }}</span>
        </p>
        <p class="text-[0.8125rem] text-[var(--ink-faint)]">
          {{ dueCount > 0 ? '復習できます' : 'すべて完了' }}
        </p>
      </NuxtLink>

      <div class="space-y-1.5">
        <p class="label">{{ today.stats.level }}</p>
        <p class="tnum display text-[1.5rem] leading-none">{{ user?.currentLevel ?? '—' }}</p>
        <!-- A bare text link is only ~16px tall. The pseudo-element grows the
             touch target to a comfortable size without changing the layout. -->
        <NuxtLink
          to="/level"
          class="relative inline-block text-[0.8125rem] text-[var(--ink-faint)] transition-colors after:absolute after:-inset-3 after:content-[''] hover:text-[var(--accent)]"
        >
          判定を受け直す
        </NuxtLink>
      </div>
    </section>

    <p v-if="roadmap && readyCard" class="mt-10 text-[0.8125rem] text-[var(--ink-faint)]">
      {{ today.streakHint }}
    </p>
  </template>
</template>

