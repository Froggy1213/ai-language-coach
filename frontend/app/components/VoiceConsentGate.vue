<script setup lang="ts">
import { ref } from 'vue'
import { voiceConsent as copy } from '~/constants/strings'

/**
 * The explicit consent dialog before the first voice session.
 *
 * It is a real dialog rather than a checkbox folded into the start button: the
 * learner is agreeing to have their speech processed, and that decision needs
 * its own moment. Confirming calls the mutation; the parent starts the session
 * only after `confirmed`.
 */
const emit = defineEmits<{ confirmed: []; cancelled: [] }>()

const { submitting, error, giveConsent } = useVoiceConsent()
const accepted = ref(false)

async function handleConfirm(): Promise<void> {
  if (!accepted.value) {
    return
  }

  if (await giveConsent()) {
    emit('confirmed')
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-[60] flex items-end justify-center bg-[color-mix(in_oklab,var(--canvas)_30%,black)] p-4 backdrop-blur-sm sm:items-center"
      role="presentation"
      @click.self="emit('cancelled')"
    >
      <div
        class="w-full max-w-md space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--canvas)] p-6 shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="voice-consent-title"
      >
        <h2 id="voice-consent-title" class="display text-[1.25rem] leading-snug">
          {{ copy.title }}
        </h2>

        <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ copy.intro }}</p>

        <ul class="space-y-2.5">
          <li
            v-for="point in copy.points"
            :key="point"
            class="flex gap-3 text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]"
          >
            <span class="mt-2.5 size-1 shrink-0 rounded-full bg-[var(--ink-faint)]" aria-hidden="true" />
            {{ point }}
          </li>
        </ul>

        <label class="flex cursor-pointer items-start gap-3 text-[0.9375rem] leading-[1.7]">
          <input
            v-model="accepted"
            type="checkbox"
            class="mt-1 size-4 shrink-0 accent-[var(--accent)]"
          >
          <span>{{ copy.label }}</span>
        </label>

        <UiNotice v-if="error" :message="error" />

        <div class="flex justify-end gap-2">
          <UiButton variant="ghost" @click="emit('cancelled')">
            {{ copy.cancel }}
          </UiButton>
          <UiButton
            variant="primary"
            :disabled="!accepted"
            :loading="submitting"
            @click="handleConfirm"
          >
            {{ copy.cta }}
          </UiButton>
        </div>
      </div>
    </div>
  </Teleport>
</template>
