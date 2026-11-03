import { useQuery } from '@urql/vue'
import { computed } from 'vue'
import { SCENARIOS_QUERY } from '~/graphql/documents'
import type { Scenario } from '~/types/graphql'

/**
 * The scenario catalogue for the learner's target language.
 *
 * The list is server-scoped to the authenticated learner (`scenarios` resolves
 * the catalogue for their target language), so the client never sends a
 * language. It is small and stable, so `cache-and-network` gives an instant list
 * on a revisit without ever showing a stale catalogue for a whole session.
 *
 * `find` reads the same reactive list: a page that needs one scenario by code
 * can call it from a computed and get the result as the query settles.
 */
export function useScenarios() {
  const { data, fetching, error, executeQuery } = useQuery<{ scenarios: Scenario[] }>({
    query: SCENARIOS_QUERY,
    requestPolicy: 'cache-and-network',
  })

  const scenarios = computed<Scenario[]>(() => data.value?.scenarios ?? [])

  function find(code: string): Scenario | null {
    return scenarios.value.find((scenario) => scenario.code === code) ?? null
  }

  /** Re-reads the catalogue from the network, for the error state's retry. */
  async function refresh(): Promise<void> {
    await executeQuery({ requestPolicy: 'network-only' })
  }

  return {
    scenarios,
    find,
    fetching,
    error,
    refresh,
  }
}
