<script setup lang="ts">
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { scenarioRegisterLabel } from '~/utils/format'
import { talk } from '~/constants/strings'

/**
 * 会話 — the topic picker.
 *
 * The centre tab now opens a choice rather than "the lesson in progress": free
 * conversation first, because that is the mode with no preparation cost, then
 * the scenario catalogue. Each row names only what the learner needs to choose
 * — title, summary, level, register — never the AI's own goal or complications,
 * which the API deliberately withholds.
 *
 * 自由に話す is itself a scenario code (`free_talk`), but it is a promise of "no
 * script" rather than one topic among many, so it gets the first, roomier row
 * and is filtered out of the catalogue below to avoid listing it twice.
 */
const { scenarios, fetching, error, refresh } = useScenarios()

const retrying = ref(false)

async function retry(): Promise<void> {
  retrying.value = true

  try {
    await refresh()
  } finally {
    retrying.value = false
  }
}

const catalogue = computed(() => scenarios.value.filter((scenario) => scenario.code !== 'free_talk'))

const showEmpty = computed(() => !fetching.value && !error.value && catalogue.value.length === 0)
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-8">
    <PageHeader :title="talk.title" :lead="talk.picker" />

    <p v-if="fetching && scenarios.length === 0" class="flex items-center gap-3 py-16 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ talk.loading }}
    </p>

    <UiNotice
      v-else-if="error && scenarios.length === 0"
      :message="graphQLErrorMessageFor(error, { fallback: talk.loadError })"
    >
      <template #actions>
        <UiButton variant="ghost" size="sm" :loading="retrying" @click="retry">
          {{ talk.retry }}
        </UiButton>
      </template>
    </UiNotice>

    <template v-else>
      <!-- Free conversation: no scene, no role, no preparation. -->
      <NuxtLink
        to="/talk/free_talk"
        class="group block overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)] transition-colors hover:border-[var(--line-strong)]"
      >
        <div class="h-px w-full bg-gradient-to-r from-transparent via-[var(--accent)] to-transparent opacity-60" />

        <div class="flex items-start gap-4 p-6 sm:p-7">
          <div class="min-w-0 flex-1 space-y-2">
            <h2 class="display text-[1.375rem] leading-snug tracking-[0.01em] sm:text-[1.5rem]">
              {{ talk.freeTalk }}
            </h2>
            <p class="max-w-[46ch] text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
              {{ talk.freeTalkBody }}
            </p>
          </div>

          <span
            class="mt-1 shrink-0 text-[1rem] text-[var(--ink-faint)] transition-colors group-hover:text-[var(--accent)]"
            aria-hidden="true"
          >
            →
          </span>
        </div>
      </NuxtLink>

      <div v-if="catalogue.length" class="space-y-3">
        <NuxtLink
          v-for="scenario in catalogue"
          :key="scenario.code"
          :to="`/talk/${scenario.code}`"
          class="group block rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 transition-colors hover:border-[var(--line-strong)]"
        >
          <div class="flex items-start gap-4">
            <div class="min-w-0 flex-1 space-y-2">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <p class="label">{{ scenario.level }} · {{ scenarioRegisterLabel(scenario.register) }}</p>
              </div>
              <h2 class="text-[1.0625rem] leading-snug font-medium">{{ scenario.title }}</h2>
              <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
                {{ scenario.summary }}
              </p>
            </div>

            <span
              class="mt-0.5 shrink-0 text-[1rem] text-[var(--ink-faint)] transition-colors group-hover:text-[var(--accent)]"
              aria-hidden="true"
            >
              →
            </span>
          </div>
        </NuxtLink>
      </div>

      <PageHeader
        v-else-if="showEmpty"
        :title="talk.empty"
        :lead="talk.emptyBody"
        :display="false"
      />
    </template>
  </section>
</template>
