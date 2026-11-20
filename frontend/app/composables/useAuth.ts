import type { CombinedError } from '@urql/vue'
import {
  FORGOT_PASSWORD_MUTATION,
  LOGIN_MUTATION,
  LOGOUT_MUTATION,
  ME_QUERY,
  REGISTER_MUTATION,
  RESET_PASSWORD_MUTATION,
  SEND_EMAIL_VERIFICATION_MUTATION,
  UPDATE_PASSWORD_MUTATION,
  UPDATE_PROFILE_MUTATION,
  VERIFY_EMAIL_MUTATION,
} from '~/graphql/documents'
import type { CurrentUser } from '~/types/view-models'
import { graphQLErrorCode, graphQLErrorMessage } from '~/utils/graphql-error'

function isUnauthenticatedError(error: CombinedError): boolean {
  if (graphQLErrorCode(error) === 'UNAUTHENTICATED') {
    return true
  }

  if (graphQLErrorMessage(error) === 'Unauthenticated.') {
    return true
  }

  const status = (error.response as { status?: number } | undefined)?.status
  return status === 401
}

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
    try {
      const { data, error } = await $urql
        .query<{ me: CurrentUser | null }>(ME_QUERY, {}, { requestPolicy: 'network-only' })
        .toPromise()

      if (error) {
        if (isUnauthenticatedError(error)) {
          user.value = null
          resolved.value = true
        }

        return user.value
      }

      user.value = data?.me ?? null
      resolved.value = true

      return user.value
    } catch {
      // Transient network error: preserve current user state and leave `resolved`
      // unchanged so subsequent navigation retries `ME_QUERY`.
      return user.value
    }
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

  async function clearSession(): Promise<void> {
    user.value = null
    resolved.value = true
    if (import.meta.client) {
      window.location.assign('/login')
    } else {
      await navigateTo('/login')
    }
  }

  async function logout(): Promise<void> {
    const { error } = await $urql.mutation(LOGOUT_MUTATION, {}).toPromise()

    if (error && !isUnauthenticatedError(error)) {
      throw error
    }

    await clearSession()
  }

  async function updateProfile(name: string, email: string): Promise<CurrentUser | null> {
    const { error } = await $urql
      .mutation(UPDATE_PROFILE_MUTATION, { name, email })
      .toPromise()

    if (error) {
      throw error
    }

    return await refresh()
  }

  async function updatePassword(currentPassword: string, newPassword: string): Promise<void> {
    const { error } = await $urql
      .mutation(UPDATE_PASSWORD_MUTATION, { currentPassword, newPassword })
      .toPromise()

    if (error) {
      throw error
    }
  }

  async function forgotPassword(email: string): Promise<void> {
    const { error } = await $urql
      .mutation(FORGOT_PASSWORD_MUTATION, { email })
      .toPromise()

    if (error) {
      throw error
    }
  }

  async function resetPassword(token: string, email: string, password: string): Promise<void> {
    const { error } = await $urql
      .mutation(RESET_PASSWORD_MUTATION, { token, email, password })
      .toPromise()

    if (error) {
      throw error
    }
  }

  async function sendEmailVerification(): Promise<void> {
    const { error } = await $urql
      .mutation(SEND_EMAIL_VERIFICATION_MUTATION, {})
      .toPromise()

    if (error) {
      throw error
    }
  }

  async function verifyEmail(id: string, hash: string, expires: number, signature: string): Promise<void> {
    const { error } = await $urql
      .mutation(VERIFY_EMAIL_MUTATION, { id, hash, expires, signature })
      .toPromise()

    if (error) {
      throw error
    }

    if (user.value) {
      await refresh()
    }
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
    updateProfile,
    updatePassword,
    forgotPassword,
    resetPassword,
    sendEmailVerification,
    verifyEmail,
    clearSession,
  }
}

export type { CombinedError }
