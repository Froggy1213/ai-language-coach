<script setup lang="ts">
import { legal } from '~/constants/strings'

const copy = legal.terms
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-8">
    <PageHeader :eyebrow="copy.eyebrow" :title="copy.title" :lead="copy.lead" />

    <p class="text-[0.8125rem] text-[var(--ink-faint)]">{{ copy.updatedAt }}</p>

    <div class="space-y-6">
      <article
        v-for="section in copy.sections"
        :key="section.title"
        class="space-y-3 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-7"
      >
        <h2 class="display text-[1.1875rem] leading-snug">{{ section.title }}</h2>
        <p class="text-[0.9375rem] leading-[1.85] text-[var(--ink-muted)]">{{ section.body }}</p>

        <ul
          v-if="section.items.length > 0"
          class="space-y-2 border-t border-[var(--line)] pt-3 text-[0.875rem] leading-[1.75] text-[var(--ink-muted)]"
        >
          <li v-for="item in section.items" :key="item" class="flex items-start gap-2.5">
            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-[var(--accent)]" />
            <span>{{ item }}</span>
          </li>
        </ul>
      </article>
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--line)] pt-6 text-[0.8125rem] text-[var(--ink-faint)]">
      <div class="flex flex-wrap items-center gap-5">
        <NuxtLink to="/privacy" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.privacy }}
        </NuxtLink>
        <NuxtLink to="/tokushoho" class="transition-colors hover:text-[var(--ink)]">
          {{ legal.links.tokushoho }}
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
