/**
 * Keeps signed-in visitors out of the sign-in and sign-up screens.
 */
export default defineNuxtRouteMiddleware(() => {
  const { isAuthenticated } = useAuth()

  if (isAuthenticated.value) {
    return navigateTo('/account')
  }
})
