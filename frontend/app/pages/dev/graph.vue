<script setup lang="ts">
import { computed, ref } from 'vue'
import type { CefrLevel, LessonCardStatus } from '~/types/graphql'
import { pickRecommendedCard, type RoadmapGraphCard } from '~/utils/roadmap-graph'

if (process.env.NODE_ENV === 'production') {
  throw createError({ statusCode: 404, statusMessage: 'Not Found', fatal: true })
}

interface RawNodeSpec {
  code: string
  title: string
  level: CefrLevel
  category: string
  prereqs: string[]
}

const CATALOGUE_SPEC: RawNodeSpec[] = [
  // A1 (8)
  { code: 'present_simple', title: 'Present Simple', level: 'A1', category: 'tenses', prereqs: [] },
  { code: 'be_and_there', title: 'Be & There is/are', level: 'A1', category: 'verbs', prereqs: [] },
  { code: 'question_forms', title: 'Question Forms', level: 'A1', category: 'clauses', prereqs: ['present_simple', 'be_and_there'] },
  { code: 'present_continuous', title: 'Present Continuous', level: 'A1', category: 'tenses', prereqs: ['present_simple', 'be_and_there'] },
  { code: 'past_simple', title: 'Past Simple', level: 'A1', category: 'tenses', prereqs: ['present_simple'] },
  { code: 'plurals_countability', title: 'Countable & Uncountable Nouns', level: 'A1', category: 'nouns', prereqs: [] },
  { code: 'articles', title: 'Articles (a / an / the)', level: 'A1', category: 'nouns', prereqs: ['plurals_countability'] },
  { code: 'quantifiers', title: 'Quantifiers (some / any / much)', level: 'A1', category: 'nouns', prereqs: ['plurals_countability'] },
  // A2 (9)
  { code: 'prepositions_time_place', title: 'Prepositions of Time & Place', level: 'A2', category: 'prepositions', prereqs: ['be_and_there'] },
  { code: 'past_continuous', title: 'Past Continuous', level: 'A2', category: 'tenses', prereqs: ['past_simple', 'present_continuous'] },
  { code: 'present_perfect', title: 'Present Perfect', level: 'A2', category: 'tenses', prereqs: ['past_simple'] },
  { code: 'used_to', title: 'Used to (Past Habits)', level: 'A2', category: 'verbs', prereqs: ['past_simple', 'present_continuous'] },
  { code: 'future_going_to', title: 'Going to for Plans', level: 'A2', category: 'tenses', prereqs: ['be_and_there', 'present_continuous'] },
  { code: 'future_will', title: 'Will for Decisions & Predictions', level: 'A2', category: 'tenses', prereqs: ['present_simple'] },
  { code: 'comparatives_superlatives', title: 'Comparatives & Superlatives', level: 'A2', category: 'adjectives', prereqs: ['be_and_there'] },
  { code: 'ed_ing_adjectives', title: '-ed vs -ing Adjectives', level: 'A2', category: 'adjectives', prereqs: ['be_and_there'] },
  { code: 'modals_ability_permission', title: 'Modals of Ability & Permission', level: 'A2', category: 'modals', prereqs: ['present_simple', 'question_forms'] },
  // B1 (9)
  { code: 'present_perfect_continuous', title: 'Present Perfect Continuous', level: 'B1', category: 'tenses', prereqs: ['present_perfect', 'present_continuous'] },
  { code: 'past_perfect', title: 'Past Perfect', level: 'B1', category: 'tenses', prereqs: ['past_simple', 'present_perfect'] },
  { code: 'first_conditional', title: 'First Conditional', level: 'B1', category: 'conditionals', prereqs: ['present_simple', 'future_will'] },
  { code: 'second_conditional', title: 'Second Conditional', level: 'B1', category: 'conditionals', prereqs: ['first_conditional', 'past_simple'] },
  { code: 'passive_present_past', title: 'Passive Voice (Present & Past)', level: 'B1', category: 'passive', prereqs: ['be_and_there', 'present_perfect'] },
  { code: 'relative_clauses', title: 'Relative Clauses', level: 'B1', category: 'clauses', prereqs: ['articles', 'be_and_there'] },
  { code: 'modals_obligation_advice', title: 'Modals of Obligation & Advice', level: 'B1', category: 'modals', prereqs: ['modals_ability_permission'] },
  { code: 'modals_deduction', title: 'Modals of Deduction', level: 'B1', category: 'modals', prereqs: ['modals_ability_permission', 'present_perfect'] },
  { code: 'reported_speech', title: 'Reported Speech', level: 'B1', category: 'clauses', prereqs: ['past_simple', 'present_perfect', 'question_forms'] },
  // B2 (7)
  { code: 'third_conditional', title: 'Third Conditional', level: 'B2', category: 'conditionals', prereqs: ['second_conditional', 'past_perfect'] },
  { code: 'passive_perfect_modal', title: 'Perfect & Modal Passive', level: 'B2', category: 'passive', prereqs: ['passive_present_past', 'modals_obligation_advice'] },
  { code: 'gerunds_infinitives', title: 'Gerunds & Infinitives', level: 'B2', category: 'verbs', prereqs: ['present_continuous', 'used_to'] },
  { code: 'causative_make_let', title: 'Causative (have/get) & make/let', level: 'B2', category: 'verbs', prereqs: ['passive_present_past', 'gerunds_infinitives'] },
  { code: 'future_perfect_continuous', title: 'Future Perfect & Continuous', level: 'B2', category: 'tenses', prereqs: ['future_will', 'present_perfect_continuous', 'future_going_to'] },
  { code: 'wish_if_only', title: 'Wish & If Only', level: 'B2', category: 'clauses', prereqs: ['second_conditional', 'past_perfect'] },
  { code: 'concession_linkers', title: 'Contrast & Concession Linkers', level: 'B2', category: 'discourse', prereqs: ['relative_clauses', 'gerunds_infinitives'] },
  // C1 (5)
  { code: 'mixed_conditionals', title: 'Mixed Conditionals', level: 'C1', category: 'conditionals', prereqs: ['third_conditional', 'wish_if_only'] },
  { code: 'inversion_emphasis', title: 'Negative Inversion', level: 'C1', category: 'advanced', prereqs: ['present_perfect', 'third_conditional'] },
  { code: 'cleft_sentences', title: 'Cleft Sentences', level: 'C1', category: 'advanced', prereqs: ['relative_clauses', 'passive_present_past'] },
  { code: 'participle_clauses', title: 'Participle Clauses', level: 'C1', category: 'clauses', prereqs: ['relative_clauses', 'passive_perfect_modal'] },
  { code: 'subjunctive_mandative', title: 'Mandative Subjunctive', level: 'C1', category: 'advanced', prereqs: ['modals_obligation_advice', 'reported_speech'] },
]

type PresetKey = 'b1-weak-spots' | 'a1-starter' | 'b2-advanced'

const preset = ref<PresetKey>('b1-weak-spots')

const titleByCode = new Map(CATALOGUE_SPEC.map((item) => [item.code, item.title]))

const fixtureCards = computed<RoadmapGraphCard[]>(() => {
  const completedSet = new Set<string>()
  const weakSpotSet = new Set<string>()
  const forceReadySet = new Set<string>()

  if (preset.value === 'a1-starter') {
    completedSet.add('present_simple')
  } else if (preset.value === 'b1-weak-spots') {
    for (const item of CATALOGUE_SPEC) {
      if (item.level === 'A1' || item.level === 'A2') {
        completedSet.add(item.code)
      }
    }
    weakSpotSet.add('articles')
    weakSpotSet.add('second_conditional')
    forceReadySet.add('second_conditional')
  } else {
    for (const item of CATALOGUE_SPEC) {
      if (item.level === 'A1' || item.level === 'A2' || item.level === 'B1') {
        completedSet.add(item.code)
      }
    }
    weakSpotSet.add('gerunds_infinitives')
  }

  return CATALOGUE_SPEC.map((item, idx) => {
    let status: LessonCardStatus = 'locked'
    if (completedSet.has(item.code)) {
      status = 'completed'
    } else if (
      forceReadySet.has(item.code)
      || item.prereqs.every((p) => completedSet.has(p))
    ) {
      status = 'ready'
    }

    const needsPractice = weakSpotSet.has(item.code)

    return {
      id: `dev-card-${idx + 1}`,
      orderIndex: idx + 1,
      status,
      practicePrompt: `Speak naturally using ${item.title} in a short personal story.`,
      needsPractice,
      recentMistakeCount: needsPractice ? 3 : 0,
      grammarPoint: {
        id: `dev-gp-${idx + 1}`,
        code: item.code,
        title: item.title,
        category: item.category,
        level: item.level,
        prerequisites: item.prereqs.map((code) => ({
          code,
          title: titleByCode.get(code) ?? code,
        })),
      },
    }
  })
})

const recommendedCard = computed(() =>
  pickRecommendedCard(
    fixtureCards.value,
    preset.value === 'a1-starter' ? 'A1' : preset.value === 'b1-weak-spots' ? 'B1' : 'B2',
  ),
)
</script>

<template>
  <section class="space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <h1 class="display text-[1.5rem]">Knowledge graph harness (38 nodes)</h1>

      <div class="flex flex-wrap gap-2">
        <button
          v-for="key in (['b1-weak-spots', 'a1-starter', 'b2-advanced'] as const)"
          :key="key"
          type="button"
          class="rounded-full border px-3 py-1 text-[0.8125rem]"
          :class="
            preset === key
              ? 'border-[var(--accent)] text-[var(--accent)]'
              : 'border-[var(--line)] text-[var(--ink-muted)]'
          "
          @click="preset = key"
        >
          {{ key }}
        </button>
      </div>
    </div>

    <RoadmapGraph
      :cards="fixtureCards"
      :recommended-card-id="recommendedCard?.id ?? null"
      preview-only
    />
  </section>
</template>
