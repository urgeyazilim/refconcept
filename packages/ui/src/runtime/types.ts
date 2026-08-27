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

// --- projects, rooms and designs ------------------------------------------------

export type ProjectStatus = 'draft' | 'active' | 'completed' | 'archived'
export type ProjectRole = 'editor' | 'viewer'

export type MeasurementQuality = 'unknown' | 'estimated' | 'manual' | 'scanned' | 'verified'

export interface Option {
  value: string
  label: string
}

export interface ProjectSummary {
  id: string
  name: string
  project_type: string
  project_type_label: string
  status: ProjectStatus
  status_label: string
  budget: MoneyValue | null
  room_count: number
  /** Whether any room has a photograph — deliberately not a link to one. */
  has_cover: boolean
  is_owner: boolean
  can_edit: boolean
  member_count: number
  created_at: string | null
  updated_at: string | null
}

export interface ProjectRoomSummary {
  id: string
  name: string
  room_type: string
  room_type_label: string
  measurement_quality: MeasurementQuality
  measurement_quality_label: string
  width_mm: number | null
  length_mm: number | null
  height_mm: number | null
  floor_area_m2: number | null
  has_photo: boolean
  constraint_count: number
  is_ready_for_design: boolean
}

export interface ProjectMemberSummary {
  id: string
  email: string
  name: string | null
  role: ProjectRole
  role_label: string
  status: 'invited' | 'active' | 'revoked'
  accepted_at: string | null
}

export interface ProjectDetail extends ProjectSummary {
  notes: string | null
  address: { id: string, label: string | null, city: string, district: string | null } | null
  rooms: ProjectRoomSummary[]
  members: ProjectMemberSummary[]
}

export interface RoomConstraintItem {
  id: string
  type: string
  type_label: string
  label: string | null
  wall: string | null
  offset_mm: number | null
  width_mm: number | null
  height_mm: number | null
  sill_height_mm: number | null
  is_blocking: boolean
  must_stay_visible: boolean
  /** Whether the engine can reason about it, or it is only a note to the customer. */
  is_placed: boolean
  description: string
  notes: string | null
}

export interface RoomDetail {
  id: string
  project_id: string
  name: string
  room_type: string
  room_type_label: string
  measurement_quality: MeasurementQuality
  measurement_quality_label: string
  measurement_confidence_bps: number
  width_mm: number | null
  length_mm: number | null
  height_mm: number | null
  floor_area_m2: number | null
  notes: string | null
  primary_media_id: string | null
  is_ready_for_design: boolean
  missing_for_design: string[]
  photo_count: number
  design_count: number
  constraints: RoomConstraintItem[]
}

/**
 * A room photograph.
 *
 * Deliberately carries no URL: a link is a separate request that checks ownership and
 * expires in five minutes.
 */
export interface RoomMediaItem {
  id: string
  type: 'photo' | 'floor_plan' | 'inspiration' | 'document'
  original_name: string
  mime_type: string
  size_bytes: number
  width: number | null
  height: number | null
  caption: string | null
  position: number
  is_primary: boolean
  uploaded_at: string | null
}

export type DesignVersionStatus = 'pending' | 'generating' | 'ready' | 'failed'

export interface DesignTreeNode {
  id: string
  version_number: number
  status: DesignVersionStatus
  status_label: string
  user_prompt: string | null
  style_code: string | null
  render_quality: 'draft' | 'premium'
  failure_reason: string | null
  credit_cost: number
  is_current: boolean
  /** The render itself, signed and short-lived. Null while generating, or after a failure. */
  image_url: string | null
  created_at: string | null
  children: DesignTreeNode[]
}

export interface DesignSummary {
  id: string
  name: string
  status: 'draft' | 'generating' | 'ready' | 'failed' | 'archived'
  status_label: string
  version_count: number
  current_version_number: number | null
  total_credit_cost: number
  created_at: string | null
}

export interface DesignDetail extends DesignSummary {
  current_version: {
    id: string
    version_number: number
    status: DesignVersionStatus
    user_prompt: string | null
  } | null
  /**
   * The photograph the design was built from.
   *
   * Sent so a screen can show before and after together. A render on its own is a picture
   * of a room; next to the room it came from it answers the question the customer asked.
   */
  source_image_url: string | null
  tree: DesignTreeNode[]
}

/*
 * Checkout and payments.
 *
 * The totals are all integer minor units and stay that way until they are formatted for
 * display. A float here would be a rounding error in somebody's bank statement.
 */

export type CheckoutPurpose = 'cart' | 'credits'

export type CheckoutStatus =
  | 'open'
  | 'awaiting_payment'
  | 'paid'
  | 'failed'
  | 'cancelled'
  | 'expired'

export type PaymentStatus =
  | 'created'
  | 'requires_action'
  | 'processing'
  | 'authorized'
  | 'captured'
  | 'partially_refunded'
  | 'refunded'
  | 'failed'
  | 'cancelled'
  | 'expired'

export interface CheckoutLine {
  type: 'product' | 'credit_package'
  name: string
  quantity: number
  unit_price_minor: number
  line_total_minor: number
  credits?: number
  bonus_credits?: number
}

export interface CheckoutTotals {
  subtotal_minor: number
  discount_minor: number
  shipping_minor: number
  tax_minor: number
  grand_total_minor: number
}

export interface PaymentSummary {
  id: string
  status: PaymentStatus
  status_label: string
  gateway: string
  method: string
  amount_minor: number
  currency: string
  captured_minor: number
  refunded_minor: number
  reference: string | null
  /** Only ever present while the payment is waiting on the customer's bank. */
  redirect_url: string | null
  failure_message: string | null
  created_at: string | null
}

export interface CheckoutSession {
  id: string
  purpose: CheckoutPurpose
  status: CheckoutStatus
  status_label: string
  currency: string
  totals: CheckoutTotals
  lines: CheckoutLine[]
  shipping_address: Record<string, string | null> | null
  billing_address: Record<string, string | null> | null
  expires_at: string | null
  payment: PaymentSummary | null
}

export interface PaymentMethodOption {
  gateway: string
  is_default: boolean
}

/*
 * Bank transfer.
 *
 * `expected_minor` and `received_minor` are separate because the second is a claim about
 * the world rather than a copy of the first: people transfer the wrong figure, and the
 * difference is the whole reason this method needs a screen of its own.
 */

export type BankTransferStatus =
  | 'awaiting_transfer'
  | 'under_review'
  | 'confirmed'
  | 'short_paid'
  | 'over_paid'
  | 'rejected'
  | 'expired'

export interface BankAccountOption {
  id: string
  bank_name: string
  branch: string | null
  account_holder: string
  iban: string
  currency: string
  note: string | null
}

export interface BankTransferDetail {
  id: string
  reference: string
  status: BankTransferStatus
  status_label: string
  message: string
  expected_minor: number
  received_minor: number | null
  shortfall_minor: number | null
  currency: string
  expires_at: string | null
  bank_account: BankAccountOption | null
  receipt_count: number
}

export interface BankTransferRow {
  id: string
  reference: string
  status: BankTransferStatus
  status_label: string
  expected_minor: number
  received_minor: number | null
  shortfall_minor: number
  currency: string
  customer_email: string | null
  bank_account: string | null
  value_date: string | null
  expires_at: string | null
  created_at: string | null
  is_decidable: boolean
  receipt_count: number
}

/*
 * Orders.
 *
 * A marketplace order is read two ways: the customer sees one order made of several
 * parcels, and each seller sees only their own. The two shapes are separate on purpose —
 * a seller's payload must not be a filtered view of the customer's, because a filter is
 * something somebody can forget to apply.
 */

export type OrderStatus =
  | 'paid'
  | 'processing'
  | 'partially_shipped'
  | 'shipped'
  | 'delivered'
  | 'cancelled'
  | 'refunded'
  | 'partially_refunded'

export type SellerOrderStatus =
  | 'awaiting_confirmation'
  | 'confirmed'
  | 'preparing'
  | 'shipped'
  | 'delivered'
  | 'cancelled'
  | 'returned'

export interface OrderLine {
  id: string
  product_name: string
  sku_code: string | null
  variant_label: string | null
  image_url: string | null
  quantity: number
  unit_price_minor: number
  line_total_minor: number
  tax_minor: number
  design_match_id: string | null
}

export interface OrderSellerGroup {
  id: string
  seller_order_number: string
  seller_name: string | null
  status: SellerOrderStatus
  status_label: string
  total_minor: number
  shipped_at: string | null
  delivered_at: string | null
  items: OrderLine[]
}

export interface OrderSummary {
  id: string
  order_number: string
  status: OrderStatus
  status_label: string
  currency: string
  item_count: number
  totals: {
    subtotal_minor: number
    discount_minor: number
    shipping_minor: number
    tax_minor: number
    grand_total_minor: number
  }
  placed_at: string
}

export interface OrderDetail extends OrderSummary {
  shipping_address: Record<string, string | null> | null
  billing_address: Record<string, string | null> | null
  customer_note: string | null
  sellers: OrderSellerGroup[]
}

export interface SellerOrderSummary {
  id: string
  seller_order_number: string
  order_number: string | null
  status: SellerOrderStatus
  status_label: string
  currency: string
  subtotal_minor: number
  tax_minor: number
  total_minor: number
  commission_minor: number
  payable_minor: number
  item_count: number
  placed_at: string | null
  confirmed_at: string | null
  shipped_at: string | null
  delivered_at: string | null
  next_statuses: Array<{ value: SellerOrderStatus, label: string }>
}

export interface SellerOrderDetail extends SellerOrderSummary {
  recipient: {
    name: string | null
    phone: string | null
    city: string | null
    district: string | null
    address_line1: string | null
    address_line2: string | null
    postal_code: string | null
  }
  cancellation_reason: string | null
  items: OrderLine[]
}

/*
 * Finance.
 *
 * A seller's balance is split four ways because the money is genuinely in four states, and
 * collapsing them into one "balance" is how a seller reads a number they cannot yet have.
 */

export interface SellerEarningsSummary {
  currency: string
  /** Earned, but the goods are not delivered or the hold is still running. */
  pending_minor: number
  /** Delivered, held out, and ready to be paid. */
  available_minor: number
  /** In an approved settlement that has not left the bank yet. */
  reserved_minor: number
  paid_out_minor: number
  lifetime_commission_minor: number
  hold_days: number
}

export interface SellerEarningsOrder {
  seller_order_number: string
  status: string
  status_label: string
  total_minor: number
  commission_minor: number
  payable_minor: number
  delivered_at: string | null
  /** A sentence, not a code: "12.09.2026 tarihinde hakedişe girer". */
  settlement_note: string
}

export type SettlementStatus = 'draft' | 'approved' | 'paid' | 'cancelled'

export interface SettlementRow {
  id: string
  reference: string
  status: SettlementStatus
  status_label: string
  currency: string
  period_start: string
  period_end: string
  gross_minor: number
  commission_minor: number
  adjustment_minor: number
  net_minor: number
  item_count: number
  seller_name: string | null
  approved_at: string | null
  paid_at: string | null
  payout_reference: string | null
  note: string | null
}

export interface LedgerAccountBalance {
  code: string
  label: string
  type: string
  balance_minor: number
}

export interface FinanceOverview {
  /** If this is ever false, nothing else on the page means anything. */
  is_balanced: boolean
  accounts: LedgerAccountBalance[]
  /** Every seller's payable added together — what the platform owes in total. */
  sellers_owed_minor: number
  open_settlements: number
  sellers_owed: number
}

export interface LedgerEntryRow {
  id: string
  type: string
  description: string
  currency: string
  total_minor: number
  reference_type: string | null
  reference_id: string | null
  is_reversal: boolean
  posted_at: string
  lines: Array<{
    account: string | null
    debit_minor: number
    credit_minor: number
    memo: string | null
  }>
}

/*
 * Shipping, returns and refunds.
 *
 * A return and its refund are separate objects with separate lifecycles, because goods and
 * money travel on different timetables — a return can be approved and the refund fail at
 * the provider, and a refund can be issued with nothing coming back.
 */

export type ReturnStatus =
  | 'requested'
  | 'approved'
  | 'rejected'
  | 'in_transit'
  | 'received'
  | 'completed'
  | 'cancelled'

export type RefundStatus = 'pending' | 'processing' | 'succeeded' | 'failed' | 'cancelled'

export interface RefundSummary {
  id: string
  reference: string
  status: RefundStatus
  status_label: string
  message: string
  currency: string
  amount_minor: number
  seller_share_minor: number
  commission_share_minor: number
  reason: string | null
  failure_reason: string | null
  processed_at: string | null
  created_at: string | null
}

export interface ReturnLine {
  id: string
  product_name: string | null
  quantity: number
  approved_quantity: number
  unit_price_minor: number
  refund_minor: number
  condition_note: string | null
}

export interface ReturnDetail {
  id: string
  reference: string
  status: ReturnStatus
  status_label: string
  message: string
  reason_code: string
  reason_note: string | null
  currency: string
  requested_minor: number
  approved_minor: number
  seller_order_number: string | null
  seller_name: string | null
  decision_note: string | null
  decided_at: string | null
  created_at: string | null
  items: ReturnLine[]
  refund: RefundSummary | null
}

export interface ShipmentSummary {
  id: string
  carrier: string | null
  tracking_number: string | null
  tracking_url: string | null
  status: string
  shipped_at: string | null
  delivered_at: string | null
  note: string | null
  item_count: number
}

export interface ReturnReason {
  code: string
  label: string
}

// --- platform administration (Phase 18) ------------------------------------------

export interface AdminOverview {
  period_days: number
  orders: {
    count: number
    gross_minor: number
    average_minor: number
    by_status: Record<string, number>
  }
  money: {
    is_balanced: boolean
    cash_minor: number
    commission_minor: number
    refunds_owed_minor: number
    sellers_owed_minor: number
  }
  marketplace: {
    active_sellers: number
    live_products: number
    pending_moderation: number
    new_customers: number
  }
  /**
   * The queue of things a person still has to decide. Kept apart from the totals
   * because a dashboard that mixes "what happened" with "what is waiting for you"
   * makes the second invisible.
   */
  waiting: {
    seller_orders_unconfirmed: number
    open_returns: number
    transfers_to_check: number
    settlements_open: number
    failed_refunds: number
    failed_jobs: number
  }
  ai: {
    jobs: number
    failed: number
  }
}

export interface AdminOrderSeriesPoint {
  day: string
  orders: number
  gross_minor: number
}

export interface AdminOrderRow extends OrderSummary {
  customer_email: string | null
  seller_count: number
}

export interface AdminAuditRow {
  id: string
  action: string
  actor: string | null
  actor_type: string | null
  subject_type: string
  subject_id: string | null
  reason: string | null
  changes: Record<string, unknown> | null
  context: Record<string, unknown> | null
  created_at: string | null
}

export interface AdminPermissionRow {
  value: string
  description: string
  granted: boolean
}

export interface AdminPermissionMatrix {
  routes: Record<string, string>
  permissions: AdminPermissionRow[]
  uncovered_routes: string[]
}

export interface FeatureFlagRow {
  id: string
  key: string
  name: string
  description: string | null
  is_enabled: boolean
  rollout_percentage: number
  updated_at: string | null
}

export interface SystemSettingRow {
  id: string
  key: string
  group: string
  label: string
  description: string | null
  type: 'string' | 'integer' | 'boolean' | 'json'
  /** Null for a secret: the value never leaves the server. */
  value: string | null
  is_secret: boolean
  is_set: boolean
  updated_at: string | null
}

export interface FailedJobRow {
  id: number
  uuid: string
  queue: string
  job: string
  error: string
  failed_at: string
}

export interface WebhookEventRow {
  id: string
  gateway: string
  event_type: string | null
  status: string
  signature_verified: boolean
  attempts: number
  error_message: string | null
  received_at: string
}

export interface SystemHealth {
  failed_jobs: FailedJobRow[]
  webhooks: WebhookEventRow[]
  failed_job_count: number
}

// --- seller team and shipping (Phase 19) ------------------------------------------

export interface SellerTeamMember {
  id: string
  user_id: string
  email: string | null
  name: string | null
  status: 'invited' | 'active'
  role: string | null
  role_label: string | null
  invited_at: string | null
  joined_at: string | null
}

export interface SellerTeamRole {
  value: string
  label: string
  description: string
}

export interface SellerTeamMeta {
  /** What the caller may do, so a missing button can be explained rather than 403. */
  can_manage: boolean
  your_role: string | null
  roles: SellerTeamRole[]
}

/** A line with something still on the shelf. Fully shipped lines are not sent. */
export interface PendingShipmentLine {
  order_item_id: string
  product_name: string
  sku_code: string | null
  ordered: number
  remaining: number
}

export interface SellerDashboard {
  waiting: {
    unconfirmed_orders: number
    to_ship: number
    open_returns: number
    low_stock: number
    pending_moderation: number
  }
  sales: {
    period_days: number
    orders: number
    gross_minor: number
    commission_minor: number
    payable_minor: number
  }
  earnings: {
    currency: string
    available_minor: number
    pending_minor: number
    in_settlement_minor: number
    paid_minor: number
  }
  catalogue: {
    live: number
    draft: number
    out_of_stock: number
  }
}
