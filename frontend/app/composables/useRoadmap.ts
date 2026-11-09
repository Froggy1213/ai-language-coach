import type { CombinedError, RequestPolicy } from '@urql/vue'
import { useQuery } from '@urql/vue'
import { computed, onMounted, ref, watch, type ComputedRef, type Ref } from 'vue'
import { GENERATE_ROADMAP_MUTATION, ROADMAP_QUERY } from '~/graphql/documents'
import type { LessonCard, Roadmap } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { roadmap as roadmapCopy } from '~/constants/strings'
import { pickRecommendedCard, type RoadmapGraphCard } from '~/utils/roadmap-graph'

export type RoadmapViewMode = 'graph' | 'list'

export type RoadmapLessonCard = LessonCard & RoadmapGraphCard

const ROADMAP_VIEW_STORAGE_KEY = 'kirie:roadmap-view'

export interface UseRoadmapOptions {
  pause?: Ref<boolean> | ComputedRef<boolean> | boolean
  requestPolicy?: RequestPolicy
}

/**
 * Encapsulates roadmap query execution, adaptive recommendation priority,
 * graph/list view persistence, and roadmap generation mutation state.
 */
export function useRoadmap(options: UseRoadmapOptions = {}) {
  const { $urql } = useNuxtApp()
  const { user } = useAuth()

  const { data, fetching, error, executeQuery } = useQuery<{ roadmap: Roadmap | null }>({
    query: ROADMAP_QUERY,
    requestPolicy: options.requestPolicy ?? 'network-only',
    pause: options.pause,
  })

  const roadmap = computed(() => data.value?.roadmap ?? null)

  const cards = computed<RoadmapLessonCard[]>(
    () => (roadmap.value?.lessonCards as RoadmapLessonCard[] | undefined) ?? [],
  )

  const availableCards = computed<RoadmapLessonCard[]>(() =>
    cards.value.filter((card) => card.status === 'ready'),
  )

  const weakSpotCards = computed<RoadmapLessonCard[]>(() =>
    cards.value.filter(
      (card) => Boolean(card.needsPractice) && card.status !== 'locked',
    ),
  )

  const readyCard = computed<RoadmapLessonCard | null>(() =>
    pickRecommendedCard(cards.value, user.value?.currentLevel ?? null),
  )

  const completedCount = computed(
    () => cards.value.filter((card) => card.status === 'completed').length,
  )

  const totalCount = computed(() => cards.value.length)

  const isComplete = computed(
    () => totalCount.value > 0 && completedCount.value >= totalCount.value,
  )

  const view = ref<RoadmapViewMode>('graph')

  onMounted(() => {
    try {
      const stored = window.localStorage.getItem(ROADMAP_VIEW_STORAGE_KEY)
      if (stored === 'graph' || stored === 'list') {
        view.value = stored
      }
    } catch {
      // Ignore storage access errors in private browsing contexts.
    }
  })

  watch(view, (nextView) => {
    if (typeof window === 'undefined') {
      return
    }
    try {
      window.localStorage.setItem(ROADMAP_VIEW_STORAGE_KEY, nextView)
    } catch {
      // Ignore storage access errors.
    }
  })

  function setView(nextView: RoadmapViewMode): void {
    view.value = nextView
  }

  const building = ref(false)
  const buildError = ref<string | null>(null)

  async function generateRoadmap(): Promise<void> {
    building.value = true
    buildError.value = null

    try {
      const { error: failure } = await $urql.mutation(GENERATE_ROADMAP_MUTATION, {}).toPromise()

      if (failure) {
        buildError.value = graphQLErrorMessageFor(failure as CombinedError, {
          fallback: roadmapCopy.buildError,
        })
      } else {
        await executeQuery({ requestPolicy: 'network-only' })
      }
    } catch {
      buildError.value = roadmapCopy.buildError
    } finally {
      building.value = false
    }
  }

  return {
    data,
    roadmap,
    cards,
    availableCards,
    weakSpotCards,
    readyCard,
    completedCount,
    totalCount,
    isComplete,
    allDone: isComplete,
    view,
    setView,
    building,
    buildError,
    generateRoadmap,
    buildRoadmap: generateRoadmap,
    generating: building,
    generateError: buildError,
    generate: generateRoadmap,
    fetching,
    error,
    executeQuery,
    refresh: executeQuery,
  }
}
