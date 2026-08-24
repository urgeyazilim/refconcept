/**
 * Requires a proven e-mail address. Mirrors the API's own gate so the user is sent to
 * the "check your inbox" screen instead of hitting a 403 from a form submission.
 */
export default defineNuxtRouteMiddleware(() => {
  const { user } = useAuth()

  if (user.value !== null && !user.value.email_verified) {
    return navigateTo('/auth/verify-email')
  }
})
