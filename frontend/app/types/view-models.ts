import type { CefrLevel } from '~/types/graphql'

/**
 * Hand-written UI projection of the authenticated user in the SPA session state (`useAuth`).
 *
 * This is not a schema type because it projects only the subset of fields requested
 * by `ME_QUERY` for global auth/session state, rather than the complete GraphQL `User`.
 * Pages with richer requirements (such as `app/pages/settings.vue`) keep their own
 * query data types (e.g. `MeData`).
 */
export interface CurrentUser {
  id: string
  name: string
  targetLanguage: string
  currentLevel: CefrLevel
}
