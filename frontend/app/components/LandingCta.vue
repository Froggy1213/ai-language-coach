<script setup lang="ts">
import { landing, legal } from '~/constants/strings'
</script>

<template>
  <!-- =======================================================================
       FINAL CALL TO ACTION
       ======================================================================= -->
  <section class="mx-auto max-w-6xl px-5 pt-8 pb-20 sm:px-6">
    <div class="relative overflow-hidden rounded-3xl border border-[var(--line-strong)] bg-[var(--surface-strong)] px-6 py-14 text-center sm:px-12 sm:py-20">
      <div
        class="pointer-events-none absolute inset-0 opacity-30"
        style="background: radial-gradient(circle at 50% 0%, var(--accent) 0%, transparent 68%)"
        aria-hidden="true"
      />

      <div class="relative z-10 mx-auto max-w-2xl space-y-6">
        <p class="label text-[var(--accent)]">{{ landing.cta.eyebrow }}</p>
        <h2 class="display text-[clamp(1.875rem,4vw,3rem)] leading-tight">
          {{ landing.cta.title }}
        </h2>
        <p class="text-[1rem] leading-[1.8] text-[var(--ink-muted)]">
          {{ landing.cta.subtitle }}
        </p>

        <div class="flex flex-wrap items-center justify-center gap-4 pt-3">
          <UiButtonLink to="/register" variant="primary" size="lg">
            {{ landing.cta.primary }}
          </UiButtonLink>
          <UiButtonLink to="/login" variant="quiet" size="lg">
            {{ landing.cta.secondary }}
          </UiButtonLink>
        </div>
      </div>
    </div>

    <footer class="mt-12 flex flex-wrap items-center justify-between gap-4 border-t border-[var(--line)] pt-6 text-[0.8125rem] text-[var(--ink-faint)]">
      <span class="display text-[0.9375rem] text-[var(--ink-muted)]">Kirie</span>
      <nav class="flex flex-wrap items-center gap-5">
        <NuxtLink to="/pricing" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.pricing }}
        </NuxtLink>
        <NuxtLink to="/privacy" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.privacy }}
        </NuxtLink>
        <NuxtLink to="/terms" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.terms }}
        </NuxtLink>
        <NuxtLink to="/tokushoho" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.tokushoho }}
        </NuxtLink>
      </nav>
    </footer>
  </section>
</template>
