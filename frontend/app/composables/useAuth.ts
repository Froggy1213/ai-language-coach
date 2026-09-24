import type { CombinedError } from '@urql/vue'
import { LOGIN_MUTATION, LOGOUT_MUTATION, ME_QUERY, REGISTER_MUTATION } from '~/graphql/documents'
import type { CurrentUser } from '~/types/graphql'

/**
 * Session state for the SPA, shared through `useState` so the header, the
 * route guard and the pages all read the same user.
 *
 * The guard resolves the session once per page load; `login` and `logout`
 * refresh it themselves, which is what keeps a stale "guest" state from
 * bouncing a just-signed-in user back to the form.
 */
export function useAuth() {
  const { $urql } = useNuxtApp()
  const user = useState<CurrentUser | null>('auth:user', () => null)
  const resolved = useState<boolean>('auth:resolved', () => false)

  async function refresh(): Promise<CurrentUser | null> {
    const { data } = await $urql
      .query<{ me: CurrentUser | null }>(ME_QUERY, {}, { requestPolicy: 'network-only' })
      .toPromise()

    user.value = data?.me ?? null
    resolved.value = true

    return user.value
  }

  async function login(email: string, password: string): Promise<void> {
    const { error } = await $urql.mutation(LOGIN_MUTATION, { email, password }).toPromise()

    if (error) {
      throw error
    }

    await refresh()
  }

  async function register(name: string, email: string, password: string, targetLanguage: string): Promise<void> {
    const { error } = await $urql
      .mutation(REGISTER_MUTATION, { name, email, password, targetLanguage })
      .toPromise()

    if (error) {
      throw error
    }

    await refresh()
  }

  async function logout(): Promise<void> {
    await $urql.mutation(LOGOUT_MUTATION, {}).toPromise()

    user.value = null
    resolved.value = true

    await navigateTo('/login')
  }

  async function ensureResolved(): Promise<void> {
    if (!resolved.value) {
      await refresh()
    }
  }

  return {
    user,
    resolved,
    refresh,
    ensureResolved,
    login,
    register,
    logout,
  }
}

export type { CombinedError }
