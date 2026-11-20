<script setup lang="ts">
import type { CombinedError } from '@urql/vue'
import {
  common,
  legal,
  settings as copy,
} from '~/constants/strings'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { planDisplayName } from '~/utils/plan-label'

/**
 * 設定 — the hub.
 *
 * The old screen carried every control in one scroll: profile fields next to the
 * billing ledger, extraction forms next to the theme switch. This page is now a
 * map, not a workbench: it answers "what can I change" with one row per concern
 * and sends the learner to a screen that does that one job.
 *
 * The identity card is not a navigation row among others — it is the learner, so
 * it stays at the top and its two actions live directly beneath it.
 *
 * Account state comes from `useAuth`, which the route guard has already resolved
 * before this component mounts and every settings mutation refreshes. A second
 * `me` query here would only duplicate that read.
 */
const { user, logout } = useAuth()

const { theme } = useTheme()
const { overview, refresh: refreshBilling } = useBilling()

onMounted(refreshBilling)

const planLabel = computed(() => planDisplayName(overview.value?.planCode))

const planValue = computed(() =>
  overview.value ? copy.planSummary(planLabel.value, overview.value.remainingMinutes) : '',
)

const themeValue = computed(() => (theme.value === 'dark' ? common.themeDark : common.themeLight))

const consentValue = computed(() =>
  user.value?.voiceConsentAt ? copy.voiceConsentYes : copy.voiceConsentNo,
)

const signingOut = ref(false)
const logoutError = ref<string | null>(null)

async function handleSignOut(): Promise<void> {
  signingOut.value = true
  logoutError.value = null

  try {
    await logout()
  } catch (failure) {
    logoutError.value = graphQLErrorMessageFor(failure as CombinedError, {
      fallback: copy.logoutError,
    })
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <section v-if="user" class="mx-auto max-w-xl space-y-8">
    <PageHeader :title="copy.title" :lead="copy.subtitle" />

    <!-- Identity. The level is a fact about the learner, not a setting, so it
         rides with the name rather than taking a row of its own. -->
    <div class="overflow-hidden rounded-3xl border border-[var(--line)] bg-[var(--surface)]">
      <div class="flex items-center gap-4 p-5">
        <span
          aria-hidden="true"
          class="grid size-12 shrink-0 place-items-center rounded-full bg-[var(--accent-dim)] text-[1.125rem] font-semibold text-[var(--accent)]"
        >
          {{ user.name.slice(0, 1).toUpperCase() }}
        </span>

        <span class="min-w-0 flex-1">
          <span class="block truncate text-[1.0625rem] font-medium">{{ user.name }}</span>
          <span class="block truncate text-[0.8125rem] text-[var(--ink-faint)]">{{ user.email }}</span>
        </span>

        <span class="tnum shrink-0 rounded-full border border-[var(--line-strong)] px-2.5 py-0.5 text-[0.75rem] text-[var(--ink-muted)]">
          {{ user.currentLevel }}
        </span>
      </div>

      <ul class="divide-y divide-[var(--line)] border-t border-[var(--line)]">
        <SettingsRow to="/settings/account" :label="copy.rows.editProfile" />
        <SettingsRow to="/settings/account#password" :label="copy.rows.changePassword" :value="copy.passwordSet" />
      </ul>
    </div>

    <!-- Plan. Heavier than the other rows: the resolver sums the month's
         sessions, so it fills in after the page rather than blocking it. -->
    <section class="space-y-3">
      <h2 class="label">{{ copy.sections.plan }}</h2>

      <ul class="divide-y divide-[var(--line)] overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)]">
        <SettingsRow to="/settings/billing" :label="copy.rows.plan" :value="planValue" />
      </ul>
    </section>

    <section class="space-y-3">
      <h2 class="label">{{ copy.sections.appearance }}</h2>

      <ul class="divide-y divide-[var(--line)] overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)]">
        <SettingsRow to="/settings/appearance" :label="copy.rows.theme" :value="themeValue" />
      </ul>
    </section>

    <section class="space-y-3">
      <h2 class="label">{{ copy.sections.data }}</h2>

      <ul class="divide-y divide-[var(--line)] overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)]">
        <SettingsRow to="/settings/privacy" :label="copy.rows.voiceConsent" :value="consentValue" />
        <SettingsRow
          to="/settings/privacy#delete"
          :label="copy.rows.deleteAccount"
          tone="danger"
        />
      </ul>
    </section>

    <!-- Signing out is a plain action, not a danger: it is one of the two
         things a settings screen is for. -->
    <section class="space-y-3">
      <h2 class="label">{{ copy.sections.session }}</h2>

      <UiNotice v-if="logoutError" :message="logoutError" />

      <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4">
        <p class="text-[0.8125rem] leading-relaxed text-[var(--ink-faint)]">{{ copy.logoutHint }}</p>
        <UiButton variant="quiet" :loading="signingOut" @click="handleSignOut">
          {{ signingOut ? copy.loggingOut : copy.logout }}
        </UiButton>
      </div>
    </section>

    <footer class="flex flex-wrap items-center gap-5 border-t border-[var(--line)] pt-6 text-[0.8125rem] text-[var(--ink-faint)]">
      <NuxtLink to="/privacy" class="transition-colors hover:text-[var(--ink)]">
        {{ legal.links.privacy }}
      </NuxtLink>
      <NuxtLink to="/terms" class="transition-colors hover:text-[var(--ink)]">
        {{ legal.links.terms }}
      </NuxtLink>
      <NuxtLink to="/tokushoho" class="transition-colors hover:text-[var(--ink)]">
        {{ legal.links.tokushoho }}
      </NuxtLink>
    </footer>
  </section>
</template>
