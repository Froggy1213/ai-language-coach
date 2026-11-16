<script setup lang="ts">
import { legal } from '~/constants/strings'

const copy = legal.tokushoho
const config = useRuntimeConfig()

const rows = computed(() => [
  {
    label: copy.rows.operator,
    value: config.public.legal?.operatorName || copy.operatorFallback,
  },
  {
    label: copy.rows.address,
    value: config.public.legal?.address || copy.disclosureFallback,
  },
  {
    label: copy.rows.phone,
    value: config.public.legal?.phone || copy.disclosureFallback,
  },
  {
    label: copy.rows.email,
    value: config.public.legal?.email || copy.emailFallback,
  },
  {
    label: copy.rows.price,
    value: copy.rows.priceValue,
  },
  {
    label: copy.rows.additionalFees,
    value: copy.rows.additionalFeesValue,
  },
  {
    label: copy.rows.paymentMethod,
    value: copy.rows.paymentMethodValue,
  },
  {
    label: copy.rows.paymentTiming,
    value: copy.rows.paymentTimingValue,
  },
  {
    label: copy.rows.deliveryTiming,
    value: copy.rows.deliveryTimingValue,
  },
  {
    label: copy.rows.cancellation,
    value: copy.rows.cancellationValue,
  },
  {
    label: copy.rows.environment,
    value: copy.rows.environmentValue,
  },
])
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-8">
    <PageHeader :eyebrow="copy.eyebrow" :title="copy.title" :lead="copy.lead" />

    <dl class="divide-y divide-[var(--line)] rounded-3xl border border-[var(--line)] bg-[var(--surface)] px-6 py-2 sm:px-8">
      <div
        v-for="row in rows"
        :key="row.label"
        class="grid gap-1.5 py-4 sm:grid-cols-[12rem_1fr] sm:gap-6"
      >
        <dt class="text-[0.875rem] font-medium text-[var(--ink)]">{{ row.label }}</dt>
        <dd class="text-[0.9375rem] leading-[1.75] text-[var(--ink-muted)]">{{ row.value }}</dd>
      </div>
    </dl>

    <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--line)] pt-6 text-[0.8125rem] text-[var(--ink-faint)]">
      <div class="flex flex-wrap items-center gap-5">
        <NuxtLink to="/privacy" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.privacy }}
        </NuxtLink>
        <NuxtLink to="/terms" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.terms }}
        </NuxtLink>
        <NuxtLink to="/pricing" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.pricing }}
        </NuxtLink>
      </div>
      <NuxtLink to="/" class="text-[var(--accent)] underline-offset-4 hover:underline">
        {{ legal.links.backHome }}
      </NuxtLink>
    </footer>
  </section>
</template>
