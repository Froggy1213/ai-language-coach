import type { CombinedError } from '@urql/vue'

/**
 * A message worth showing the user.
 *
 * Lighthouse reports a failed validation as a generic "Validation failed for
 * the field [login]." plus a per-field map, so the field messages are what the
 * form should display; everything else falls back to the error itself.
 */
export function graphQLErrorMessage(error: CombinedError | null | undefined): string | null {
  if (!error) {
    return null
  }

  const extensions = error.graphQLErrors[0]?.extensions as { validation?: Record<string, string[]> } | undefined
  const fieldMessage = extensions?.validation ? Object.values(extensions.validation)[0]?.[0] : undefined

  return fieldMessage ?? error.graphQLErrors[0]?.message ?? error.message
}
