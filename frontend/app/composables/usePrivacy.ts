import { DELETE_ACCOUNT_MUTATION } from '~/graphql/documents'
import { graphQLErrorCode } from '~/utils/graphql-error'

export function usePrivacy() {
  const { $urql } = useNuxtApp()
  const { user } = useAuth()

  const deleting = ref(false)
  const error = ref<string | null>(null)

  function translateError(code?: string): string {
    switch (code) {
      case 'CONFIRMATION_REQUIRED':
        return '削除を確定するには DELETE と入力してください。'
      case 'PASSWORD_MISMATCH':
        return 'パスワードが正しくありません。'
      default:
        return 'アカウントを削除できませんでした。しばらくしてからお試しください。'
    }
  }

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
        const code = graphQLErrorCode(result.error) ?? undefined
        error.value = translateError(code)
        return false
      }

      // Clear local session state and navigate to /login
      user.value = null

      if (import.meta.client) {
        window.location.assign('/login')
      } else {
        await navigateTo('/login')
      }

      return true
    } catch {
      error.value = 'アカウントを削除できませんでした。しばらくしてからお試しください。'
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
