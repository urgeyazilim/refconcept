/**
 * Requires a signed-in account. Remembers where the visitor was heading so they land
 * there after signing in rather than on a generic home page.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const { token, user, fetchUser } = useAuth()

  if (!token.value) {
    return navigateTo(`/auth/login?redirect=${encodeURIComponent(to.fullPath)}`)
  }

  if (user.value === null) {
    await fetchUser()
  }

  if (user.value === null) {
    return navigateTo(`/auth/login?redirect=${encodeURIComponent(to.fullPath)}`)
  }
})
