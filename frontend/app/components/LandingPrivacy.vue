<script setup lang="ts">
import { landing } from '~/constants/strings'
</script>

<template>
  <!-- =======================================================================
       PRIVACY BY DESIGN (#privacy)
       ======================================================================= -->
  <section id="privacy" class="mx-auto max-w-6xl border-t border-[var(--line)] px-5 py-20 sm:px-6">
    <div class="scroll-reveal-card rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-10">
      <div class="max-w-3xl space-y-4">
        <p class="label text-[var(--color-sage-400)]">{{ landing.privacy.eyebrow }}</p>
        <h2 class="display text-[clamp(1.5rem,3vw,2.125rem)] leading-snug">
          {{ landing.privacy.title }}
        </h2>
        <p class="text-[0.9375rem] leading-[1.85] text-[var(--ink-muted)]">
          {{ landing.privacy.body }}
        </p>

        <div class="flex flex-wrap gap-2.5 pt-2">
          <span
            v-for="badge in landing.privacy.badges"
            :key="badge"
            class="inline-flex items-center gap-2 rounded-full border border-[var(--line-strong)] bg-[var(--surface-sunken)] px-3.5 py-1.5 text-[0.8125rem] text-[var(--ink)]"
          >
            <span class="size-1.5 rounded-full bg-[var(--color-sage-400)]" />
            {{ badge }}
          </span>
        </div>
      </div>
    </div>
  </section>
</template>
