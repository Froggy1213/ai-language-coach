<script setup lang="ts">
import type { CombinedError } from '@urql/vue'
import { languageDisplayName } from '~/constants/languages'
import { settings as copy } from '~/constants/strings'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'

/**
 * 設定 → アカウント.
 *
 * Everything that changes the account itself: the two editable profile fields,
 * the two facts that are read-only by design (language is fixed at sign-up, the
 * level comes from an assessment), and the password.
 *
 * The old screen printed the name and email in a read-only list and then asked
 * for them again in a form directly below. The form is the truthful control, so
 * it is the only copy of those values here.
 *
 * State is shared through `useAuth`; the route guard resolves it before mount and
 * `updateProfile` refreshes it, so this page owns only the form's local state.
 */
const { user, updateProfile, updatePassword, sendEmailVerification } = useAuth()
useHashScroll()

const profileName = ref('')
const profileEmail = ref('')
const profileSaving = ref(false)
const profileError = ref<string | null>(null)
const profileSuccess = ref<string | null>(null)
const verificationSending = ref(false)
const verificationSent = ref(false)
const verificationError = ref<string | null>(null)

watch(
  user,
  (value) => {
    if (!value) {
      return
    }
    profileName.value = value.name
    profileEmail.value = value.email
  },
  { immediate: true },
)

watch(
  () => [user.value?.email, user.value?.emailVerifiedAt],
  () => {
    verificationSent.value = false
    verificationError.value = null
  },
)

const profileDirty = computed(() => {
  if (!user.value) {
    return false
  }
  return (
    profileName.value.trim() !== user.value.name
    || profileEmail.value.trim() !== user.value.email
  )
})

const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirmation = ref('')
const passwordSaving = ref(false)
const passwordError = ref<string | null>(null)
const passwordSuccess = ref<string | null>(null)

async function handleProfileSave(): Promise<void> {
  const trimmedName = profileName.value.trim()
  const trimmedEmail = profileEmail.value.trim()
  if (!trimmedName || !trimmedEmail) {
    return
  }

  const emailChanged = trimmedEmail !== user.value?.email
  profileSaving.value = true
  profileError.value = null
  profileSuccess.value = null
  if (emailChanged) {
    verificationSent.value = false
    verificationError.value = null
  }

  try {
    await updateProfile(trimmedName, trimmedEmail)
    profileSuccess.value = copy.profileSaved
  } catch (failure) {
    profileError.value = graphQLErrorMessageFor(failure as CombinedError, {
      fallback: copy.profileError,
    })
  } finally {
    profileSaving.value = false
  }
}

async function handleResendVerification(): Promise<void> {
  verificationSending.value = true
  verificationError.value = null
  verificationSent.value = false

  try {
    await sendEmailVerification()
    verificationSent.value = true
  } catch (failure) {
    verificationError.value = graphQLErrorMessageFor(failure as CombinedError, {
      fallback: copy.verificationSendError,
    })
  } finally {
    verificationSending.value = false
  }
}

async function handlePasswordUpdate(): Promise<void> {
  if (!currentPassword.value || !newPassword.value || !newPasswordConfirmation.value) {
    return
  }

  passwordError.value = null
  passwordSuccess.value = null

  if (newPassword.value !== newPasswordConfirmation.value) {
    passwordError.value = copy.passwordMismatchConfirm
    return
  }

  passwordSaving.value = true

  try {
    await updatePassword(currentPassword.value, newPassword.value)
    currentPassword.value = ''
    newPassword.value = ''
    newPasswordConfirmation.value = ''
    passwordSuccess.value = copy.passwordUpdated
  } catch (failure) {
    passwordError.value = graphQLErrorMessageFor(failure as CombinedError, {
      fallback: copy.passwordUpdateError,
    })
  } finally {
    passwordSaving.value = false
  }
}
</script>

<template>
  <SettingsPage v-if="user" :title="copy.accountTitle" :lead="copy.accountLead">
    <section class="space-y-3">
      <h2 class="label">{{ copy.profileSection }}</h2>

      <form
        class="space-y-4 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"
        data-testid="settings-profile-form"
        @submit.prevent="handleProfileSave"
      >
        <UiField :label="copy.nameLabel" :hint="copy.nameHint">
          <input
            v-model="profileName"
            type="text"
            required
            maxlength="255"
            autocomplete="name"
            class="field-input"
            data-testid="settings-profile-name"
          >
        </UiField>

        <UiField :label="copy.email" :hint="copy.emailHint">
          <input
            v-model="profileEmail"
            type="email"
            required
            maxlength="255"
            autocomplete="email"
            class="field-input"
            data-testid="settings-profile-email"
          >
        </UiField>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <span
            v-if="user.emailVerifiedAt"
            class="inline-flex items-center gap-1.5 rounded-full border border-[color-mix(in_oklab,var(--color-sage-400)_36%,transparent)] bg-[color-mix(in_oklab,var(--color-sage-500)_12%,transparent)] px-2.5 py-0.5 text-[0.75rem] font-medium text-[var(--color-sage-400)]"
            data-testid="settings-email-verified-badge"
          >
            <span aria-hidden="true" class="size-1.5 rounded-full bg-[var(--color-sage-400)]" />
            {{ copy.emailVerified }}
          </span>
          <span
            v-else
            class="inline-flex items-center gap-1.5 rounded-full border border-[color-mix(in_oklab,var(--color-amber-bright)_36%,transparent)] bg-[color-mix(in_oklab,var(--color-amber-bright)_12%,transparent)] px-2.5 py-0.5 text-[0.75rem] font-medium text-[var(--color-amber-bright)]"
            data-testid="settings-email-unverified-badge"
          >
            <span aria-hidden="true" class="size-1.5 rounded-full bg-[var(--color-amber-bright)]" />
            {{ copy.emailUnverified }}
          </span>

          <UiButton
            v-if="!user.emailVerifiedAt"
            type="button"
            variant="quiet"
            size="sm"
            :loading="verificationSending"
            data-testid="settings-resend-verification"
            @click="handleResendVerification"
          >
            {{ verificationSending ? copy.resendingVerification : copy.resendVerification }}
          </UiButton>
        </div>

        <UiNotice v-if="!user.emailVerifiedAt && verificationError" :message="verificationError" />
        <UiNotice v-else-if="!user.emailVerifiedAt && verificationSent" :message="copy.verificationSent" tone="success" />

        <UiNotice v-if="profileError" :message="profileError" />
        <UiNotice v-else-if="profileSuccess" :message="profileSuccess" tone="success" />

        <UiButton
          type="submit"
          variant="primary"
          :loading="profileSaving"
          :disabled="!profileDirty || !profileName.trim() || !profileEmail.trim()"
          data-testid="settings-profile-submit"
        >
          {{ profileSaving ? copy.savingProfile : copy.saveProfile }}
        </UiButton>
      </form>
    </section>

    <!-- What cannot be edited, and why. Each hint sits under its own label so
         the reason travels with the fact instead of floating below the card. -->
    <section class="space-y-3">
      <h2 class="label">{{ copy.learningSection }}</h2>

      <dl class="divide-y divide-[var(--line)] rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-4">
        <div class="flex items-start justify-between gap-6 py-3.5">
          <dt class="min-w-0">
            <span class="block text-[0.875rem] text-[var(--ink-muted)]">{{ copy.language }}</span>
            <span class="mt-0.5 block text-[0.75rem] leading-relaxed text-[var(--ink-faint)]">
              {{ copy.languageHint }}
            </span>
          </dt>
          <dd class="shrink-0 text-[0.9375rem]">
            {{ languageDisplayName(user.targetLanguage) }}
          </dd>
        </div>

        <div class="flex items-start justify-between gap-6 py-3.5">
          <dt class="min-w-0">
            <span class="block text-[0.875rem] text-[var(--ink-muted)]">{{ copy.levelLabel }}</span>
            <span class="mt-0.5 block text-[0.75rem] leading-relaxed text-[var(--ink-faint)]">
              {{ copy.levelHint }}
            </span>
          </dt>
          <dd class="flex shrink-0 items-center gap-3 text-[0.9375rem]">
            <span class="tnum">{{ user.currentLevel }}</span>
            <NuxtLink
              to="/level"
              class="text-[0.8125rem] text-[var(--accent)] underline-offset-4 hover:underline"
            >
              {{ copy.retakeLevel }}
            </NuxtLink>
          </dd>
        </div>
      </dl>
    </section>

    <section id="password" class="scroll-mt-24 space-y-3">
      <h2 class="label">{{ copy.passwordSection }}</h2>

      <form
        class="space-y-4 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5"
        data-testid="settings-password-form"
        @submit.prevent="handlePasswordUpdate"
      >
        <UiField :label="copy.currentPassword">
          <input
            v-model="currentPassword"
            type="password"
            required
            autocomplete="current-password"
            :placeholder="copy.currentPasswordPlaceholder"
            class="field-input"
            data-testid="settings-current-password"
          >
        </UiField>

        <UiField :label="copy.newPassword" :hint="copy.passwordHint">
          <input
            v-model="newPassword"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
            :placeholder="copy.newPasswordPlaceholder"
            class="field-input"
            data-testid="settings-new-password"
          >
        </UiField>

        <UiField :label="copy.confirmNewPassword">
          <input
            v-model="newPasswordConfirmation"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
            :placeholder="copy.confirmNewPasswordPlaceholder"
            class="field-input"
            data-testid="settings-confirm-new-password"
          >
        </UiField>

        <UiNotice v-if="passwordError" :message="passwordError" />
        <UiNotice v-else-if="passwordSuccess" :message="passwordSuccess" tone="success" />

        <UiButton
          type="submit"
          variant="quiet"
          :loading="passwordSaving"
          :disabled="!currentPassword || newPassword.length < 8 || !newPasswordConfirmation"
          data-testid="settings-password-submit"
        >
          {{ passwordSaving ? copy.updatingPassword : copy.updatePasswordCta }}
        </UiButton>
      </form>
    </section>
  </SettingsPage>
</template>
