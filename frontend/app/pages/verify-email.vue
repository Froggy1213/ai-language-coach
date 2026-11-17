<script setup lang="ts">
import type { CombinedError } from '@urql/vue'
import { app, verifyEmail as copy } from '~/constants/strings'

/**
 * Signed email verification link handler.
 *
 * Accessible to both guests and signed-in learners: when signed in, a verified
 * result refreshes the shared `useAuth` user so settings reflects it immediately.
 */
const route = useRoute()
const { user, verifyEmail, sendEmailVerification } = useAuth()

function readQueryParam(value: unknown): string {
  if (Array.isArray(value)) {
    return typeof value[0] === 'string' ? value[0].trim() : ''
  }
  return typeof value === 'string' ? value.trim() : ''
}

const id = readQueryParam(route.query.id)
const hash = readQueryParam(route.query.hash)
const expiresRaw = readQueryParam(route.query.expires)
const signature = readQueryParam(route.query.signature)
const expires = /^-?\d+$/.test(expiresRaw) ? Number(expiresRaw) : Number.NaN
const isValidExpires = Number.isSafeInteger(expires) && expires >= -2_147_483_648 && expires <= 2_147_483_647
const hasValidParams = Boolean(id && hash && signature && isValidExpires)

const status = ref<'verifying' | 'success' | 'error'>(hasValidParams ? 'verifying' : 'error')
const errorMessage = ref<string | null>(hasValidParams ? null : copy.invalidOrExpired)

const resending = ref(false)
const resent = ref(false)
const resendError = ref<string | null>(null)

onMounted(async () => {
  if (!hasValidParams) {
    return
  }

  try {
    await verifyEmail(id, hash, expires, signature)
    status.value = 'success'
  } catch (failure) {
    status.value = 'error'
    errorMessage.value = graphQLErrorMessageFor(failure as CombinedError, {
      fallback: copy.invalidOrExpired,
    })
  }
})

async function handleResend(): Promise<void> {
  resending.value = true
  resendError.value = null
  resent.value = false

  try {
    await sendEmailVerification()
    resent.value = true
  } catch (failure) {
    resendError.value = graphQLErrorMessageFor(failure as CombinedError, {
      fallback: copy.resendError,
    })
  } finally {
    resending.value = false
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
      <h1 class="display text-[1.75rem] leading-tight">{{ copy.title }}</h1>
    </header>

    <UiNotice v-if="status === 'verifying'" tone="info">
      <span class="flex items-center gap-3">
        <UiSpinner />
        <span>{{ copy.verifying }}</span>
      </span>
    </UiNotice>

    <div v-else-if="status === 'success'" class="space-y-4">
      <UiNotice tone="success" :message="copy.success" />

      <template v-if="user">
        <UiButtonLink to="/" variant="primary" size="lg" block>
          {{ copy.toHome }}
        </UiButtonLink>
        <p class="text-[0.875rem] text-[var(--ink-muted)]">
          <NuxtLink to="/settings/account" class="text-[var(--accent)] underline-offset-4 hover:underline">
            {{ copy.toAccountSettings }}
          </NuxtLink>
        </p>
      </template>

      <UiButtonLink v-else to="/login" variant="primary" size="lg" block>
        {{ copy.toLogin }}
      </UiButtonLink>
    </div>

    <div v-else class="space-y-4">
      <UiNotice :message="errorMessage" />
      <UiNotice v-if="resent" tone="success" :message="copy.resent" />
      <UiNotice v-else-if="resendError" :message="resendError" />

      <template v-if="user">
        <UiButton
          variant="primary"
          size="lg"
          block
          :loading="resending"
          @click="handleResend"
        >
          {{ resending ? copy.resending : copy.resendCta }}
        </UiButton>

        <div class="flex flex-wrap items-center justify-between gap-3 text-[0.875rem] text-[var(--ink-muted)]">
          <NuxtLink to="/settings/account" class="text-[var(--accent)] underline-offset-4 hover:underline">
            {{ copy.toAccountSettings }}
          </NuxtLink>
          <NuxtLink to="/" class="text-[var(--accent)] underline-offset-4 hover:underline">
            {{ copy.toHome }}
          </NuxtLink>
        </div>
      </template>

      <UiButtonLink v-else to="/login" variant="primary" size="lg" block>
        {{ copy.toLogin }}
      </UiButtonLink>
    </div>
  </section>
</template>
