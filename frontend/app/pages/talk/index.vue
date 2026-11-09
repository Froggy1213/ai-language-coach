<script setup lang="ts">
import { useMutation } from '@urql/vue'
import { DELETE_CUSTOM_SCENARIO_MUTATION } from '~/graphql/documents'
import type { Scenario } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { scenarioRegisterLabel } from '~/utils/format'
import { talk } from '~/constants/strings'

/**
 * 会話 — the topic picker and custom scenario studio.
 *
 * The centre tab opens a choice rather than "the lesson in progress": free
 * conversation first, then custom scenario creation (where the learner writes a
 * short situation prompt, the AI expands it into a role-play card, and the
 * learner reviews and confirms it), followed by the learner's saved custom
 * scenarios and the built-in catalogue.
 */
const router = useRouter()
const { user } = useAuth()
const { scenarios, fetching, error, refresh } = useScenarios()
const deleteMutation = useMutation(DELETE_CUSTOM_SCENARIO_MUTATION)

const retrying = ref(false)
const builderOpen = ref(false)
const deletingCode = ref<string | null>(null)
const deleteError = ref<string | null>(null)

async function retry(): Promise<void> {
  retrying.value = true

  try {
    await refresh()
  } finally {
    retrying.value = false
  }
}

const customScenarios = computed(() =>
  scenarios.value.filter((scenario) => scenario.isCustom),
)

const catalogue = computed(() =>
  scenarios.value.filter((scenario) => !scenario.isCustom && scenario.code !== 'free_talk'),
)

const showEmpty = computed(
  () => !fetching.value && !error.value && customScenarios.value.length === 0 && catalogue.value.length === 0,
)

async function handleScenarioSaved(scenario: Scenario, startImmediately: boolean): Promise<void> {
  builderOpen.value = false
  await refresh()

  if (startImmediately) {
    await router.push(`/talk/${scenario.code}`)
  }
}

async function removeCustomScenario(code: string): Promise<void> {
  if (deletingCode.value) {
    return
  }

  deletingCode.value = code
  deleteError.value = null

  try {
    const result = await deleteMutation.executeMutation({ code })
    if (result.error) {
      deleteError.value = graphQLErrorMessageFor(result.error, {
        fallback: talk.custom.deleteError,
      })
      return
    }

    await refresh()
  } finally {
    deletingCode.value = null
  }
}
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
      <UiNotice v-if="deleteError" :message="deleteError" />

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

      <!-- Custom scenario builder: short prompt -> AI refinement -> user confirmation -->
      <div>
        <button
          v-if="!builderOpen"
          type="button"
          data-testid="open-custom-scenario-builder"
          class="group flex w-full items-start justify-between gap-4 rounded-2xl border border-dashed border-[var(--line-strong)] bg-[var(--surface)] p-5 text-left transition-colors hover:border-[var(--accent)]"
          @click="builderOpen = true"
        >
          <div class="min-w-0 flex-1 space-y-1.5">
            <div class="flex items-center gap-2">
              <span class="label text-[var(--accent)]">+ {{ talk.custom.openBuilder }}</span>
            </div>
            <p class="text-[0.875rem] leading-relaxed text-[var(--ink-muted)]">
              {{ talk.custom.openBuilderLead }}
            </p>
          </div>
          <span
            class="mt-0.5 shrink-0 text-[1rem] text-[var(--ink-faint)] transition-colors group-hover:text-[var(--accent)]"
            aria-hidden="true"
          >
            ＋
          </span>
        </button>

        <CustomScenarioBuilder
          v-else
          :default-level="user?.currentLevel"
          @saved="handleScenarioSaved"
          @cancel="builderOpen = false"
        />
      </div>

      <!-- Learner's confirmed custom scenarios -->
      <div v-if="customScenarios.length" class="space-y-3" data-testid="custom-scenarios-list">
        <p class="label">{{ talk.custom.sectionTitle }}</p>

        <div
          v-for="scenario in customScenarios"
          :key="scenario.code"
          class="group relative flex items-start gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 transition-colors hover:border-[var(--line-strong)]"
        >
          <NuxtLink
            :to="`/talk/${scenario.code}`"
            class="min-w-0 flex-1 space-y-2"
          >
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
              <span class="rounded-full border border-[var(--accent)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.06em] text-[var(--accent)]">
                {{ talk.custom.badge }}
              </span>
              <p class="label">{{ scenario.level }} · {{ scenarioRegisterLabel(scenario.register) }}</p>
            </div>
            <h2 class="text-[1.0625rem] leading-snug font-medium">{{ scenario.title }}</h2>
            <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
              {{ scenario.summary }}
            </p>
          </NuxtLink>

          <div class="flex shrink-0 items-center gap-2">
            <UiButton
              variant="ghost"
              size="sm"
              :loading="deletingCode === scenario.code"
              :data-testid="`delete-custom-scenario-${scenario.code}`"
              @click="removeCustomScenario(scenario.code)"
            >
              {{ deletingCode === scenario.code ? talk.custom.deleting : talk.custom.deleteCta }}
            </UiButton>
          </div>
        </div>
      </div>

      <!-- Built-in catalogue -->
      <div v-if="catalogue.length" class="space-y-3">
        <p v-if="customScenarios.length" class="label">{{ talk.custom.catalogueTitle }}</p>

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
