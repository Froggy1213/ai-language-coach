<script setup lang="ts">
import { common, settings as copy } from '~/constants/strings'

/**
 * 設定 → 表示.
 *
 * A theme used to be a two-button segmented control squeezed into a settings
 * row. Here each choice is a preview of the product itself: the learner picks a
 * screen, not a word. Native radios carry the keyboard behaviour and the
 * mutually-exclusive semantics; the label card is only paint.
 */
const { theme, set: setTheme } = useTheme()

const options = [
  {
    value: 'dark',
    label: common.themeDark,
    hint: copy.themeDarkHint,
  },
  {
    value: 'light',
    label: common.themeLight,
    hint: copy.themeLightHint,
  },
] as const
</script>

<template>
  <SettingsPage :title="copy.appearanceTitle" :lead="copy.appearanceLead">
    <section class="space-y-3">
      <h2 class="label">{{ copy.themeSection }}</h2>

      <fieldset class="grid grid-cols-2 gap-3">
        <legend class="sr-only">{{ copy.themeSection }}</legend>

        <label
          v-for="option in options"
          :key="option.value"
          class="group cursor-pointer"
        >
          <input
            type="radio"
            name="theme"
            :value="option.value"
            :checked="theme === option.value"
            class="peer sr-only"
            @change="setTheme(option.value)"
          >

          <span
            class="block rounded-2xl border p-2 transition-colors peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--accent)]"
            :class="theme === option.value
              ? 'border-[var(--accent)] bg-[color-mix(in_oklab,var(--accent)_7%,var(--surface))]'
              : 'border-[var(--line)] bg-[var(--surface)] group-hover:border-[var(--line-strong)]'"
          >
            <!-- The preview keeps fixed colours so it shows the theme it names
                 even while the other theme is applied to the page. -->
            <span
              class="flex h-24 items-center gap-3 overflow-hidden rounded-xl p-3"
              :class="option.value === 'dark'
                ? 'bg-[oklch(0.185_0.014_265)]'
                : 'bg-[oklch(0.975_0.006_85)]'"
            >
              <span class="size-9 shrink-0 rounded-full bg-[var(--color-aqua-400)] shadow-[0_0_20px_2px_var(--accent-dim)]" />

              <span class="min-w-0 flex-1 space-y-1.5">
                <span
                  class="block h-1.5 w-4/5 rounded-full"
                  :class="option.value === 'dark' ? 'bg-[oklch(0.41_0.015_265)]' : 'bg-[oklch(0.755_0.01_265)]'"
                />
                <span
                  class="block h-1.5 w-3/5 rounded-full"
                  :class="option.value === 'dark' ? 'bg-[oklch(0.315_0.016_265)]' : 'bg-[oklch(0.865_0.008_265)]'"
                />
              </span>
            </span>

            <span class="flex items-center justify-between gap-2 px-1 pt-2.5 pb-1">
              <span class="text-[0.875rem]">{{ option.label }}</span>
              <span v-if="theme === option.value" class="text-[0.6875rem] text-[var(--accent)]">
                {{ copy.themeSelected }}
              </span>
            </span>

            <span class="block px-1 pb-1 text-[0.75rem] text-[var(--ink-faint)]">{{ option.hint }}</span>
          </span>
        </label>
      </fieldset>

      <p class="text-[0.8125rem] leading-relaxed text-[var(--ink-faint)]">{{ copy.themeHint }}</p>
    </section>
  </SettingsPage>
</template>
