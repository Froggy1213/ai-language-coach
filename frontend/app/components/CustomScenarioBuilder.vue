<script setup lang="ts">
import { useMutation } from '@urql/vue'
import { computed, reactive, ref, watch } from 'vue'
import {
  CONFIRM_CUSTOM_SCENARIO_MUTATION,
  DRAFT_CUSTOM_SCENARIO_MUTATION,
} from '~/graphql/documents'
import type { CefrLevel, Scenario } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { talk } from '~/constants/strings'

const props = withDefaults(
  defineProps<{
    initialScenario?: Scenario | null
    defaultLevel?: CefrLevel
  }>(),
  {
    initialScenario: null,
    defaultLevel: 'B1',
  },
)

const emit = defineEmits<{
  saved: [scenario: Scenario, startImmediately: boolean]
  cancel: []
}>()

const levels: readonly CefrLevel[] = [
  'A1',
  'A2',
  'B1',
  'B2',
  'C1',
]

const registers = [
  { value: 'neutral', label: talk.register.neutral },
  { value: 'formal', label: talk.register.formal },
  { value: 'casual', label: talk.register.casual },
] as const

const step = ref<'prompt' | 'confirm'>(props.initialScenario ? 'confirm' : 'prompt')
const promptText = ref(props.initialScenario?.userPrompt ?? '')
const selectedRegister = ref<string>(props.initialScenario?.register ?? 'neutral')
const selectedLevel = ref<CefrLevel>(props.initialScenario?.level ?? props.defaultLevel)
const errorMessage = ref<string | null>(null)

const form = reactive({
  code: props.initialScenario?.code ?? '',
  title: props.initialScenario?.title ?? '',
  summary: props.initialScenario?.summary ?? '',
  level: (props.initialScenario?.level ?? props.defaultLevel) as CefrLevel,
  register: props.initialScenario?.register ?? 'neutral',
  setting: props.initialScenario?.setting ?? '',
  aiRole: props.initialScenario?.aiRole ?? '',
  learnerRole: props.initialScenario?.learnerRole ?? '',
  learnerGoal: props.initialScenario?.learnerGoal ?? '',
  usefulPhrases: props.initialScenario?.usefulPhrases?.length
    ? [...props.initialScenario.usefulPhrases]
    : ['', '', ''],
})

function applyDraft(scenario: Scenario): void {
  form.code = scenario.code
  form.title = scenario.title
  form.summary = scenario.summary
  form.level = scenario.level
  form.register = scenario.register
  form.setting = scenario.setting
  form.aiRole = scenario.aiRole
  form.learnerRole = scenario.learnerRole
  form.learnerGoal = scenario.learnerGoal
  form.usefulPhrases = scenario.usefulPhrases.length >= 3
    ? [...scenario.usefulPhrases]
    : [...scenario.usefulPhrases, '', '', ''].slice(0, 3)

  if (scenario.userPrompt) {
    promptText.value = scenario.userPrompt
  }
  selectedLevel.value = scenario.level
  selectedRegister.value = scenario.register
}

watch(
  () => props.initialScenario,
  (next) => {
    if (next) {
      applyDraft(next)
      step.value = 'confirm'
    }
  },
)

const draftMutation = useMutation(DRAFT_CUSTOM_SCENARIO_MUTATION)
const confirmMutation = useMutation(CONFIRM_CUSTOM_SCENARIO_MUTATION)

const drafting = ref(false)
const confirming = ref(false)

const canDraft = computed(() => promptText.value.trim().length >= 3 && !drafting.value && !confirming.value)

const canConfirm = computed(() => {
  if (confirming.value || drafting.value) {
    return false
  }

  const requiredFields = [
    form.code,
    form.title,
    form.summary,
    form.setting,
    form.aiRole,
    form.learnerRole,
    form.learnerGoal,
  ]

  if (requiredFields.some((val) => val.trim() === '')) {
    return false
  }

  const nonEmptyPhrases = form.usefulPhrases.filter((phrase) => phrase.trim() !== '')
  return nonEmptyPhrases.length >= 3 && nonEmptyPhrases.length <= 5
})

async function requestDraft(): Promise<void> {
  if (!canDraft.value) {
    return
  }

  drafting.value = true
  errorMessage.value = null

  try {
    const result = await draftMutation.executeMutation({
      prompt: promptText.value.trim(),
      register: selectedRegister.value,
      level: selectedLevel.value,
      code: form.code || null,
    })

    if (result.error) {
      errorMessage.value = graphQLErrorMessageFor(result.error, {
        fallback: talk.custom.draftError,
      })
      return
    }

    const drafted = result.data?.draftCustomScenario as Scenario | undefined
    if (!drafted) {
      errorMessage.value = talk.custom.draftError
      return
    }

    applyDraft(drafted)
    step.value = 'confirm'
  } finally {
    drafting.value = false
  }
}

function addPhrase(): void {
  if (form.usefulPhrases.length < 5) {
    form.usefulPhrases.push('')
  }
}

function removePhrase(index: number): void {
  if (form.usefulPhrases.length > 3) {
    form.usefulPhrases.splice(index, 1)
  }
}

async function submitConfirm(startImmediately: boolean): Promise<void> {
  if (!canConfirm.value) {
    return
  }

  confirming.value = true
  errorMessage.value = null

  try {
    const cleanPhrases = form.usefulPhrases
      .map((phrase) => phrase.trim())
      .filter((phrase) => phrase !== '')
      .slice(0, 5)

    const result = await confirmMutation.executeMutation({
      input: {
        code: form.code,
        title: form.title.trim(),
        summary: form.summary.trim(),
        level: form.level,
        register: form.register,
        setting: form.setting.trim(),
        aiRole: form.aiRole.trim(),
        learnerRole: form.learnerRole.trim(),
        learnerGoal: form.learnerGoal.trim(),
        usefulPhrases: cleanPhrases,
      },
    })

    if (result.error) {
      errorMessage.value = graphQLErrorMessageFor(result.error, {
        fallback: talk.custom.confirmError,
      })
      return
    }

    const confirmed = result.data?.confirmCustomScenario as Scenario | undefined
    if (!confirmed) {
      errorMessage.value = talk.custom.confirmError
      return
    }

    emit('saved', confirmed, startImmediately)
  } finally {
    confirming.value = false
  }
}
</script>

<template>
  <div
    data-testid="custom-scenario-builder"
    class="space-y-6 rounded-3xl border border-[var(--line-strong)] bg-[var(--surface)] p-6 sm:p-7"
  >
    <UiNotice v-if="errorMessage" :message="errorMessage" />

    <!-- Step 1: Short prompt input for AI refinement -->
    <form
      v-if="step === 'prompt'"
      class="space-y-5"
      @submit.prevent="requestDraft"
    >
      <div class="space-y-1.5">
        <p class="label">{{ talk.custom.stepPromptLabel }}</p>
        <h2 class="display text-[1.25rem] leading-snug tracking-[0.01em] sm:text-[1.375rem]">
          {{ talk.custom.stepPromptTitle }}
        </h2>
        <p class="text-[0.875rem] leading-relaxed text-[var(--ink-muted)]">
          {{ talk.custom.stepPromptHint }}
        </p>
      </div>

      <div class="space-y-2">
        <label for="custom-scenario-prompt" class="label block">
          {{ talk.custom.promptLabel }}
        </label>
        <textarea
          id="custom-scenario-prompt"
          v-model="promptText"
          data-testid="custom-scenario-prompt-input"
          rows="3"
          maxlength="500"
          :placeholder="talk.custom.promptPlaceholder"
          class="w-full rounded-2xl border border-[var(--line)] bg-[var(--bg)] px-4 py-3 text-[0.9375rem] leading-relaxed text-[var(--ink)] placeholder:text-[var(--ink-faint)] focus:border-[var(--accent)] focus:outline-none"
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-2">
          <label for="custom-scenario-register" class="label block">
            {{ talk.custom.toneLabel }}
          </label>
          <select
            id="custom-scenario-register"
            v-model="selectedRegister"
            data-testid="custom-scenario-register-select"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          >
            <option v-for="reg in registers" :key="reg.value" :value="reg.value">
              {{ reg.label }}
            </option>
          </select>
        </div>

        <div class="space-y-2">
          <label for="custom-scenario-level" class="label block">
            {{ talk.custom.levelLabel }}
          </label>
          <select
            id="custom-scenario-level"
            v-model="selectedLevel"
            data-testid="custom-scenario-level-select"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          >
            <option v-for="lvl in levels" :key="lvl" :value="lvl">
              {{ lvl }}
            </option>
          </select>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-3 pt-1">
        <UiButton
          type="submit"
          variant="primary"
          size="lg"
          :loading="drafting"
          :disabled="!canDraft"
          data-testid="custom-scenario-draft-submit"
        >
          {{ drafting ? talk.custom.drafting : talk.custom.draftCta }}
        </UiButton>

        <UiButton
          type="button"
          variant="ghost"
          size="lg"
          :disabled="drafting"
          @click="emit('cancel')"
        >
          {{ talk.custom.cancel }}
        </UiButton>
      </div>
    </form>

    <!-- Step 2: Review AI-refined prompt & confirm -->
    <div v-else class="space-y-6" data-testid="custom-scenario-confirm-step">
      <div class="space-y-2">
        <p class="label text-[var(--accent)]">{{ talk.custom.stepConfirmLabel }}</p>
        <h2 class="display text-[1.25rem] leading-snug tracking-[0.01em] sm:text-[1.375rem]">
          {{ talk.custom.stepConfirmTitle }}
        </h2>
        <p class="text-[0.875rem] leading-relaxed text-[var(--ink-muted)]">
          {{ talk.custom.stepConfirmLead }}
        </p>
      </div>

      <!-- Prompt bar with one-click AI regeneration -->
      <div class="space-y-3 rounded-2xl border border-[var(--line)] bg-[var(--bg)] p-4">
        <label for="custom-scenario-regenerate-prompt" class="label block">
          {{ talk.custom.promptLabel }}
        </label>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
          <input
            id="custom-scenario-regenerate-prompt"
            v-model="promptText"
            type="text"
            maxlength="500"
            class="min-w-0 flex-1 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 py-2 text-[0.875rem] text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          >
          <UiButton
            type="button"
            variant="quiet"
            size="sm"
            :loading="drafting"
            :disabled="!canDraft"
            data-testid="custom-scenario-regenerate-button"
            @click="requestDraft"
          >
            {{ drafting ? talk.custom.regenerating : talk.custom.regenerateCta }}
          </UiButton>
        </div>
      </div>

      <!-- Editable visible fields of the AI-refined scenario -->
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1.5 sm:col-span-2">
          <label for="confirm-title" class="label block">{{ talk.custom.fieldTitle }}</label>
          <input
            id="confirm-title"
            v-model="form.title"
            data-testid="confirm-title-input"
            type="text"
            maxlength="140"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          >
        </div>

        <div class="space-y-1.5 sm:col-span-2">
          <label for="confirm-summary" class="label block">{{ talk.custom.fieldSummary }}</label>
          <textarea
            id="confirm-summary"
            v-model="form.summary"
            data-testid="confirm-summary-input"
            rows="2"
            maxlength="260"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] leading-relaxed text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          />
        </div>

        <div class="space-y-1.5">
          <label for="confirm-register" class="label block">{{ talk.custom.toneLabel }}</label>
          <select
            id="confirm-register"
            v-model="form.register"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          >
            <option v-for="reg in registers" :key="reg.value" :value="reg.value">
              {{ reg.label }}
            </option>
          </select>
        </div>

        <div class="space-y-1.5">
          <label for="confirm-level" class="label block">{{ talk.custom.levelLabel }}</label>
          <select
            id="confirm-level"
            v-model="form.level"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          >
            <option v-for="lvl in levels" :key="lvl" :value="lvl">
              {{ lvl }}
            </option>
          </select>
        </div>

        <div class="space-y-1.5 sm:col-span-2">
          <label for="confirm-setting" class="label block">{{ talk.custom.fieldSetting }}</label>
          <textarea
            id="confirm-setting"
            v-model="form.setting"
            data-testid="confirm-setting-input"
            rows="2"
            maxlength="260"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] leading-relaxed text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          />
        </div>

        <div class="space-y-1.5">
          <label for="confirm-ai-role" class="label block">{{ talk.custom.fieldAiRole }}</label>
          <textarea
            id="confirm-ai-role"
            v-model="form.aiRole"
            data-testid="confirm-ai-role-input"
            rows="2"
            maxlength="200"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] leading-relaxed text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          />
        </div>

        <div class="space-y-1.5">
          <label for="confirm-learner-role" class="label block">{{ talk.custom.fieldLearnerRole }}</label>
          <textarea
            id="confirm-learner-role"
            v-model="form.learnerRole"
            data-testid="confirm-learner-role-input"
            rows="2"
            maxlength="200"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] leading-relaxed text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          />
        </div>

        <div class="space-y-1.5 sm:col-span-2">
          <label for="confirm-learner-goal" class="label block">{{ talk.custom.fieldLearnerGoal }}</label>
          <textarea
            id="confirm-learner-goal"
            v-model="form.learnerGoal"
            data-testid="confirm-learner-goal-input"
            rows="2"
            maxlength="220"
            class="w-full rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2.5 text-[0.9375rem] leading-relaxed text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
          />
        </div>

        <div class="space-y-2.5 sm:col-span-2">
          <div class="flex items-center justify-between gap-2">
            <span class="label">{{ talk.custom.fieldPhrases }}</span>
            <button
              v-if="form.usefulPhrases.length < 5"
              type="button"
              class="text-[0.8125rem] font-medium text-[var(--accent)] hover:underline"
              @click="addPhrase"
            >
              + {{ talk.custom.addPhrase }}
            </button>
          </div>

          <div class="space-y-2">
            <div
              v-for="(_, index) in form.usefulPhrases"
              :key="index"
              class="flex items-center gap-2"
            >
              <input
                v-model="form.usefulPhrases[index]"
                :data-testid="`confirm-phrase-input-${index}`"
                type="text"
                maxlength="160"
                class="min-w-0 flex-1 rounded-xl border border-[var(--line)] bg-[var(--bg)] px-3.5 py-2 text-[0.9375rem] text-[var(--ink)] focus:border-[var(--accent)] focus:outline-none"
              >
              <button
                v-if="form.usefulPhrases.length > 3"
                type="button"
                class="shrink-0 rounded-lg px-2 py-1 text-[0.75rem] text-[var(--ink-faint)] hover:text-[var(--ink)]"
                @click="removePhrase(index)"
              >
                {{ talk.custom.removePhrase }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-3 pt-2">
        <UiButton
          type="button"
          variant="primary"
          size="lg"
          :loading="confirming"
          :disabled="!canConfirm"
          data-testid="custom-scenario-confirm-start"
          @click="submitConfirm(true)"
        >
          {{ confirming ? talk.custom.confirming : talk.custom.confirmAndStart }}
        </UiButton>

        <UiButton
          type="button"
          variant="quiet"
          size="lg"
          :loading="confirming"
          :disabled="!canConfirm"
          data-testid="custom-scenario-confirm-save"
          @click="submitConfirm(false)"
        >
          {{ talk.custom.confirmAndSave }}
        </UiButton>

        <UiButton
          type="button"
          variant="ghost"
          size="lg"
          :disabled="confirming || drafting"
          @click="emit('cancel')"
        >
          {{ talk.custom.cancel }}
        </UiButton>
      </div>
    </div>
  </div>
</template>
