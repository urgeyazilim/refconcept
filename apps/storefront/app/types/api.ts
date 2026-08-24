/**
 * Shapes returned by the RefConcept API.
 *
 * Hand-written for now; Phase 3 generates them from the OpenAPI document so the
 * client and server can never drift silently.
 */

export interface UserProfile {
  first_name: string | null
  last_name: string | null
  display_name: string | null
  avatar_path: string | null
  birth_date: string | null
  marketing_opt_in: boolean
}

export interface AuthUser {
  id: string
  email: string | null
  phone: string | null
  status: 'pending_verification' | 'active' | 'suspended' | 'banned'
  status_label: string
  locale: string
  timezone: string
  email_verified: boolean
  email_verified_at: string | null
  phone_verified: boolean
  last_login_at: string | null
  created_at: string | null
  profile?: UserProfile
}

export interface LoginResponse {
  token: string
  token_type: 'Bearer'
  expires_at: string | null
  user: AuthUser
}

export interface Address {
  id: string
  label: string | null
  recipient_name: string
  phone: string | null
  country_code: string
  city: string
  district: string | null
  neighbourhood: string | null
  address_line1: string
  address_line2: string | null
  postal_code: string | null
  is_default_shipping: boolean
  is_default_billing: boolean
  created_at: string | null
  updated_at: string | null
}

/** Laravel's validation error envelope. */
export interface ValidationErrors {
  [field: string]: string[]
}

export interface ApiErrorBody {
  message?: string
  errors?: ValidationErrors
  code?: string
}
