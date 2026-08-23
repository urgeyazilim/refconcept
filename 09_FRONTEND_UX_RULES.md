# REFCONCEPT WEB FRONTEND / UX RULES

## Applications

```text
apps/storefront
apps/seller-portal
apps/admin-panel
```

May share:
- UI package
- typed API client
- validation schemas
- design tokens

## Storefront Customer Journey

```text
Landing
→ Register/Login
→ Project
→ Room
→ Upload
→ Choose style/budget
→ Buy credits if needed
→ Generate design
→ Compare versions
→ See real matched products
→ Choose variants/alternatives
→ Cart
→ Checkout
→ Payment
→ Order
→ Delivery/Return
```

## Seller Journey

```text
Apply
→ Verify
→ Company/Legal
→ Bank/IBAN
→ Documents/Agreement
→ Admin Review
→ Approved Store
→ Product
→ Variant/SKU
→ Media/Price/Stock
→ Moderation
→ Publish
→ Order
→ Shipment
→ Return
→ Settlement/Payout
```

## Super Admin Journey

Control center for:
- users
- sellers
- products/moderation
- orders
- payments/bank transfers
- credits
- AI providers/models/prompts/routes
- commission
- ledger
- settlement/payout
- returns/refunds
- queues/webhooks
- audit
- feature flags/settings

## UX Completion Criteria

Every async screen includes:
- loading
- success
- empty
- validation error
- server/provider error
- retry where valid

Every dangerous admin action:
- confirmation
- reason if needed
- permission check
- audit



## Approved Design References

The UI must visually follow:
- `design_refs/brand_colors.jpg`
- `design_refs/ui_inspiration.jpg`
- `design_refs/hero_room.jpg`
- `design_refs/dashboard.jpg`
- `design_refs/mobile_ai_marketplace.jpg`
- `design_refs/mobile_ops_ar.jpg`

See also:
- `21_DESIGN_SYSTEM_UI_SPEC.md`
- `22_SCREEN_BLUEPRINTS_INFORMATION_ARCHITECTURE.md`

## Visual System Lock

The frontends must implement:
- neutral luxury palette
- charcoal primary CTAs
- soft rounded cards
- warm white / cream surfaces
- thin outline icons
- calm editorial typography
- premium interior imagery
- budget donut/ring visualization
- guided AI flow cards
- elegant dashboard patterns

If the produced UI looks like a generic admin template or a default bootstrap/tailwind demo, it fails the design requirement.

## Responsive

The web milestone must work well on phone browsers even though native app is deferred.

## SEO

Public pages:
- SSR
- metadata
- canonical
- sitemap
- product/category structured data where appropriate

Private AI room/project media:
- no indexing
- authenticated/signed access

## Accessibility

At minimum:
- semantic controls
- labels
- keyboard navigation on critical flows
- focus states
- reasonable contrast
- error association
