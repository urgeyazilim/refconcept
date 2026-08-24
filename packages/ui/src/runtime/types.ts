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

// --- catalogue and products ---------------------------------------------------

/**
 * A monetary amount.
 *
 * `amount_minor` is the integer of minor units and is the only field arithmetic may
 * touch; `formatted` is the server's rendering, used for display so that one screen
 * cannot format a price differently from another.
 */
export interface MoneyValue {
  amount_minor: number
  currency: string
  formatted: string
}

export type ProductStatus = 'draft' | 'active' | 'archived'

export type ModerationStatus =
  | 'draft'
  | 'pending_review'
  | 'in_review'
  | 'approved'
  | 'rejected'

export interface ProductMediaItem {
  id: string
  type: string
  url: string
  alt_text: string | null
  position: number
  is_cover: boolean
}

export interface ProductDimensions {
  width_mm: number | null
  height_mm: number | null
  depth_mm: number | null
  weight_g: number | null
  display: string | null
  assembly_required: boolean
}

export interface ProductSkuItem {
  id: string
  sku: string
  variant_label: string | null
  status: 'draft' | 'active' | 'paused' | 'out_of_stock' | 'archived'
  status_label: string
  list_price: MoneyValue
  sale_price: MoneyValue | null
  effective_price: MoneyValue
  discount_bps: number
  tax_rate_bps: number
  stock_policy: 'track' | 'always_available' | 'made_to_order'
  stock_quantity: number | null
  lead_time_days: number
  is_available: boolean
  dimensions: ProductDimensions | null
  seller: { id: string, display_name: string, seller_code: string } | null
}

export interface ProductAttributeValueItem {
  code: string | null
  name: string | null
  unit: string | null
  /** The stored code — what a form posts back. */
  value: string | number | boolean | null
  /** The human label — what a customer reads. */
  display: string | number | boolean | null
}

export interface ProductSummaryRef {
  id: string
  name: string
  slug: string
}

export interface Product {
  id: string
  name: string
  slug: string
  description: string | null
  product_type: string
  status: ProductStatus
  status_label: string
  moderation_status: ModerationStatus
  moderation_status_label: string
  is_editable: boolean
  published_at: string | null
  brand?: ProductSummaryRef | null
  category?: (ProductSummaryRef & { path: string, room_type: string | null }) | null
  style?: { id: string, code: string, name: string } | null
  media?: ProductMediaItem[]
  attributes?: ProductAttributeValueItem[]
  skus?: ProductSkuItem[]
  /** Cheapest purchasable offer — null when nothing is on sale. */
  from_price: MoneyValue | null
  /** Cheapest offer regardless of availability, for seller and moderation screens. */
  lowest_price?: MoneyValue | null
  created_at: string | null
  updated_at: string | null
}

/** What the seller still has to supply before a listing can be reviewed. */
export interface ProductCompletenessMeta {
  missing_requirements: string[]
  completion_percent: number
  can_submit: boolean
}

export interface ModerationDecision {
  decision: string
  reason: string | null
  flagged_fields: string[] | null
  decided_by: string | null
  decided_at: string
}

export interface CatalogCategory {
  id: string
  parent_id: string | null
  name: string
  slug: string
  path: string
  depth: number
  room_type: string | null
}

export interface CatalogAttribute {
  code: string
  name: string
  data_type: string
  unit: string | null
  is_required: boolean
  is_variant_defining: boolean
  values: Array<{ value: string, label: string }>
}

export interface CatalogVocabulary {
  colors: Array<{ code: string, name: string, hex: string | null, family: string | null }>
  materials: Array<{ code: string, name: string, family: string | null }>
  styles: Array<{ code: string, name: string, description: string | null }>
}

/** Laravel's paginator envelope, as the resource collections emit it. */
export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

// --- import, pricing and inventory ---------------------------------------------

export type ImportStatus =
  | 'uploaded'
  | 'analysing'
  | 'mapped'
  | 'validating'
  | 'validated'
  | 'importing'
  | 'completed'
  | 'failed'

export interface ImportField {
  field: string
  label: string
  required: boolean
}

export interface ImportBatchSummary {
  id: string
  original_name: string
  status: ImportStatus
  status_label: string
  is_running: boolean
  total_rows: number
  valid_rows: number
  error_rows: number
  created_rows: number
  updated_rows: number
  progress_percent: number
  failure_reason: string | null
  created_at: string | null
  committed_at: string | null
}

export interface ImportBatchDetail extends ImportBatchSummary {
  detected_headers: string[]
  /** Spreadsheet header → field name. Absent keys are columns the seller ignored. */
  mapping: Record<string, string>
  fields: ImportField[]
  missing_required: string[]
  can_validate: boolean
  can_commit: boolean
}

export interface ImportRowResult {
  line_number: number
  status: 'pending' | 'valid' | 'invalid' | 'imported' | 'skipped'
  status_label: string
  action: 'create' | 'update' | 'skip' | null
  raw: Record<string, string>
  errors: string[]
}

/** Where the price a customer sees actually came from. */
export type PriceSource = 'sku' | 'default_list' | 'campaign'

export interface SellerPriceRow {
  sku_id: string
  sku: string
  product_name: string | null
  variant_label: string | null
  list_price: MoneyValue
  sale_price: MoneyValue | null
  effective_price: MoneyValue
  tax_rate_bps: number
  price_source: PriceSource
}

export interface PriceHistoryEntry {
  field: 'list_price' | 'sale_price'
  old_price: MoneyValue | null
  new_price: MoneyValue | null
  change_bps: number | null
  source: 'manual' | 'import' | 'api' | 'campaign' | 'system'
  author: string | null
  changed_at: string
}

export interface PriceListSummary {
  id: string
  code: string
  name: string
  currency: string
  is_default: boolean
  status: string
  is_effective: boolean
  starts_at: string | null
  ends_at: string | null
  item_count: number
}

export interface StockLocationSummary {
  id: string
  code: string
  name: string
  type: string
  type_label: string
  city: string | null
  is_default: boolean
  is_active: boolean
}

export interface StockRow {
  id: string
  sku: {
    id: string | null
    code: string | null
    variant_label: string | null
    product_name: string | null
  }
  location: { id: string | null, name: string | null, code: string | null }
  on_hand: number
  /** Spoken for but not yet dispatched. */
  reserved: number
  /** What can still be promised: on_hand minus reserved. */
  sellable: number
  reorder_point: number
  needs_attention: boolean
  counted_at: string | null
}

export interface StockMovementEntry {
  id: string
  type: string
  type_label: string
  quantity: number
  on_hand_after: number
  reserved_after: number
  reason: string | null
  reference_type: string | null
  author: string | null
  created_at: string
}

export interface ApiCredentialSummary {
  id: string
  name: string
  key_id: string
  secret_hint: string
  scopes: string[]
  rate_limit_per_minute: number
  is_usable: boolean
  last_used_at: string | null
  expires_at: string | null
  revoked_at: string | null
  revoked_reason: string | null
  created_at: string | null
  /** Returned exactly once, by the creation request. Never fetchable again. */
  secret?: string
  secret_notice?: string
}

export interface ApiUsageEntry {
  method: string
  path: string
  status: number
  ok: boolean
  duration_ms: number
  created_at: string
}
