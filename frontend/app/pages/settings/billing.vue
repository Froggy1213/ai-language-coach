<script setup lang="ts">
import { billing as copy, pricing as pricingCopy } from '~/constants/strings'
import { formatDate } from '~/utils/format'
import { planDisplayName } from '~/utils/plan-label'

/**
 * 設定 → プランと利用状況.
 *
 * Stripe returns here after Checkout (`?checkout=success`), so the query is read
 * once, turned into a notice and then stripped with `replace` — the confirmation
 * should not reappear every time the learner navigates back.
 *
 * Billing is a separate read from `me` because its resolver sums the month's
 * sessions; the settings hub paints without waiting for it.
 */
const route = useRoute()
const router = useRouter()

const {
  overview,
  error,
  refresh,
  openPortal,
  openingPortal,
} = useBilling()

const checkoutState = ref('')

onMounted(async () => {
  const state = String(route.query.checkout ?? '')

  if (state === 'success') {
    checkoutState.value = state
    // Cleanup must never gate the read: a refused `replace` would otherwise
    // leave the page without its plan.
    void router.replace({ query: {} }).catch(() => {})
  }

  await refresh()
})

const planLabel = computed(() => planDisplayName(overview.value?.planCode))

const intervalLabel = computed(() => {
  if (overview.value?.interval === 'month') {
    return pricingCopy.monthly
  }
  if (overview.value?.interval === 'year') {
    return pricingCopy.yearly
  }

  return ''
})

/**
 * Stripe has more statuses than the interface names. An unknown one is better
 * left out than printed raw in English, so only the states this screen can
 * explain get a label.
 */
const statusLabel = computed(() => {
  switch (overview.value?.status) {
    case 'active':
      return copy.statusActive
    case 'trialing':
      return copy.statusTrialing
    case 'past_due':
      return copy.statusPastDue
    case 'unpaid':
      return copy.statusUnpaid
    case 'canceled':
      return copy.statusCanceled
    default:
      return ''
  }
})

// The free and the paid allowance are both monthly, so this number is always
// this month's usage.
const usageLabel = copy.usageLabel

const paymentTrouble = computed(() =>
  overview.value?.status === 'past_due' || overview.value?.status === 'unpaid',
)

const usedPercent = computed(() => {
  const used = overview.value?.usedMinutes ?? 0
  const included = overview.value?.includedMinutes ?? 0

  return included > 0 ? Math.min(100, Math.round((used / included) * 100)) : 0
})
</script>

<template>
  <SettingsPage :title="copy.title" :lead="copy.lead">
    <UiNotice v-if="checkoutState === 'success'" :message="copy.checkoutSuccess" tone="success" />

    <UiNotice v-if="error" :message="error" />

    <p v-if="!overview && !error" class="flex items-center gap-3 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ copy.title }}…
    </p>

    <div v-else-if="overview" class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
      <header class="flex items-start justify-between gap-4">
        <div class="space-y-1">
          <p class="label">{{ copy.plan }}</p>
          <p class="display text-[1.5rem] leading-tight">{{ planLabel }}</p>
          <p v-if="intervalLabel" class="text-[0.8125rem] text-[var(--ink-faint)]">{{ intervalLabel }}</p>
        </div>

        <span
          v-if="statusLabel"
          class="shrink-0 rounded-full border border-[var(--line-strong)] px-2.5 py-1 text-[0.6875rem] text-[var(--ink-muted)]"
        >
          {{ statusLabel }}
        </span>
      </header>

      <!-- The remaining allowance is the one number a learner opens this screen
           for, so it gets the display size; the progress bar repeats it as a
           proportion, and the used line keeps the denominator honest. -->
      <div class="space-y-2.5 pt-1">
        <div class="flex items-baseline justify-between gap-4">
          <span class="text-[0.8125rem] text-[var(--ink-muted)]">{{ copy.remainingLabel }}</span>
          <span class="tnum display text-[1.75rem] leading-none">
            {{ overview.remainingMinutes }}<span class="ml-1 text-[0.8125rem] text-[var(--ink-faint)]">{{ copy.minutesUnit }}</span>
          </span>
        </div>

        <div class="h-1.5 w-full overflow-hidden rounded-full bg-[var(--line)]">
          <div
            class="h-full rounded-full bg-[var(--accent)] transition-[width] duration-700 ease-[var(--ease-out-soft)]"
            :style="{ width: `${usedPercent}%` }"
          />
        </div>

        <div class="flex items-baseline justify-between gap-4 text-[0.75rem] text-[var(--ink-faint)]">
          <span>{{ usageLabel }}</span>
          <span class="tnum">{{ copy.minutesUsed(overview.usedMinutes, overview.includedMinutes) }}</span>
        </div>
      </div>

      <p v-if="overview.resetsAt" class="text-[0.75rem] text-[var(--ink-faint)]">
        {{ copy.resetsLabel }}: {{ formatDate(overview.resetsAt) }}
      </p>
      <p v-else-if="overview.currentPeriodEnd" class="text-[0.75rem] text-[var(--ink-faint)]">
        {{ copy.currentPeriodEnd }}: {{ formatDate(overview.currentPeriodEnd) }}
      </p>
      <p v-else-if="!overview.hasSubscription" class="text-[0.75rem] leading-relaxed text-[var(--ink-faint)]">
        {{ copy.freeNote }}
      </p>

      <p v-if="overview.cancelAtPeriodEnd" class="text-[0.75rem] text-[var(--ink-faint)]">
        {{ copy.cancelAtPeriodEnd }}
      </p>

      <UiNotice v-if="paymentTrouble" :message="copy.pastDue" tone="warn" />

      <div class="flex flex-wrap gap-2 pt-1">
        <UiButtonLink to="/pricing" variant="primary">
          {{ copy.change }}
        </UiButtonLink>
        <UiButton
          v-if="overview.canManage"
          :loading="openingPortal"
          @click="openPortal"
        >
          {{ copy.manage }}
        </UiButton>
      </div>
    </div>
  </SettingsPage>
</template>
