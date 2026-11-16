<script setup lang="ts">
import { settings as copy, voiceConsent as consentCopy } from '~/constants/strings'
import { formatDate } from '~/utils/format'

/**
 * 設定 → データとプライバシー.
 *
 * Two things a learner can do about their data: see the consent they gave (and
 * give it if they have not), and delete the account. Both are separated from the
 * rest of settings because the second is irreversible — burying it under a theme
 * switch is how a destructive action gets clicked by accident.
 *
 * The consent flag lives on the shared `useAuth` user: `giveConsent` refreshes
 * it, so no page-level `me` query is needed to see the change.
 */
const { user } = useAuth()
useHashScroll()

const {
  submitting: consentingVoice,
  error: voiceConsentError,
  giveConsent: grantVoiceConsent,
} = useVoiceConsent()

const { deleting, error: deleteError, deleteAccount } = usePrivacy()

const password = ref('')
const confirmation = ref('')
const consentStatus = ref<HTMLElement | null>(null)

/**
 * The grant button disappears on success, so focus would otherwise fall back to
 * `<body>` and the new state would be silent to a screen reader. Move focus to
 * the (now updated) status line, which is also a live region.
 */
async function handleVoiceConsent(): Promise<void> {
  const granted = await grantVoiceConsent()

  if (!granted) {
    return
  }

  await nextTick()
  consentStatus.value?.focus()
}

async function handleDelete(): Promise<void> {
  if (!password.value || !confirmation.value) {
    return
  }

  await deleteAccount(password.value, confirmation.value)
}
</script>

<template>
  <SettingsPage v-if="user" :title="copy.privacyTitle" :lead="copy.privacyLead">
    <section class="space-y-3">
      <h2 class="label">{{ copy.consentSection }}</h2>

      <div class="space-y-4 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5">
        <div
          ref="consentStatus"
          role="status"
          tabindex="-1"
          class="flex flex-wrap items-baseline gap-x-3 gap-y-1"
        >
          <span v-if="user.voiceConsentAt" class="inline-flex items-center gap-2 text-[0.9375rem]">
            <span class="size-1.5 rounded-full bg-[var(--color-sage-400)]" />
            {{ copy.voiceConsentYes }}
          </span>
          <span v-else class="text-[0.9375rem] text-[var(--ink-faint)]">{{ copy.voiceConsentNo }}</span>

          <span v-if="user.voiceConsentAt" class="text-[0.75rem] text-[var(--ink-faint)]">
            {{ copy.consentDate(formatDate(user.voiceConsentAt)) }}
          </span>
        </div>

        <p class="text-[0.875rem] leading-[1.8] text-[var(--ink-muted)]">{{ copy.consentBody }}</p>

        <ul class="space-y-1.5 text-[0.8125rem] leading-relaxed text-[var(--ink-faint)]">
          <li v-for="point in consentCopy.points" :key="point" class="flex gap-2.5">
            <span aria-hidden="true" class="mt-[0.55em] size-1 shrink-0 rounded-full bg-current" />
            <span>{{ point }}</span>
          </li>
        </ul>

        <p v-if="!user.voiceConsentAt" class="text-[0.8125rem] leading-relaxed text-[var(--ink-faint)]">
          {{ copy.consentMissingBody }}
        </p>

        <UiNotice v-if="voiceConsentError" :message="voiceConsentError" />

        <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
          <UiButton
            v-if="!user.voiceConsentAt"
            variant="primary"
            :loading="consentingVoice"
            data-testid="settings-grant-consent"
            @click="handleVoiceConsent"
          >
            {{ consentingVoice ? copy.grantingConsent : copy.grantConsent }}
          </UiButton>

          <NuxtLink
            to="/privacy"
            class="text-[0.8125rem] text-[var(--accent)] underline-offset-4 hover:underline"
          >
            {{ copy.privacyPolicyLink }}
          </NuxtLink>
        </div>
      </div>
    </section>

    <section id="delete" class="scroll-mt-24 space-y-4">
      <h2 class="label text-[var(--color-clay-400)]">{{ copy.danger }}</h2>

      <div class="space-y-5 rounded-2xl border border-[color-mix(in_oklab,var(--color-clay-400)_30%,transparent)] bg-[color-mix(in_oklab,var(--color-clay-500)_7%,transparent)] p-5">
        <p class="text-[0.875rem] leading-[1.8] text-[var(--ink-muted)]">{{ copy.dangerBody }}</p>

        <UiNotice v-if="deleteError" :message="deleteError" />

        <form class="space-y-4" @submit.prevent="handleDelete">
          <label class="block space-y-1.5">
            <span class="text-[0.875rem] text-[var(--ink-muted)]">{{ copy.currentPassword }}</span>
            <input
              v-model="password"
              type="password"
              required
              autocomplete="current-password"
              :placeholder="copy.passwordPlaceholder"
              class="field-input"
            >
          </label>

          <label class="block space-y-1.5">
            <span class="text-[0.875rem] text-[var(--ink-muted)]">
              {{ copy.confirmLabel }} <strong class="font-mono text-[var(--color-clay-400)]">DELETE</strong> {{ copy.confirmSuffix }}
            </span>
            <input
              v-model="confirmation"
              type="text"
              required
              placeholder="DELETE"
              class="field-input font-mono focus:border-[var(--color-clay-400)]"
            >
          </label>

          <UiButton
            type="submit"
            variant="danger"
            :loading="deleting"
            :disabled="!password || confirmation !== 'DELETE'"
          >
            {{ deleting ? copy.deleting : copy.deleteCta }}
          </UiButton>
        </form>
      </div>
    </section>
  </SettingsPage>
</template>
