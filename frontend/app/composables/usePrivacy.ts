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
        return 'Для подтверждения удаления необходимо ввести DELETE.'
      case 'PASSWORD_MISMATCH':
        return 'Неверный пароль.'
      default:
        return 'Не удалось удалить аккаунт. Попробуйте позже.'
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
      error.value = 'Не удалось удалить аккаунт. Попробуйте позже.'
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
