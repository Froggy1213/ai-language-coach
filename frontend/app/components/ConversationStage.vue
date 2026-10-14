<script setup lang="ts">
import type { OrbState } from '~/composables/useConversation'
import { formatClock } from '~/utils/format'
import { talk } from '~/constants/strings'

/**
 * The conversation stage: one orb, one state label, one control.
 *
 * This screen deliberately carries nothing else. The learner's whole job here is
 * to talk, so the orb is both the visualisation *and* the primary affordance —
 * tapping it starts the session, which is one fewer thing to find than a button
 * that duplicates it.
 */
const props = defineProps<{
  state: OrbState
  energy: number
  status: 'idle' | 'requesting' | 'connecting' | 'connected' | 'ended' | 'error'
  elapsedSeconds: number
  agentPresent: boolean
  error: string | null
}>()

const emit = defineEmits<{ start: []; finish: [] }>()

const STATE_COPY: Record<OrbState, { label: string; hint: string }> = {
  idle: { label: talk.stateIdle, hint: talk.hintListening },
  listening: { label: talk.stateListening, hint: talk.hintListening },
  speaking: { label: talk.stateSpeaking, hint: talk.hintSpeaking },
  thinking: { label: talk.stateThinking, hint: talk.hintThinking },
  error: { label: talk.stateError, hint: talk.micNote },
}

const copy = computed(() => STATE_COPY[props.state])

const sessionLive = computed(() => props.status === 'connected')
const busy = computed(() => props.status === 'requesting' || props.status === 'connecting')

const controlLabel = computed(() => {
  if (props.status === 'requesting') {
    return talk.requesting
  }

  if (props.status === 'connecting') {
    return talk.connecting
  }

  return sessionLive.value ? talk.finish : talk.start
})

const elapsedLabel = computed(() => formatClock(props.elapsedSeconds))

/** The connection line is only worth showing while there is something to say. */
const connectionNote = computed(() => {
  if (!sessionLive.value) {
    return ''
  }

  return props.agentPresent ? talk.agentHere : talk.agentWaiting
})

function onOrbActivate(): void {
  if (props.status === 'idle' || props.status === 'ended' || props.status === 'error') {
    emit('start')
  }
}
</script>

<template>
  <div class="flex flex-col items-center">
    <!-- The orb. `button` semantics because it is tappable, with the canvas
         itself hidden from assistive tech. -->
    <button
      type="button"
      class="group relative aspect-square w-[min(64vw,17rem)] max-w-full cursor-pointer rounded-full transition-transform duration-500 active:scale-[0.99] disabled:cursor-default disabled:active:scale-100"
      :disabled="busy"
      :aria-label="controlLabel"
      @click="onOrbActivate"
    >
      <ConversationOrb :state="state" :energy="energy" />
      <!-- A ring only while the room is live: it reads as "the microphone is on"
           without a second indicator competing with the orb. -->
      <span
        v-if="sessionLive"
        class="pointer-events-none absolute inset-[6%] rounded-full border border-[color-mix(in_oklab,var(--accent)_22%,transparent)]"
        aria-hidden="true"
      />
    </button>

    <div class="mt-9 flex min-h-[3.5rem] flex-col items-center gap-1.5 text-center">
      <p
        class="text-[1.0625rem] font-medium tracking-[0.02em] transition-colors"
        :class="state === 'error' ? 'text-[var(--color-clay-400)]' : 'text-[var(--ink)]'"
      >
        {{ copy.label }}
      </p>
      <p class="max-w-[34ch] text-[0.875rem] leading-relaxed text-[var(--ink-faint)]">
        {{ copy.hint }}
      </p>
    </div>

    <!-- Session telemetry: monospaced and quiet, so it never competes with the
         state label above it. -->
    <div
      v-if="sessionLive || elapsedSeconds > 0"
      class="tnum mt-1 flex items-center gap-3 text-[0.8125rem] text-[var(--ink-faint)]"
    >
      <span v-if="sessionLive" class="flex items-center gap-1.5">
        <span class="size-1.5 rounded-full bg-[var(--color-sage-400)]" />
        {{ elapsedLabel }}
      </span>
      <span v-if="connectionNote" class="text-[var(--ink-faint)]">{{ connectionNote }}</span>
    </div>

    <UiNotice v-if="error" tone="warn" class="mt-5 w-full max-w-sm text-left text-[0.875rem]">
      {{ error }}
    </UiNotice>

    <div class="mt-8 flex w-full max-w-sm flex-col items-center gap-3">
      <UiButton
        :variant="sessionLive ? 'danger' : 'primary'"
        size="lg"
        block
        :loading="busy"
        :disabled="busy"
        @click="sessionLive ? emit('finish') : emit('start')"
      >
        {{ controlLabel }}
      </UiButton>
      <slot name="actions" />
    </div>
  </div>
</template>
