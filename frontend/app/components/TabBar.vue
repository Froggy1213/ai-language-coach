<script setup lang="ts">
import { nav } from '~/constants/strings'

/**
 * The bottom tab bar.
 *
 * A persistent bottom bar with always-visible labels is what Japanese users read
 * as an application rather than a web page, and it keeps navigation inside thumb
 * reach on a phone. Every tab stays enabled — a disabled tab teaches nothing, so
 * a locked lesson shows an explaining empty state on its own screen instead.
 *
 * Tabs live in two fixed groups of two around a raised centre action, which gives
 * the primary task (speaking) the only filled control in the bar.
 */
defineProps<{ dueCount: number }>()

const route = useRoute()

const leftTabs = [
  { to: '/', label: nav.today, exact: true, icon: 'today' },
  { to: '/roadmap', label: nav.roadmap, exact: false, icon: 'roadmap' },
] as const

const rightTabs = [
  { to: '/review', label: nav.review, exact: false, icon: 'review' },
  { to: '/settings', label: nav.settings, exact: false, icon: 'settings' },
] as const

function isActive(to: string, exact: boolean): boolean {
  return exact ? route.path === to : route.path === to || route.path.startsWith(`${to}/`)
}

const talkActive = computed(() => isActive('/talk', false))

/**
 * The centre action always resolves to `/talk`, which itself resolves "the lesson
 * in progress" — a deep link into a specific lesson would strand the learner on
 * that lesson forever.
 */
const talkTarget = '/talk'
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-50 border-t border-[var(--line)] bg-[color-mix(in_oklab,var(--canvas)_93%,transparent)] backdrop-blur-xl md:hidden"
    aria-label="メインナビゲーション"
  >
    <div class="mx-auto grid max-w-md grid-cols-5 items-end px-2 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
      <NuxtLink
        v-for="tab in leftTabs"
        :key="tab.to"
        :to="tab.to"
        class="flex min-h-[52px] min-w-0 flex-col items-center justify-center gap-1 rounded-2xl px-0.5 pt-2.5 transition-colors"
        :class="isActive(tab.to, tab.exact) ? 'text-[var(--ink)]' : 'text-[var(--ink-faint)]'"
        :aria-current="isActive(tab.to, tab.exact) ? 'page' : undefined"
      >
        <TabIcon :name="tab.icon" />
        <span class="w-full truncate text-center text-[0.625rem] leading-none font-medium">{{ tab.label }}</span>
      </NuxtLink>

      <NuxtLink
        :to="talkTarget"
        class="group flex min-h-[52px] min-w-0 flex-col items-center justify-center gap-1 rounded-2xl pt-2.5"
        :aria-current="talkActive ? 'page' : undefined"
      >
        <span
          class="grid size-11 place-items-center rounded-full bg-[var(--accent)] text-[var(--accent-ink)] shadow-[0_6px_20px_-6px_var(--accent)] transition group-active:scale-95"
        >
          <TabIcon name="talk" :filled="true" />
        </span>
        <span class="w-full truncate text-center text-[0.625rem] leading-none font-medium text-[var(--ink)]">{{ nav.talk }}</span>
      </NuxtLink>

      <NuxtLink
        v-for="tab in rightTabs"
        :key="tab.to"
        :to="tab.to"
        class="relative flex min-h-[52px] min-w-0 flex-col items-center justify-center gap-1 rounded-2xl px-0.5 pt-2.5 transition-colors"
        :class="isActive(tab.to, tab.exact) ? 'text-[var(--ink)]' : 'text-[var(--ink-faint)]'"
        :aria-current="isActive(tab.to, tab.exact) ? 'page' : undefined"
      >
        <span class="relative">
          <TabIcon :name="tab.icon" />
          <span
            v-if="tab.icon === 'review' && dueCount > 0"
            class="tnum absolute -top-1 -right-2 grid min-w-[1rem] place-items-center rounded-full bg-[var(--accent)] px-1 text-[0.625rem] leading-4 font-semibold text-[var(--accent-ink)]"
          >
            {{ dueCount > 99 ? '99+' : dueCount }}
          </span>
        </span>
        <span class="text-[0.6875rem] leading-none font-medium">{{ tab.label }}</span>
      </NuxtLink>
    </div>
  </nav>
</template>
