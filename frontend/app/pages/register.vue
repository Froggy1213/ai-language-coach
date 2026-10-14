<script setup lang="ts">
import type { CombinedError } from '@urql/vue'
import { SUPPORTED_LANGUAGES, languageOptionLabel } from '~/constants/languages'
import { app, register as copy } from '~/constants/strings'

/**
 * Create an account.
 *
 * The level assessment is deliberately not part of this form: it needs a
 * microphone and a minute of the learner's voice, which is a different kind of
 * commitment from choosing a password.
 */
const { register } = useAuth()

const name = ref('')
const email = ref('')
const password = ref('')
const targetLanguage = ref('en')
const error = ref<string | null>(null)
const pending = ref(false)

async function submit(): Promise<void> {
  pending.value = true
  error.value = null

  try {
    await register(name.value, email.value, password.value, targetLanguage.value)
    await navigateTo('/level')
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
      <UiField :label="copy.name">
        <input v-model="name" type="text" required autocomplete="name" class="field-input">
      </UiField>

      <UiField :label="copy.email">
        <input v-model="email" type="email" required autocomplete="email" class="field-input">
      </UiField>

      <UiField :label="copy.password" :hint="copy.passwordHint">
        <input
          v-model="password"
          type="password"
          required
          minlength="8"
          autocomplete="new-password"
          class="field-input"
        >
      </UiField>

      <UiField :label="copy.language">
        <select v-model="targetLanguage" class="field-input">
          <option v-for="code in SUPPORTED_LANGUAGES" :key="code" :value="code">
            {{ languageOptionLabel(code) }}
          </option>
        </select>
      </UiField>

      <UiNotice :message="error" />

      <UiButton type="submit" variant="primary" size="lg" block :loading="pending">
        {{ pending ? copy.pending : copy.submit }}
      </UiButton>
    </form>

    <p class="text-[0.875rem] text-[var(--ink-muted)]">
      {{ copy.hasAccount }}
      <NuxtLink to="/login" class="text-[var(--accent)] underline-offset-4 hover:underline">
        {{ copy.toLogin }}
      </NuxtLink>
    </p>
  </section>
</template>
