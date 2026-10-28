import { DELETE_ACCOUNT_MUTATION } from '~/graphql/documents'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'

export function usePrivacy() {
  const { $urql } = useNuxtApp()
  const { clearSession } = useAuth()

  const deleting = ref(false)
  const error = ref<string | null>(null)

  const fallbackError = 'アカウントを削除できませんでした。しばらくしてからお試しください。'

  async function deleteAccount(password: string, confirmation: string): Promise<boolean> {
    deleting.value = true
    error.value = null

    try {
      const result = await $urql
        .mutation<{ deleteAccount: boolean }>(DELETE_ACCOUNT_MUTATION, {
          password,
          confirmation,
        })
        .toPromise()

      if (result.error) {
        error.value = graphQLErrorMessageFor(result.error, {
          fallback: fallbackError,
        })
        return false
      }

      await clearSession()
      return true
    } catch {
      error.value = fallbackError
      return false
    } finally {
      deleting.value = false
    }
  }

  return {
    deleting,
    error,
    deleteAccount,
  }
}
