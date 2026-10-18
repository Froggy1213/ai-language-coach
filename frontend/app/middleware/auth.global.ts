const GUEST_ONLY_ROUTES = ['login', 'register']
const PUBLIC_ROUTES = ['index', 'login', 'register']

/**
 * Keeps guests on public/sign-in pages and signed-in users off login/register.
 *
 * The root route (`/`, named `index`) renders the scrollytelling landing page for
 * guests and the daily home (`今日`) for signed-in learners.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const { user, ensureResolved } = useAuth()

  await ensureResolved()

  const routeName = String(to.name)
  const isPublicRoute = PUBLIC_ROUTES.includes(routeName)
  const isGuestOnlyRoute = GUEST_ONLY_ROUTES.includes(routeName)

  if (!user.value && !isPublicRoute) {
    return navigateTo('/login')
  }

  if (user.value && isGuestOnlyRoute) {
    return navigateTo('/')
  }
})

