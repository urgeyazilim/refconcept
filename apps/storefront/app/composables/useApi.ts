import type { ApiErrorBody, ValidationErrors } from '~/types/api'

/**
 * A normalised API error.
 *
 * Every caller needs the same three things — a message to show, field errors to
 * attach to inputs, and the status to branch on — so they are extracted once here
 * rather than in each form's catch block.
 */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors: ValidationErrors = {},
    readonly code?: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Validation failure: the user can fix this by changing the form. */
  get isValidation(): boolean {
    return this.status === 422
  }

  get isUnauthorized(): boolean {
    return this.status === 401
  }

  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

/**
 * The single place the storefront talks to the API.
 *
 * Attaches the bearer token, sends JSON, and converts any failure into an ApiError
 * so no component ever inspects a raw fetch rejection.
 */
export function useApi() {
  const config = useRuntimeConfig()
  const token = useAuthToken()

  async function request<T>(path: string, options: Record<string, unknown> = {}): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...(options.headers as Record<string, string> | undefined),
    }

    if (token.value) {
      headers.Authorization = `Bearer ${token.value}`
    }

    try {
      return await $fetch<T>(path, {
        baseURL: config.public.apiBase,
        ...options,
        headers,
      })
    } catch (error: unknown) {
      throw toApiError(error)
    }
  }

  return {
    get: <T>(path: string, query?: Record<string, unknown>) =>
      request<T>(path, { method: 'GET', query }),
    post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
    patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
    delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  }
}

function toApiError(error: unknown): ApiError {
  const candidate = error as { status?: number; statusCode?: number; data?: ApiErrorBody }
  const status = candidate.status ?? candidate.statusCode ?? 0
  const data = candidate.data ?? {}

  if (status === 0) {
    return new ApiError(
      'Sunucuya ulaşılamadı. Bağlantınızı kontrol edip tekrar deneyin.',
      0,
    )
  }

  return new ApiError(
    data.message ?? 'Beklenmeyen bir hata oluştu.',
    status,
    data.errors ?? {},
    data.code,
  )
}
