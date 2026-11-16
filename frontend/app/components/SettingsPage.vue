<script setup lang="ts">
import { settings as copy } from '~/constants/strings'

/**
 * The frame every 設定 sub-screen shares: a way back to the hub, one heading,
 * and the same capped column.
 *
 * The hub itself does not use this — it is the destination the back link points
 * at, and a back link there would lead in a circle.
 */
withDefaults(
  defineProps<{
    title: string
    lead?: string
  }>(),
  { lead: '' },
)
</script>

<template>
  <section class="mx-auto max-w-xl space-y-8">
    <div class="space-y-4">
      <NuxtLink
        to="/settings"
        class="inline-flex min-h-9 items-center gap-1.5 text-[0.8125rem] text-[var(--ink-faint)] transition-colors hover:text-[var(--ink)]"
      >
        <span aria-hidden="true">←</span>
        {{ copy.backToSettings }}
      </NuxtLink>

      <PageHeader :title="title" :lead="lead" />
    </div>

    <slot />
  </section>
</template>
