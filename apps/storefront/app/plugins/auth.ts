/**
 * Resolves the signed-in user once per request, before any page renders.
 *
 * Runs on the server so the first paint already knows whether somebody is signed in;
 * without it the header would render logged-out and then flip, which reads as a bug.
 */
export default defineNuxtPlugin(async () => {
  const { token, user, fetchUser } = useAuth()

  if (token.value && user.value === null) {
    try {
      await fetchUser()
    } catch {
      // A failing API must not prevent the site from rendering.
    }
  }
})
