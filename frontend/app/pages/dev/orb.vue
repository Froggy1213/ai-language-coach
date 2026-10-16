<script setup lang="ts">
import type { OrbState } from '~/composables/useConversation'

/**
 * Design harness for the conversation orb. Not linked from anywhere; used to
 * review every state at a readable size while tuning the canvas.
 */
const states: OrbState[] = ['idle', 'listening', 'speaking', 'thinking', 'error']
const energy = ref(0.55)
const state = ref<OrbState>('idle')
</script>

<template>
  <section class="space-y-10">
    <h1 class="display text-[1.5rem]">Orb states</h1>

    <label class="flex max-w-sm items-center gap-3 text-[0.875rem]">
      energy
      <input v-model.number="energy" type="range" min="0" max="1" step="0.01" class="flex-1" aria-label="energy">
      <span class="tnum w-10">{{ energy.toFixed(2) }}</span>
    </label>

    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      <div v-for="s in states" :key="s" class="space-y-2">
        <div class="aspect-square rounded-3xl border border-[var(--line)]" style="background: var(--surface-sunken)">
          <ConversationOrb :state="s" :energy="energy" />
        </div>
        <p class="label">{{ s }}</p>
      </div>
    </div>

    <div class="space-y-3">
      <div class="flex flex-wrap gap-2">
        <button
          v-for="s in states"
          :key="s"
          type="button"
          class="rounded-full border px-3 py-1 text-[0.8125rem]"
          :class="state === s ? 'border-[var(--accent)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--ink-muted)]'"
          @click="state = s"
        >
          {{ s }}
        </button>
      </div>
      <div class="mx-auto aspect-square w-[min(78vw,20rem)]">
        <ConversationOrb :state="state" :energy="energy" />
      </div>
    </div>
  </section>
</template>
