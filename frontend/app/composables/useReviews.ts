import type { CombinedError } from '@urql/vue'
import { computed, onMounted, ref } from 'vue'
import {
  DUE_REVIEWS_QUERY,
  RECURRING_MISTAKES_QUERY,
  SUBMIT_REVIEW_RESULT_MUTATION,
} from '~/graphql/documents'
import type { RecurringMistake, ReviewItem } from '~/types/graphql'
import { AUTH_EXPIRED_MESSAGE, graphQLErrorMessageFor } from '~/utils/graphql-error'

/**
 * Manages the spaced repetition review queue and SM-2 quality submission (plan §4–6).
 *
 * Loads due reviews, handles 0–5 quality scoring per item, removes completed items
 * from the queue immediately upon submission, translates domain error codes,
 * and queries recurring mistakes with graceful degradation when the backend
 * field is unavailable.
 */
export function useReviews() {
  const { $urql } = useNuxtApp()

  const dueReviews = ref<ReviewItem[]>([])
  const recurringMistakes = ref<RecurringMistake[]>([])
  const loading = ref(true)
  const error = ref<string | null>(null)
  const submittingId = ref<string | null>(null)
  const itemErrors = ref<Record<string, string>>({})

  const dueCount = computed(() => dueReviews.value.length)

  function mapReviewError(failure: CombinedError | null | undefined): string {
    return graphQLErrorMessageFor(failure, {
      codes: {
        REVIEW_ITEM_NOT_FOUND: '復習する項目が見つかりません。',
        GRAMMAR_POINT_NOT_FOUND: '文法項目が見つかりません。',
        UNAUTHENTICATED: AUTH_EXPIRED_MESSAGE,
      },
      fallback: '復習の結果を保存できませんでした。',
    })
  }

  async function fetchDueReviews(): Promise<void> {
    try {
      const result = await $urql
        .query<{ dueReviews: ReviewItem[] }>(DUE_REVIEWS_QUERY, {}, { requestPolicy: 'network-only' })
        .toPromise()

      if (result.error) {
        error.value = mapReviewError(result.error)
        dueReviews.value = []
        return
      }

      dueReviews.value = result.data?.dueReviews ?? []
    } catch {
      error.value = '復習キューを読み込めませんでした。'
      dueReviews.value = []
    }
  }

  /**
   * Queries recurring mistakes, degrading gracefully (leaving empty array) if
   * the parallel backend workstream has not yet deployed the field.
   */
  async function fetchRecurringMistakes(): Promise<void> {
    try {
      const result = await $urql
        .query<{ recurringMistakes: RecurringMistake[] }>(
          RECURRING_MISTAKES_QUERY,
          {},
          { requestPolicy: 'network-only' },
        )
        .toPromise()

      if (result.error) {
        // Degrade gracefully: field might not be deployed yet in backend
        recurringMistakes.value = []
        return
      }

      recurringMistakes.value = result.data?.recurringMistakes ?? []
    } catch {
      recurringMistakes.value = []
    }
  }

  async function refresh(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      await Promise.all([fetchDueReviews(), fetchRecurringMistakes()])
    } finally {
      loading.value = false
    }
  }

  async function submitReview(grammarPointId: string, quality: number): Promise<boolean> {
    if (quality < 0 || quality > 5) {
      itemErrors.value[grammarPointId] = '評価は0から5の範囲で指定してください。'
      return false
    }

    submittingId.value = grammarPointId
    delete itemErrors.value[grammarPointId]

    try {
      const result = await $urql
        .mutation<{ submitReviewResult: ReviewItem }>(SUBMIT_REVIEW_RESULT_MUTATION, {
          grammarPointId,
          quality,
        })
        .toPromise()

      if (result.error) {
        itemErrors.value[grammarPointId] = mapReviewError(result.error)
        return false
      }

      // Remove from active due queue immediately for snappy UI feedback
      dueReviews.value = dueReviews.value.filter(
        (item) => item.grammarPoint.id !== grammarPointId,
      )

      return true
    } catch {
      itemErrors.value[grammarPointId] = '復習の結果を送信できませんでした。'
      return false
    } finally {
      submittingId.value = null
    }
  }

  onMounted(() => {
    void refresh()
  })

  return {
    dueReviews,
    recurringMistakes,
    loading,
    error,
    submittingId,
    itemErrors,
    dueCount,
    submitReview,
    refresh,
  }
}
