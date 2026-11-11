<script setup lang="ts">
import type { BillingInterval, BillingPlan } from '~/types/graphql'
import { pricing as copy } from '~/constants/strings'

/**
 * 料金プラン — the public pricing screen.
 *
 * It renders for guests too (the route is in the guard's public list), because
 * a price is part of the pitch and hiding it behind sign-up costs more than it
 * protects. The plan names and descriptions are keyed by the backend's stable
 * `code`; prices and minutes come from `billingPlans`.
 */
const { user } = useAuth()

const {
  plans,
  plansFetching,
  plansError,
  loadPlans,
  overview,
  refresh,
  checkout,
  checkingOut,
  error,
} = useBilling()

const interval = ref<BillingInterval>('month')

onMounted(async () => {
  await loadPlans()

  if (user.value) {
    await refresh()
  }
})

type PlanCopy = { name: string, tagline: string, description: string }

function copyFor(code: string): PlanCopy {
  const known = copy.plans[code as keyof typeof copy.plans]

  return known ?? { name: code, tagline: '', description: '' }
}

function priceFor(plan: BillingPlan): number {
  return interval.value === 'month' ? plan.monthlyPriceJpy : plan.yearlyPriceJpy
}

function yearlyMonthly(plan: BillingPlan): number {
  return Math.round(plan.yearlyPriceJpy / 12)
}

function isCurrent(plan: BillingPlan): boolean {
  return overview.value?.hasSubscription === true && overview.value.planCode === plan.code
}

async function choose(plan: BillingPlan): Promise<void> {
  if (!user.value) {
    await navigateTo('/register')
    return
  }

  await checkout(plan.code, interval.value)
}
</script>

<template>
  <section class="mx-auto max-w-4xl space-y-8">
    <PageHeader :eyebrow="copy.eyebrow" :title="copy.title" :lead="copy.lead" />

    <p v-if="!user" class="text-[0.875rem] text-[var(--ink-faint)]">
      {{ copy.loginPrompt }}
      <NuxtLink to="/login" class="text-[var(--accent)] underline-offset-4 hover:underline">
        {{ copy.loginLink }}
      </NuxtLink>
    </p>

    <!-- Billing interval: one control, two mutually exclusive choices. -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="flex rounded-full border border-[var(--line)] p-0.5" role="radiogroup" :aria-label="copy.intervalLabel">
        <button
          v-for="option in ([{ value: 'month', label: copy.monthly }, { value: 'year', label: copy.yearly }] as const)"
          :key="option.value"
          type="button"
          role="radio"
          class="min-h-9 rounded-full px-4 text-[0.875rem] transition-colors"
          :class="interval === option.value
            ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
            : 'text-[var(--ink-muted)] hover:text-[var(--ink)]'"
          :aria-checked="interval === option.value"
          @click="interval = option.value"
        >
          {{ option.label }}
        </button>
      </div>

      <span class="text-[0.8125rem] text-[var(--ink-faint)]">{{ copy.yearlyNote }}</span>
    </div>

    <UiNotice v-if="plansError" :message="plansError" />
    <UiNotice v-if="error" :message="error" />

    <p v-if="plansFetching && plans.length === 0" class="flex items-center gap-3 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ copy.title }}…
    </p>

    <div v-else class="grid gap-4 md:grid-cols-3">
      <!-- Free plan: no card, no Stripe, fifteen minutes. -->
      <article class="flex flex-col gap-4 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5">
        <header class="space-y-1">
          <h2 class="display text-[1.25rem]">{{ copy.freeTitle }}</h2>
          <p class="text-[0.8125rem] text-[var(--ink-faint)]">{{ copy.freeAllowance }}</p>
        </header>

        <p class="text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">{{ copy.freeBody }}</p>

        <div class="mt-auto pt-2">
          <UiButtonLink :to="user ? '/' : '/register'" block>
            {{ copy.freeCta }}
          </UiButtonLink>
        </div>
      </article>

      <article
        v-for="plan in plans"
        :key="plan.code"
        class="flex flex-col gap-4 rounded-3xl border p-5"
        :class="isCurrent(plan)
          ? 'border-[var(--accent)] bg-[color-mix(in_oklab,var(--accent)_7%,var(--surface))]'
          : 'border-[var(--line)] bg-[var(--surface)]'"
      >
        <header class="space-y-1">
          <div class="flex items-baseline justify-between gap-2">
            <h2 class="display text-[1.25rem]">{{ copyFor(plan.code).name }}</h2>
            <span v-if="isCurrent(plan)" class="rounded-full bg-[var(--accent-dim)] px-2.5 py-0.5 text-[0.6875rem] text-[var(--accent)]">
              {{ copy.current }}
            </span>
          </div>
          <p class="text-[0.8125rem] text-[var(--ink-faint)]">{{ copyFor(plan.code).tagline }}</p>
        </header>

        <div class="space-y-1">
          <p class="tnum text-[1.75rem] leading-none font-semibold">
            ¥{{ priceFor(plan).toLocaleString('ja-JP') }}
            <span class="text-[0.8125rem] font-normal text-[var(--ink-faint)]">
              / {{ interval === 'month' ? copy.perMonth : copy.perYear }}
            </span>
          </p>
          <p v-if="interval === 'year'" class="tnum text-[0.8125rem] text-[var(--ink-faint)]">
            {{ copy.monthlyEquivalent(yearlyMonthly(plan)) }}
          </p>
          <p class="tnum text-[0.875rem] text-[var(--ink)]">{{ copy.minutesPerMonth(plan.includedMinutes) }}</p>
        </div>

        <p class="text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">{{ copyFor(plan.code).description }}</p>

        <div class="mt-auto pt-2">
          <UiButton
            variant="primary"
            block
            :loading="checkingOut === `${plan.code}:${interval}`"
            :disabled="checkingOut !== null && checkingOut !== `${plan.code}:${interval}`"
            @click="choose(plan)"
          >
            {{ user ? copy.choose : copy.loginToChoose }}
          </UiButton>
        </div>
      </article>
    </div>

    <p class="text-center text-[0.75rem] leading-relaxed text-[var(--ink-faint)]">{{ copy.taxNote }}</p>
  </section>
</template>
