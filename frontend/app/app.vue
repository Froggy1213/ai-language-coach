<script setup lang="ts">
import { useQuery } from '@urql/vue'
import { DUE_REVIEWS_QUERY } from '~/graphql/documents'
import type { ReviewItem } from '~/types/graphql'
import { nav } from '~/constants/strings'

/**
 * Application shell.
 *
 * Two layout decisions carry the "native app, not website" feel:
 *  - a persistent bottom tab bar, which is what Japanese mobile users read as
 *    an application rather than a page;
 *  - the content column is capped and centred, with the tab bar overlaying it,
 *    so a phone never scrolls its navigation out of reach.
 *
 * The header only exists on desktop; on a phone the active tab already names the
 * page, so a title bar would be chrome for chrome's sake.
 */

const { user } = useAuth()
const { theme, toggle: toggleTheme } = useTheme()

const { data: reviewsData } = useQuery<{ dueReviews: ReviewItem[] }>({
  query: DUE_REVIEWS_QUERY,
  pause: computed(() => !user.value),
  requestPolicy: 'cache-and-network',
})

const dueCount = computed(() => reviewsData.value?.dueReviews?.length ?? 0)
</script>

<template>
  <div class="grain relative flex min-h-dvh flex-col bg-[var(--canvas)] text-[var(--ink)]">
    <!-- Desktop navigation. On phones the tab bar below does this job. -->
    <header v-if="user" class="sticky top-0 z-40 hidden border-b border-[var(--line)] bg-[color-mix(in_oklab,var(--canvas)_82%,transparent)] backdrop-blur-xl md:block">
      <div class="mx-auto flex h-16 max-w-5xl items-center gap-8 px-6">
        <NuxtLink to="/" class="group flex items-center gap-2.5">
          <span class="size-1.5 rounded-full bg-[var(--accent)] transition group-hover:scale-150" />
          <span class="display text-[1.0625rem] tracking-tight">Kirie</span>
        </NuxtLink>

        <nav class="flex items-center gap-1 text-[0.9375rem]">
          <NavLink to="/" :label="nav.today" exact />
          <NavLink to="/roadmap" :label="nav.roadmap" />
          <NavLink to="/talk" :label="nav.talk" />
          <NavLink to="/review" :label="nav.review" :badge="dueCount" />
        </nav>

        <div class="ml-auto flex items-center gap-3">
          <button
            type="button"
            class="grid size-9 place-items-center rounded-full text-[var(--ink-faint)] transition hover:bg-[var(--surface)] hover:text-[var(--ink)]"
            :aria-label="theme === 'dark' ? 'ライトテーマに切り替える' : 'ダークテーマに切り替える'"
            @click="toggleTheme"
          >
            <ThemeIcon :theme="theme" />
          </button>

          <NuxtLink
            to="/settings"
            class="flex items-center gap-2 rounded-full border border-[var(--line)] py-1.5 pr-3.5 pl-1.5 text-[0.8125rem] text-[var(--ink-muted)] transition hover:border-[var(--line-strong)] hover:text-[var(--ink)]"
          >
            <span class="grid size-6 place-items-center rounded-full bg-[var(--accent-dim)] text-[0.6875rem] font-semibold text-[var(--accent)]">
              {{ user.name.slice(0, 1).toUpperCase() }}
            </span>
            <span class="tnum">{{ user.currentLevel }}</span>
          </NuxtLink>
        </div>
      </div>
    </header>

    <main
      class="mx-auto w-full max-w-5xl flex-1 px-5 pt-8 sm:px-6 md:pt-12"
      :class="user ? 'pb-40 md:pb-24' : 'pb-20'"
    >
      <NuxtPage />
    </main>

    <TabBar v-if="user" :due-count="dueCount" />

    <NuxtRouteAnnouncer />
  </div>
</template>
