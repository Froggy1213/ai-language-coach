<script setup lang="ts">
import type { CombinedError } from '@urql/vue'
import { app, forgotPassword as copy } from '~/constants/strings'

/**
 * Request a password reset link.
 *
 * Always shows the same neutral confirmation notice regardless of whether the
 * email is registered, matching the backend's non-enumerable behaviour.
 */
const { forgotPassword } = useAuth()

const email = ref('')
const pending = ref(false)
const sent = ref(false)
const error = ref<string | null>(null)

async function submit(): Promise<void> {
  const trimmedEmail = email.value.trim()
  if (!trimmedEmail) {
    return
  }

  pending.value = true
  error.value = null
  sent.value = false

  try {
    await forgotPassword(trimmedEmail)
    sent.value = true
  } catch (failure) {
    error.value = graphQLErrorMessageFor(failure as CombinedError, { fallback: copy.error })
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <section class="mx-auto flex min-h-[70dvh] max-w-sm flex-col justify-center space-y-8">
    <header class="space-y-3">
      <div class="flex items-center gap-2.5">
        <span class="size-1.5 rounded-full bg-[var(--accent)]" />
        <span class="display text-[1.0625rem] tracking-tight">{{ app.name }}</span>
      </div>
      <div class="space-y-1.5">
        <h1 class="display text-[1.75rem] leading-tight">{{ copy.title }}</h1>
        <p class="text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">{{ copy.subtitle }}</p>
      </div>
    </header>

    <form class="space-y-4" @submit.prevent="submit">
      <UiField :label="copy.email">
        <input
          v-model="email"
          type="email"
          required
          autocomplete="email"
          class="field-input"
        >
      </UiField>

      <UiNotice v-if="error" :message="error" />
      <UiNotice v-else-if="sent" :message="copy.sentNotice" tone="success" />

      <UiButton type="submit" variant="primary" size="lg" block :loading="pending">
        {{ pending ? copy.pending : copy.submit }}
      </UiButton>
    </form>

    <p class="text-[0.875rem] text-[var(--ink-muted)]">
      <NuxtLink to="/login" class="text-[var(--accent)] underline-offset-4 hover:underline">
        {{ copy.backToLogin }}
      </NuxtLink>
    </p>
  </section>
</template>
