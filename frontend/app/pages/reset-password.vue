<script setup lang="ts">
import type { CombinedError } from '@urql/vue'
import { app, resetPassword as copy } from '~/constants/strings'

/**
 * Complete a password reset from the emailed token link.
 */
const route = useRoute()
const { resetPassword } = useAuth()

function readQueryParam(value: unknown): string {
  if (Array.isArray(value)) {
    return typeof value[0] === 'string' ? value[0].trim() : ''
  }
  return typeof value === 'string' ? value.trim() : ''
}

const token = computed(() => readQueryParam(route.query.token))
const email = computed(() => readQueryParam(route.query.email))
const hasValidParams = computed(() => token.value.length > 0 && email.value.length > 0)

const password = ref('')
const passwordConfirmation = ref('')
const pending = ref(false)
const error = ref<string | null>(null)

async function submit(): Promise<void> {
  if (!hasValidParams.value) {
    return
  }

  error.value = null

  if (password.value !== passwordConfirmation.value) {
    error.value = copy.passwordMismatch
    return
  }

  pending.value = true

  try {
    await resetPassword(token.value, email.value, password.value)
    await navigateTo('/login?reset=success')
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

    <div v-if="!hasValidParams" class="space-y-4">
      <UiNotice :message="copy.invalidLink" />
      <UiButtonLink to="/forgot-password" variant="quiet" size="lg" block>
        {{ copy.requestNewLink }}
      </UiButtonLink>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <UiField :label="copy.newPassword" :hint="copy.passwordHint">
        <input
          v-model="password"
          type="password"
          required
          minlength="8"
          autocomplete="new-password"
          class="field-input"
        >
      </UiField>

      <UiField :label="copy.confirmPassword">
        <input
          v-model="passwordConfirmation"
          type="password"
          required
          minlength="8"
          autocomplete="new-password"
          class="field-input"
        >
      </UiField>

      <UiNotice :message="error" />

      <UiButton
        type="submit"
        variant="primary"
        size="lg"
        block
        :loading="pending"
        :disabled="password.length < 8 || !passwordConfirmation"
      >
        {{ pending ? copy.pending : copy.submit }}
      </UiButton>

      <p class="text-[0.875rem] text-[var(--ink-muted)]">
        <NuxtLink to="/forgot-password" class="text-[var(--accent)] underline-offset-4 hover:underline">
          {{ copy.requestNewLink }}
        </NuxtLink>
      </p>
    </form>
  </section>
</template>
