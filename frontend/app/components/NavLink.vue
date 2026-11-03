<script setup lang="ts">
/**
 * Desktop navigation link.
 *
 * The active state is typographic (ink + weight) rather than a pill or an
 * underline slab: at five items, five pinked backgrounds would compete with the
 * page's own hierarchy.
 */
const props = withDefaults(
  defineProps<{
    to: string
    label: string
    /** Match the path exactly; needed for `/`, which prefixes every route. */
    exact?: boolean
    /**
     * Extra route prefixes that light this link up. Used to keep ロードマップ
     * active during a lesson (`/lesson/:cardId`), which belongs to the course
     * even though its URL is not under `/roadmap`.
     */
    also?: readonly string[]
    badge?: number
  }>(),
  {
    exact: false,
    also: () => [],
    badge: 0,
  },
)

const route = useRoute()

const active = computed(() => {
  if (props.exact) {
    return route.path === props.to
  }

  // A prefix owns its children (`/talk` owns `/talk/:code`) but must not match
  // `/talkative`, hence the trailing slash in the comparison.
  const prefixes = [props.to, ...props.also]

  return prefixes.some((prefix) => route.path === prefix || route.path.startsWith(`${prefix}/`))
})
</script>

<template>
  <NuxtLink
    :to="to"
    class="relative rounded-full px-3.5 py-1.5 transition-colors"
    :class="active ? 'text-[var(--ink)]' : 'text-[var(--ink-muted)] hover:text-[var(--ink)]'"
    :aria-current="active ? 'page' : undefined"
  >
    {{ label }}
    <span
      v-if="badge > 0"
      class="tnum ml-1.5 inline-flex min-w-[1.25rem] justify-center rounded-full bg-[var(--accent-dim)] px-1.5 py-0.5 align-middle text-[0.6875rem] font-semibold text-[var(--accent)]"
    >
      {{ badge }}
    </span>
    <span
      v-if="active"
      class="absolute inset-x-3 -bottom-[1.15rem] h-px bg-[var(--ink)]"
      aria-hidden="true"
    />
  </NuxtLink>
</template>
