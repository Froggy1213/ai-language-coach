<script setup lang="ts">
import type { OrbState } from '~/composables/useConversation'
import { landing } from '~/constants/strings'

/**
 * A controlled surface: the landing page owns the orb state and energy for this
 * section, so it renders them and asks for changes instead of keeping a second
 * copy inside the section.
 */
defineProps<{
  state: OrbState
  energy: number
}>()

const emit = defineEmits<{
  'update:state': [OrbState]
  'update:energy': [number]
}>()

/** Range inputs report strings, so the value is coerced before it leaves the child. */
function onEnergyInput(event: Event): void {
  emit('update:energy', Number((event.target as HTMLInputElement).value))
}
</script>

<template>
  <!-- =======================================================================
       INTERACTIVE ORB PLAYGROUND (#interactive)
       ======================================================================= -->
  <section id="interactive" class="mx-auto max-w-6xl border-t border-[var(--line)] px-5 py-24 sm:px-6 md:py-32">
    <div class="grid items-center gap-12 rounded-3xl border border-[var(--line-strong)] bg-[var(--surface)] p-6 sm:p-10 lg:grid-cols-[1fr_1fr]">
      <div class="space-y-6">
        <div class="space-y-3">
          <p class="label text-[var(--accent)]">{{ landing.playground.eyebrow }}</p>
          <h2 class="display text-[clamp(1.625rem,3.2vw,2.375rem)] leading-tight">
            {{ landing.playground.title }}
          </h2>
          <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
            {{ landing.playground.subtitle }}
          </p>
        </div>

        <!-- State selector buttons -->
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4" role="group" aria-label="オーブの状態を選択">
          <button
            v-for="item in landing.playground.states"
            :key="item.state"
            type="button"
            class="rounded-2xl border px-3.5 py-2.5 text-left transition"
            :class="state === item.state
              ? 'border-[var(--accent)] bg-[var(--accent-dim)] text-[var(--accent)]'
              : 'border-[var(--line)] bg-[var(--surface-sunken)] text-[var(--ink-muted)] hover:border-[var(--line-strong)] hover:text-[var(--ink)]'"
            @click="emit('update:state', item.state)"
          >
            <p class="font-mono text-[0.6875rem] uppercase opacity-75">{{ item.state }}</p>
            <p class="mt-0.5 text-[0.875rem] font-medium">{{ item.label }}</p>
          </button>
        </div>

        <p class="rounded-2xl bg-[var(--surface-sunken)] px-4 py-3 text-[0.875rem] leading-relaxed text-[var(--ink-muted)]">
          {{ landing.playground.states.find((s) => s.state === state)?.desc }}
        </p>

        <!-- Voice Energy Slider -->
        <label class="block space-y-2">
          <div class="flex items-center justify-between text-[0.8125rem]">
            <span class="text-[var(--ink-muted)]">{{ landing.playground.energyLabel }}</span>
            <span class="tnum font-mono text-[var(--accent)]">{{ Math.round(energy * 100) }}%</span>
          </div>
          <input
            :value="energy"
            type="range"
            min="0.08"
            max="1"
            step="0.02"
            class="w-full accent-[var(--accent)]"
            :aria-label="landing.playground.energyLabel"
            @input="onEnergyInput"
          >
        </label>
      </div>

      <div class="flex flex-col items-center justify-center">
        <div class="relative aspect-square w-[min(76vw,22rem)]">
          <div
            class="pointer-events-none absolute inset-[8%] rounded-full border border-[var(--line)] bg-[var(--surface-sunken)] shadow-inner"
            aria-hidden="true"
          />
          <div class="relative z-10 size-full">
            <ConversationOrb :state="state" :energy="energy" />
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
