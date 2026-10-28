import type { CombinedError, RequestPolicy } from '@urql/vue'
import { useQuery } from '@urql/vue'
import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { GENERATE_ROADMAP_MUTATION, ROADMAP_QUERY } from '~/graphql/documents'
import type { LessonCard, Roadmap } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { roadmap as roadmapCopy } from '~/constants/strings'

export interface UseRoadmapOptions {
  pause?: Ref<boolean> | ComputedRef<boolean> | boolean
  requestPolicy?: RequestPolicy
}

/**
 * Encapsulates roadmap query execution, derived progress metrics,
 * and roadmap generation mutation state.
 */
export function useRoadmap(options: UseRoadmapOptions = {}) {
  const { $urql } = useNuxtApp()

  const { data, fetching, error, executeQuery } = useQuery<{ roadmap: Roadmap | null }>({
    query: ROADMAP_QUERY,
    requestPolicy: options.requestPolicy ?? 'network-only',
    pause: options.pause,
  })

  const roadmap = computed(() => data.value?.roadmap ?? null)

  const readyCard = computed<LessonCard | null>(
    () => roadmap.value?.lessonCards.find((card) => card.status === 'ready') ?? null,
  )

  const completedCount = computed(
    () => roadmap.value?.lessonCards.filter((card) => card.status === 'completed').length ?? 0,
  )

  const totalCount = computed(() => roadmap.value?.lessonCards.length ?? 0)

  const isComplete = computed(
    () => totalCount.value > 0 && completedCount.value >= totalCount.value,
  )

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
    readyCard,
    completedCount,
    totalCount,
    isComplete,
    allDone: isComplete,
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
