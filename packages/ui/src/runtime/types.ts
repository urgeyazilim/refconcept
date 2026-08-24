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

// --- seller onboarding -------------------------------------------------------

export interface SellerLegalEntity {
  legal_name: string
  tax_office: string | null
  tax_number: string | null
  national_id: string | null
  mersis_number: string | null
  trade_registry_number: string | null
  kep_address: string | null
}

export interface SellerTaxProfile {
  taxpayer_type: 'corporate' | 'sole_proprietor' | 'individual'
  taxpayer_type_label: string
  default_vat_rate_bps: number
  e_invoice_enabled: boolean
  e_archive_enabled: boolean
}

export interface SellerContact {
  id: string
  type: string
  full_name: string
  email: string
  phone: string | null
  title: string | null
}

export interface SellerAddress {
  id: string
  type: string
  country_code: string
  city: string
  district: string | null
  address_line1: string
  address_line2: string | null
  postal_code: string | null
}

/** Only ever the masked form; the plaintext IBAN never leaves the server. */
export interface SellerBankAccount {
  id: string
  account_holder: string
  bank_name: string | null
  iban_masked: string
  currency: string
  is_primary: boolean
}

export interface SellerDocumentSummary {
  id: string
  type: string
  type_label: string
  original_name: string
  size_bytes: number
  status: 'pending' | 'approved' | 'rejected'
  status_label: string
  review_note: string | null
  uploaded_at: string | null
}

export type ApplicationStatus =
  | 'draft'
  | 'submitted'
  | 'in_review'
  | 'approved'
  | 'rejected'
  | 'withdrawn'

export interface SellerApplication {
  id: string
  company_name: string
  display_name: string
  legal_form: string
  contact_email: string
  contact_phone: string
  website: string | null
  product_categories: string | null
  status: ApplicationStatus
  status_label: string
  is_editable: boolean
  submitted_at: string | null
  reviewed_at: string | null
  decision_reason: string | null
  created_at: string | null
  legal_entity?: SellerLegalEntity | null
  tax_profile?: SellerTaxProfile | null
  contacts?: SellerContact[]
  addresses?: SellerAddress[]
  bank_accounts?: SellerBankAccount[]
  documents?: SellerDocumentSummary[]
  accepted_agreement_ids?: string[]
}

export interface OnboardingChecklistStep {
  step: string
  label: string
  completed: boolean
  detail: string | null
}

export interface OnboardingMeta {
  checklist: OnboardingChecklistStep[]
  completion_percent: number
  can_submit: boolean
}

export interface SellerAgreementSummary {
  id: string
  code: string
  version: string
  title: string
  body: string
  is_mandatory: boolean
  effective_from: string
  accepted: boolean
}
