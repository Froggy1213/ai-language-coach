<script setup lang="ts">
import { landing } from '~/constants/strings'
</script>

<template>
  <!-- =======================================================================
       WHY KIRIE WORKS (#method) — Scroll Entry/Exit Cards
       ======================================================================= -->
  <section id="method" class="mx-auto max-w-6xl space-y-12 border-t border-[var(--line)] px-5 py-24 sm:px-6 md:py-32">
    <div class="max-w-2xl space-y-3">
      <p class="label text-[var(--accent)]">{{ landing.pillars.eyebrow }}</p>
      <h2 class="display text-[clamp(1.75rem,3.5vw,2.75rem)] leading-tight">
        {{ landing.pillars.title }}
      </h2>
      <p class="text-[1rem] leading-[1.8] text-[var(--ink-muted)]">
        {{ landing.pillars.subtitle }}
      </p>
    </div>

    <div class="grid gap-6 md:grid-cols-3">
      <article
        v-for="item in landing.pillars.items"
        :key="item.num"
        class="scroll-reveal-card flex flex-col justify-between rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-7"
      >
        <div class="space-y-4">
          <span class="tnum font-mono text-[0.75rem] tracking-[0.14em] text-[var(--accent)]">
            PILLAR {{ item.num }}
          </span>
          <h3 class="display text-[1.375rem] leading-snug">{{ item.title }}</h3>
          <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
            {{ item.body }}
          </p>
        </div>

        <div class="mt-8 border-t border-[var(--line)] pt-5">
          <p class="tnum display text-[2.25rem] leading-none text-[var(--accent)]">{{ item.metric }}</p>
          <p class="mt-1.5 text-[0.8125rem] text-[var(--ink-faint)]">{{ item.metricLabel }}</p>
        </div>
      </article>
    </div>
  </section>
</template>
