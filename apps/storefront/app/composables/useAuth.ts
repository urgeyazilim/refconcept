import type { AuthUser, LoginResponse } from '~/types/api'

/**
 * The API token.
 *
 * Held in a cookie rather than localStorage so server-side rendering can read it and
 * render the signed-in shell on the first paint instead of flashing a logged-out
 * header. Marked sameSite=lax and, outside development, secure.
 *
 * Note: this cookie is readable by JavaScript by design — the token is attached as a
 * bearer header, not sent automatically — so XSS hardening (CSP, escaping) is what
 * protects it. Moving to an httpOnly cookie + BFF proxy is Phase 21 work.
 */
export function useAuthToken() {
  return useCookie<string | null>('rc_token', {
    default: () => null,
    maxAge: 60 * 60 * 24 * 30,
    sameSite: 'lax',
    secure: !import.meta.dev,
    path: '/',
  })
}

export function useAuth() {
  const token = useAuthToken()
  const user = useState<AuthUser | null>('auth:user', () => null)
  const api = useApi()

  const isAuthenticated = computed(() => Boolean(token.value) && user.value !== null)
  const isVerified = computed(() => user.value?.email_verified === true)

  const displayName = computed(() => {
    const profile = user.value?.profile

    return (
      profile?.display_name
      || [profile?.first_name, profile?.last_name].filter(Boolean).join(' ')
      || user.value?.email
      || ''
    )
  })

  /**
   * Loads the signed-in user. A rejected token is cleared rather than retried:
   * keeping a dead token means every later request fails the same way.
   */
  async function fetchUser(): Promise<AuthUser | null> {
    if (!token.value) {
      user.value = null

      return null
    }

    try {
      const response = await api.get<{ data: AuthUser }>('/api/v1/auth/me')
      user.value = response.data

      return user.value
    } catch (error) {
      if (error instanceof ApiError && (error.isUnauthorized || error.status === 403)) {
        token.value = null
        user.value = null

        return null
      }

      throw error
    }
  }

  async function login(email: string, password: string): Promise<AuthUser> {
    const response = await api.post<{ data: LoginResponse }>('/api/v1/auth/login', {
      email,
      password,
      device_name: 'storefront',
    })

    token.value = response.data.token
    user.value = response.data.user

    return response.data.user
  }

  /**
   * Registration deliberately does not sign the user in: the API issues no token
   * until the address is verified.
   */
  async function register(payload: Record<string, unknown>): Promise<AuthUser> {
    const response = await api.post<{ data: AuthUser }>('/api/v1/auth/register', payload)

    return response.data
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/api/v1/auth/logout')
    } catch {
      // Already invalid server-side; clearing locally is still the right outcome.
    } finally {
      token.value = null
      user.value = null
      await navigateTo('/')
    }
  }

  return {
    token,
    user,
    isAuthenticated,
    isVerified,
    displayName,
    fetchUser,
    login,
    register,
    logout,
  }
}
