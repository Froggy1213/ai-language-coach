<script setup lang="ts">
import { useMutation } from '@urql/vue'
import {
  DELETE_CUSTOM_SCENARIO_MUTATION,
  FINISH_VOICE_SESSION_MUTATION,
} from '~/graphql/documents'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { scenarioRegisterLabel } from '~/utils/format'
import { lesson, talk } from '~/constants/strings'

/**
 * 会話 — one scenario session.
 *
 * The scene is the subject: who the learner is, what they want, and where they
 * are. The AI's own goal and complications are deliberately absent — the API
 * does not expose them, because knowing them would turn the role-play into
 * reading a script.
 *
 * Two rules separate this screen from a lesson:
 *  - the support panel is the scenario's own phrases, not a grammar cheat sheet;
 *  - finishing never completes a lesson card. A scenario feeds mistakes into
 *    振り返り like any conversation, but it did not practise a grammar point.
 */
const route = useRoute()
const router = useRouter()
const { user } = useAuth()

const consentOpen = ref(false)
const limitOpen = ref(false)

const code = computed(() => String(route.params.code ?? ''))

const { find, fetching, error: queryError, refresh } = useScenarios()

const scenario = computed(() => (code.value ? find(code.value) : null))

const retrying = ref(false)
const editingCustom = ref(false)
const deletingCustom = ref(false)
const customError = ref<string | null>(null)

const deleteCustomMutation = useMutation(DELETE_CUSTOM_SCENARIO_MUTATION)

async function retry(): Promise<void> {
  retrying.value = true

  try {
    await refresh()
  } finally {
    retrying.value = false
  }
}

const audioElement = ref<HTMLAudioElement | null>(null)

const {
  status,
  error: voiceError,
  limit,
  sessionId,
  agentPresent,
  elapsedSeconds,
  orbState,
  energy,
  start,
  leave,
} = useConversation({ audioElement })

watch(limit, (value) => {
  if (value) {
    limitOpen.value = true
  }
})

const finishVoiceMutation = useMutation(FINISH_VOICE_SESSION_MUTATION)
const finishPending = ref(false)

async function finish(): Promise<void> {
  if (finishPending.value) {
    return
  }

  finishPending.value = true

  try {
    // Best effort, like the lesson screen: the webhook finishes the session
    // afterwards if this call fails, and the ended state must still appear.
    if (sessionId.value) {
      try {
        await finishVoiceMutation.executeMutation({ id: sessionId.value })
      } catch {
        // Ignored on purpose: see above, the webhook is the backstop.
      }
    }

    await leave()

    // No completeLessonCard here on purpose: a scenario has no card, and
    // completing one would unlock the course for a conversation that never
    // practised its grammar point.
  } finally {
    finishPending.value = false
  }
}

function begin(): void {
  if (!scenario.value) {
    return
  }

  if (!user.value?.voiceConsentAt) {
    consentOpen.value = true
    return
  }

  void start({ scenarioCode: scenario.value.code })
}

function onConsentConfirmed(): void {
  consentOpen.value = false
  begin()
}

async function handleCustomSaved(_saved: unknown, startImmediately: boolean): Promise<void> {
  editingCustom.value = false
  await refresh()

  if (startImmediately) {
    begin()
  }
}

async function removeCustom(): Promise<void> {
  if (!scenario.value?.isCustom || deletingCustom.value) {
    return
  }

  deletingCustom.value = true
  customError.value = null

  try {
    const result = await deleteCustomMutation.executeMutation({
      code: scenario.value.code,
    })

    if (result.error) {
      customError.value = graphQLErrorMessageFor(result.error, {
        fallback: talk.custom.deleteError,
      })
      return
    }

    await refresh()
    await router.push('/talk')
  } finally {
    deletingCustom.value = false
  }
}

// Leaving the screen must not leave the microphone open.
onBeforeUnmount(() => {
  void leave()
})

const ended = computed(() => status.value === 'ended')

const notFound = computed(() => !fetching.value && !queryError.value && !scenario.value)
</script>

<template>
  <section class="mx-auto flex max-w-2xl flex-col">
    <p v-if="fetching && !scenario" class="flex items-center gap-3 py-16 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ talk.loading }}
    </p>

    <div v-else-if="queryError && !scenario" class="space-y-5 py-10">
      <UiNotice :message="graphQLErrorMessageFor(queryError, { fallback: talk.loadError })">
        <template #actions>
          <UiButton variant="ghost" size="sm" :loading="retrying" @click="retry">
            {{ talk.retry }}
          </UiButton>
        </template>
      </UiNotice>

      <UiButtonLink to="/talk" variant="quiet" size="lg">{{ talk.picker }}</UiButtonLink>
    </div>

    <div v-else-if="notFound" class="space-y-5 py-10">
      <PageHeader :title="talk.notFound" :lead="talk.notFoundBody" />
      <UiButtonLink to="/talk" variant="primary" size="lg">{{ talk.picker }}</UiButtonLink>
    </div>

    <template v-else-if="scenario">
      <!-- Scene identity: role and goal first, because that is what the learner
           plays. The mode hint names the one behavioural difference from a
           lesson (no correction during the conversation). -->
      <header class="mb-6 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <NuxtLink
            to="/talk"
            class="inline-flex items-center gap-1.5 text-[0.8125rem] text-[var(--ink-faint)] transition-colors hover:text-[var(--ink)]"
          >
            <span aria-hidden="true">←</span>
            {{ talk.picker }}
          </NuxtLink>

          <div
            v-if="scenario.isCustom && status === 'idle' && !editingCustom"
            class="flex items-center gap-2"
          >
            <UiButton
              variant="quiet"
              size="sm"
              data-testid="edit-custom-scenario-button"
              @click="editingCustom = true"
            >
              {{ talk.custom.editBuilder }}
            </UiButton>
            <UiButton
              variant="ghost"
              size="sm"
              :loading="deletingCustom"
              data-testid="delete-custom-scenario-button"
              @click="removeCustom"
            >
              {{ deletingCustom ? talk.custom.deleting : talk.custom.deleteCta }}
            </UiButton>
          </div>
        </div>

        <UiNotice v-if="customError" :message="customError" />

        <CustomScenarioBuilder
          v-if="editingCustom && scenario.isCustom"
          :initial-scenario="scenario"
          @saved="handleCustomSaved"
          @cancel="editingCustom = false"
        />

        <template v-else>
          <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
              <p class="label">{{ talk.title }}</p>
              <span
                v-if="scenario.isCustom"
                class="rounded-full border border-[var(--accent)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.06em] text-[var(--accent)]"
              >
                {{ talk.custom.badge }}
              </span>
              <span class="tnum rounded-full border border-[var(--line)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.08em] text-[var(--ink-muted)]">
                {{ scenario.level }}
              </span>
              <span class="text-[0.6875rem] font-semibold tracking-[0.08em] text-[var(--ink-faint)]">
                {{ scenarioRegisterLabel(scenario.register) }}
              </span>
            </div>

            <h1 class="display text-[1.5rem] leading-snug tracking-[0.01em] sm:text-[1.75rem]">
              {{ scenario.title }}
            </h1>
            <p class="max-w-[46ch] text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
              {{ scenario.summary }}
            </p>
          </div>

          <dl class="grid gap-4 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:grid-cols-2">
            <div class="space-y-1 sm:col-span-2">
              <dt class="label">{{ talk.setting }}</dt>
              <dd class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ scenario.setting }}</dd>
            </div>

            <div class="space-y-1">
              <dt class="label">{{ talk.yourRole }}</dt>
              <dd class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ scenario.learnerRole }}</dd>
            </div>

            <div class="space-y-1">
              <dt class="label">{{ talk.yourGoal }}</dt>
              <dd class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ scenario.learnerGoal }}</dd>
            </div>
          </dl>

          <p class="text-[0.8125rem] text-[var(--ink-faint)]">{{ talk.modeHint }}</p>
        </template>
      </header>

      <!-- Once the session ends the stage is replaced by the outcome: leaving a
           dead orb on screen would invite a tap that does nothing. -->
      <div v-if="ended" class="space-y-6">
        <div class="space-y-3 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6">
          <p class="label">{{ lesson.endedTitle }}</p>
          <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ lesson.endedBody }}</p>
          <div class="flex flex-wrap items-center gap-3 pt-1">
            <UiButtonLink v-if="sessionId" :to="`/reflect/${sessionId}`" variant="primary" size="lg">
              {{ lesson.seeFeedback }}
            </UiButtonLink>
            <UiButton variant="quiet" size="lg" @click="begin">{{ lesson.again }}</UiButton>
          </div>
        </div>

        <UsefulPhrasesPanel :phrases="scenario.usefulPhrases" open />
      </div>

      <template v-else-if="!editingCustom">
        <div class="py-2">
          <ConversationStage
            :state="orbState"
            :energy="energy"
            :status="status"
            :elapsed-seconds="elapsedSeconds"
            :agent-present="agentPresent"
            :error="voiceError"
            @start="begin"
            @finish="finish"
          />
        </div>

        <div class="mt-10">
          <UsefulPhrasesPanel :phrases="scenario.usefulPhrases" open />
        </div>
      </template>
    </template>

    <VoiceConsentGate
      v-if="consentOpen"
      @confirmed="onConsentConfirmed"
      @cancelled="consentOpen = false"
    />

    <VoiceLimitGate
      v-if="limitOpen && limit"
      :limit="limit"
      @close="limitOpen = false"
    />

    <!-- Remote audio is attached to this element by the LiveKit room. -->
    <audio ref="audioElement" class="sr-only" autoplay playsinline />
  </section>
</template>
