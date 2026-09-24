const GUEST_ROUTES = ['login', 'register']

/**
 * Keeps guests on the sign-in pages and signed-in users off them.
 *
 * The session is resolved once per page load: `me` fails with "Unauthenticated."
 * for a guest, which the client turns into a null user rather than an error the
 * page has to handle.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const { user, ensureResolved } = useAuth()

  await ensureResolved()

  const isGuestRoute = GUEST_ROUTES.includes(String(to.name))

  if (!user.value && !isGuestRoute) {
    return navigateTo('/login')
  }

  if (user.value && isGuestRoute) {
    return navigateTo('/roadmap')
  }
})
