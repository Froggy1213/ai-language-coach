<script setup lang="ts">
import type { CombinedError } from '@urql/vue'
import { app, login as copy } from '~/constants/strings'

/**
 * Sign in.
 *
 * The shell drops away here — no tab bar, no page header — because there is
 * nothing else to do on this screen but the form.
 */
const { login } = useAuth()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const pending = ref(false)

async function submit(): Promise<void> {
  pending.value = true
  error.value = null

  try {
    await login(email.value, password.value)
    await navigateTo('/')
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

      <UiField :label="copy.password">
        <input
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="field-input"
        >
      </UiField>

      <UiNotice :message="error" />

      <UiButton type="submit" variant="primary" size="lg" block :loading="pending">
        {{ pending ? copy.pending : copy.submit }}
      </UiButton>
    </form>

    <p class="text-[0.875rem] text-[var(--ink-muted)]">
      {{ copy.noAccount }}
      <NuxtLink to="/register" class="text-[var(--accent)] underline-offset-4 hover:underline">
        {{ copy.toRegister }}
      </NuxtLink>
    </p>
  </section>
</template>
