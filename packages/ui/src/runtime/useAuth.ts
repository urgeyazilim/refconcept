import type { AuthUser, LoginResponse } from './types'

/**
 * The API token.
 *
 * Held in a cookie rather than localStorage so server-side rendering can read it and
 * render the signed-in shell on the first paint instead of flashing a logged-out
 * header.
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
    /*
     * Secure whenever the page is actually served over TLS — which is not the same
     * question as "is this a production build".
     *
     * It used to be `!import.meta.dev`, and that assumption broke the first time the
     * application was deployed to a server without a certificate on it: a `Secure`
     * cookie on an `http://` origin is not stored at all, so signing in succeeded, the
     * token came back, the browser silently dropped it, and the next request bounced
     * straight back to the login form. Nothing failed anywhere and the password looked
     * wrong.
     *
     * Read from the real scheme instead. On HTTPS this is exactly what it was; on plain
     * HTTP it is the difference between a working deployment and one nobody can sign
     * into. `undefined` on the server, where the flag is decided by the response the
     * browser will receive rather than by this call.
     */
    secure: isSecureOrigin(),
    path: '/',
  })
}

/**
 * Whether the page was served over TLS.
 *
 * Only answerable in the browser. During server-side rendering there is no location to
 * read, so the flag is left off and the browser's own set-cookie decides — a cookie
 * written client-side moments later carries the right value either way.
 */
function isSecureOrigin(): boolean {
  return import.meta.client && window.location.protocol === 'https:'
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
