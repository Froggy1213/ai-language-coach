<script setup lang="ts">
import { paywall as copy } from '~/constants/strings'
import type { VoiceLimit } from '~/composables/useVoiceSession'

/**
 * The dialog that replaces a refused session with a way to keep talking.
 *
 * It distinguishes two refusals that share a screen: the allowance is spent
 * (offer the pricing screen) and the subscription cannot be charged (offer the
 * billing portal). Both come from `VOICE_*` extensions; the component never
 * parses a server message.
 */
const props = defineProps<{ limit: VoiceLimit }>()

const emit = defineEmits<{ close: [] }>()

const inactive = computed(() => props.limit.code === 'VOICE_SUBSCRIPTION_INACTIVE')

const usedMinutes = computed(() => Math.floor((props.limit.usedSeconds ?? 0) / 60))
const includedMinutes = computed(() => Math.floor((props.limit.includedSeconds ?? 0) / 60))
const isFree = computed(() => props.limit.resetsAt === null)

const resetDate = computed(() => {
  if (!props.limit.resetsAt) {
    return null
  }

  return new Intl.DateTimeFormat('ja-JP', { month: 'long', day: 'numeric' }).format(new Date(props.limit.resetsAt))
})
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-[60] flex items-end justify-center bg-[color-mix(in_oklab,var(--canvas)_30%,black)] p-4 backdrop-blur-sm sm:items-center"
      role="presentation"
      @click.self="emit('close')"
    >
      <div
        class="w-full max-w-md space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--canvas)] p-6 shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="voice-limit-title"
      >
        <h2 id="voice-limit-title" class="display text-[1.25rem] leading-snug">
          {{ inactive ? copy.inactiveTitle : copy.title }}
        </h2>

        <p v-if="inactive" class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
          {{ copy.inactiveBody }}
        </p>

        <template v-else>
          <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
            {{ isFree ? copy.freeBody : copy.paidBody(usedMinutes, includedMinutes) }}
          </p>
          <p v-if="resetDate" class="text-[0.8125rem] text-[var(--ink-faint)]">
            {{ copy.resets(resetDate) }}
          </p>
        </template>

        <div class="flex justify-end gap-2">
          <UiButton variant="ghost" @click="emit('close')">
            {{ copy.close }}
          </UiButton>

          <UiButtonLink v-if="inactive" to="/settings" variant="primary">
            {{ copy.ctaBilling }}
          </UiButtonLink>
          <UiButtonLink v-else to="/pricing" variant="primary">
            {{ copy.ctaPlans }}
          </UiButtonLink>
        </div>
      </div>
    </div>
  </Teleport>
</template>
