<script setup lang="ts">
import { useQuery } from '@urql/vue'
import { ME_QUERY } from '~/graphql/documents'
import { languageDisplayName } from '~/constants/languages'
import { common, settings as copy } from '~/constants/strings'
import { formatDate } from '~/utils/format'

/**
 * 設定 — account, appearance, and the data controls.
 *
 * A **Configure** surface, so it follows the grouped-list convention: rows of
 * label and value, one section per concern, and the destructive action isolated
 * at the bottom behind its own confirmation.
 */
interface MeData {
  me: {
    id: string
    name: string
    email: string
    targetLanguage: string
    currentLevel: string
    voiceConsentAt: string | null
  } | null
}

const { data, fetching, error: queryError } = useQuery<MeData>({
  query: ME_QUERY,
  requestPolicy: 'cache-and-network',
})

const currentUser = computed(() => data.value?.me ?? null)

const { theme, set: setTheme } = useTheme()

const { logout } = useAuth()
const { deleting, error: deleteError, deleteAccount } = usePrivacy()

const password = ref('')
const confirmation = ref('')
const signingOut = ref(false)

async function handleSignOut(): Promise<void> {
  signingOut.value = true

  try {
    await logout()
  } finally {
    signingOut.value = false
  }
}

async function handleDelete(): Promise<void> {
  if (!password.value || !confirmation.value) {
    return
  }

  await deleteAccount(password.value, confirmation.value)
}
</script>

<template>
  <section class="mx-auto max-w-xl space-y-10">
    <PageHeader :title="copy.title" :lead="copy.subtitle" />

    <p v-if="fetching && !currentUser" class="flex items-center gap-3 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ copy.title }}…
    </p>

    <UiNotice v-else-if="queryError && !currentUser" :message="copy.loadError" />

    <template v-else-if="currentUser">
      <!-- Account -->
      <section class="space-y-3">
        <h2 class="label">{{ copy.account }}</h2>

        <dl class="divide-y divide-[var(--line)] rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-4">
          <div class="flex items-baseline justify-between gap-6 py-3.5">
            <dt class="shrink-0 text-[0.875rem] text-[var(--ink-muted)]">{{ copy.name }}</dt>
            <dd class="min-w-0 truncate text-[0.9375rem]">{{ currentUser.name }}</dd>
          </div>
          <div class="flex items-baseline justify-between gap-6 py-3.5">
            <dt class="shrink-0 text-[0.875rem] text-[var(--ink-muted)]">{{ copy.email }}</dt>
            <dd class="min-w-0 truncate text-[0.9375rem]">{{ currentUser.email }}</dd>
          </div>
          <div class="flex items-baseline justify-between gap-6 py-3.5">
            <dt class="shrink-0 text-[0.875rem] text-[var(--ink-muted)]">{{ copy.language }}</dt>
            <dd class="text-[0.9375rem]">{{ languageDisplayName(currentUser.targetLanguage) }}</dd>
          </div>
          <div class="flex items-baseline justify-between gap-6 py-3.5">
            <dt class="shrink-0 text-[0.875rem] text-[var(--ink-muted)]">{{ copy.levelLabel }}</dt>
            <dd class="tnum text-[0.9375rem]">{{ currentUser.currentLevel }}</dd>
          </div>
          <div class="flex items-baseline justify-between gap-6 py-3.5">
            <dt class="shrink-0 text-[0.875rem] text-[var(--ink-muted)]">{{ copy.voiceConsent }}</dt>
            <dd class="text-[0.9375rem]">
              <span v-if="currentUser.voiceConsentAt" class="inline-flex items-center gap-2">
                <span class="size-1.5 rounded-full bg-[var(--color-sage-400)]" />
                {{ copy.voiceConsentYes }}
              </span>
              <span v-else class="text-[var(--ink-faint)]">{{ copy.voiceConsentNo }}</span>
            </dd>
          </div>
        </dl>

        <p v-if="currentUser.voiceConsentAt" class="pl-1 text-[0.75rem] text-[var(--ink-faint)]">
          {{ formatDate(currentUser.voiceConsentAt) }}
        </p>
      </section>

      <!-- Appearance -->
      <section class="space-y-3">
        <h2 class="label">{{ copy.appearance }}</h2>

        <div class="space-y-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4">
          <div class="flex items-center justify-between gap-4">
            <span class="text-[0.9375rem]">{{ common.theme }}</span>

            <!-- Segmented control: two mutually exclusive options read better as
                 one control than as two switches. -->
            <div class="flex rounded-full border border-[var(--line)] p-0.5" role="radiogroup" :aria-label="common.theme">
              <button
                v-for="option in ([{ value: 'dark', label: common.themeDark }, { value: 'light', label: common.themeLight }] as const)"
                :key="option.value"
                type="button"
                role="radio"
                class="min-h-8 rounded-full px-3.5 text-[0.8125rem] transition-colors"
                :class="theme === option.value
                  ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
                  : 'text-[var(--ink-muted)] hover:text-[var(--ink)]'"
                :aria-checked="theme === option.value"
                @click="setTheme(option.value)"
              >
                {{ option.label }}
              </button>
            </div>
          </div>

          <p class="text-[0.8125rem] leading-relaxed text-[var(--ink-faint)]">{{ copy.themeHint }}</p>
        </div>
      </section>

      <!-- Session. Signing out is a plain action, not a danger: it is one of the
           two things this screen is for. -->
      <section class="space-y-3">
        <h2 class="label">{{ copy.session }}</h2>

        <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4">
          <p class="text-[0.8125rem] leading-relaxed text-[var(--ink-faint)]">{{ copy.logoutHint }}</p>
          <UiButton variant="quiet" :loading="signingOut" @click="handleSignOut">
            {{ signingOut ? copy.loggingOut : copy.logout }}
          </UiButton>
        </div>
      </section>

      <!-- Danger zone -->
      <section class="space-y-4">
        <h2 class="label text-[var(--color-clay-400)]">{{ copy.danger }}</h2>

        <div class="space-y-5 rounded-2xl border border-[color-mix(in_oklab,var(--color-clay-400)_30%,transparent)] bg-[color-mix(in_oklab,var(--color-clay-500)_7%,transparent)] p-4">
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
                class="w-full rounded-xl border border-[var(--line-strong)] bg-[var(--surface-sunken)] px-3.5 py-2.5 text-[0.9375rem] text-[var(--ink)] outline-none transition focus:border-[var(--accent)]"
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
                class="w-full rounded-xl border border-[var(--line-strong)] bg-[var(--surface-sunken)] px-3.5 py-2.5 font-mono text-[0.9375rem] text-[var(--ink)] outline-none transition focus:border-[var(--color-clay-400)]"
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
    </template>
  </section>
</template>
