# REFCONCEPT DESIGN SYSTEM & UI SPECIFICATION

This document converts the approved visual references into implementation rules for the RefConcept product.

**Primary design references live in** `design_refs/`.

---

## 1. Design Source of Truth

The UI must be grounded in these images:

1. `design_refs/brand_colors.jpg`
2. `design_refs/ui_inspiration.jpg`
3. `design_refs/hero_room.jpg`
4. `design_refs/dashboard.jpg`
5. `design_refs/mobile_ai_marketplace.jpg`
6. `design_refs/mobile_ops_ar.jpg`
7. `design_refs/refconcept_assets_montage.png`

These references establish the visual system for:
- public website
- customer app/web experience
- seller portal
- super admin
- AI design UX
- marketplace catalog experience

They are not optional inspiration; they are the intended visual target.

---

## 2. Brand & Interface Personality

RefConcept should feel:

- premium, but not cold
- modern, but not aggressive
- elegant, but still usable
- AI-powered, but not overly futuristic
- calm, confident, spacious and trustworthy

### Emotional keywords
- refined
- architectural
- warm minimal
- high-end living
- guided confidence
- luxury-tech
- organized simplicity

### Visual anti-patterns to avoid
- loud gradients
- neon colors
- overly gamified UI
- crowded dashboards
- harsh SaaS-blue generic look
- cheap e-commerce visuals
- dark heavy enterprise admin look for customer flows

---

## 3. Core Visual Language

### 3.1 Overall look
The UI direction is **luxury minimal** with soft neutral backgrounds, large white/cream space, crisp typography, rounded cards and warm editorial imagery of modern interiors.

### 3.2 Shape language
- large rounded corners on cards and major containers
- soft rectangular cards
- minimal borders
- subtle shadowing, never heavy
- pill tabs and rounded filter chips
- clean line icons

### 3.3 Interior imagery
Use bright, soft, neutral, premium interior visuals:
- beige / taupe / stone / cream tones
- clean architectural lines
- luxury living spaces
- daylight-rich rooms
- tasteful furniture focus

---

## 4. Color System

Based directly on `brand_colors.jpg`:

### Primary palette
- **Charcoal** — `#111111`
- **Warm Gray** — `#F5F3F0`
- **Sand** — `#DCCE86`
- **Taupe** — `#A89E8E`
- **Gold Accent** — `#C9A86A`

### Suggested usage
- Primary text: `#111111`
- Main background: `#FFFFFF` / `#F5F3F0`
- Section background: `#F5F3F0`
- Secondary surface: `#F7F5F2` or warm white derivatives
- Card outline / dividers: very light warm gray lines
- CTA dark button: `#111111` on light surfaces
- Accent highlights, charts, tags, budget visuals: `#C9A86A`
- Muted secondary data / inactive controls: `#A89E8E`

### Semantic extensions
To keep the UI practical, semantic states may add:
- success: muted olive/green
- warning: warm amber
- error: softened terracotta/red
But they must harmonize with the neutral base palette.

### Hard rule
The product should **not** visually drift into generic blue SaaS branding.

---

## 5. Typography

The references show a clean modern sans-serif system. The earlier brand board also suggests **Satoshi**.

### Recommended type stack
1. **Satoshi** (preferred)
2. `Inter`
3. `system-ui`, sans-serif fallback

### Typography behavior
- headlines: elegant, large, clean, medium to semibold
- body text: neutral, readable, uncluttered
- small labels: subtle and lightweight
- numeric emphasis: clean and clear for budgets, price, usage, percentages

### UI tone
Typography must communicate confidence through space and simplicity rather than bold shouting.

---

## 6. Iconography

From `brand_colors.jpg` and other references:

### Style
- thin/clean outline icons
- soft rounded stroke feeling
- simple geometry
- consistent stroke weight
- not filled, not cartoonish

### Typical icon families needed
- home
- cube/product
- sparkle/AI
- users/professionals
- calendar/timeline
- chat
- shield/trust
- budget/wallet
- cart/order
- room/layout

---

## 7. Layout System

## 7.1 General spacing rhythm
Adopt a spacious layout. Visual breathing room is part of the premium feel.

### Recommended spacing scale
- 4 / 8 / 12 / 16 / 24 / 32 / 48 / 64

### Containers
- large desktop max-width sections
- generous side padding
- strong whitespace around hero and dashboard cards

## 7.2 Border radius
Suggested tokens:
- small: 10–12px
- medium: 16px
- large cards / modal / dashboard shells: 20–28px
- pills / chips: fully rounded

## 7.3 Shadows
Soft and subtle. More “elevated paper” than “floating glass”.

---

## 8. Public Website Direction

Based on `hero_room.jpg` and `ui_inspiration.jpg`.

## 8.1 Hero section
Hero should include:
- large left-aligned value proposition
- calm editorial copy
- dark CTA
- supporting secondary CTA
- right-aligned premium interior visual
- optional floating AI/design card

### Hero feel
- architectural
- aspirational
- not cluttered
- focused on “see it, shape it, live it” type positioning

## 8.2 Homepage structure
Recommended order:
1. Hero
2. capability icons / core pillars
3. product dashboard preview
4. marketplace + professionals + budget modules
5. social proof / trusted brands
6. statistics
7. workflow section
8. final CTA

## 8.3 Homepage pillars
The references clearly support pillars such as:
- AI Design
- Products
- Budgeting
- Purchase
- Professionals
- Project Management

---

## 9. Customer Experience UX

Based heavily on `mobile_ai_marketplace.jpg`, `mobile_ops_ar.jpg`, `dashboard.jpg`.

### Core user flow
1. onboarding
2. create project
3. choose room type
4. upload room photo or floor plan
5. set style
6. set budget
7. generate design
8. inspect result
9. see real matched products
10. compare alternatives
11. add to cart
12. buy products / work with professionals
13. track project timeline

### Core UX principle
The AI flow must feel guided and reassuring, not technical.

The user should always feel:
- what step they are in
- what the AI is doing
- what they need to provide
- what will happen next
- how much the result may cost / consume in credits / affect budget

---

## 10. AI Design UX Rules

This is the most important design domain.

### 10.1 AI entry flow
Use card-based choice architecture:
- design a living room
- design a bedroom
- design a kitchen
- design a dining room
- design a bathroom
- custom project

### 10.2 Start options
Allow:
- upload room photo
- upload floor plan
- draw dimensions manually

### 10.3 Style selection
Style chips or segmented options:
- modern
- minimal
- luxury
- Scandinavian
- warm contemporary
- etc.

### 10.4 Budget selection
Budget should be easy to understand with:
- preset tiers
- optional custom budget
- transparent explanation

### 10.5 AI job state
The interface must show:
- processing
- waiting
- success
- retry/failure
- credit status

### 10.6 AI result screen
The result should combine:
- large main design image
- product list
- budget summary
- alternatives
- AI suggested changes
- save/share controls

### 10.7 AI assistant
From the references, a lightweight conversational assistant fits well:
- “make this brighter”
- “reduce budget”
- “replace flooring”
- “show Scandinavian alternatives”

The assistant should behave like a refinement layer, not a generic chatbot.

---

## 11. Marketplace & Product Discovery

### Product cards
Should be:
- clean
- image-forward
- premium
- not too dense

Suggested contents:
- product image
- brand
- product name
- price
- material / stock / shipping snippets
- quick actions (favorite, view, add)

### Catalog UX
The customer should move fluidly from inspiration → real products.

Key surfaces:
- “Our Picks”
- “Best Sellers”
- AI matched products
- alternatives
- bundle / room set options

---

## 12. Budget UX

The references show a strong circular budget visualization.

### Budget module should contain:
- total budget
- budget used %
- budget remaining
- per-category breakdown
- itemized allocation
- alerts for over-budget items
- AI budget optimization suggestions

### Visual rule
Budget should feel elegant and understandable, not accounting-heavy.

---

## 13. Professionals / Services UX

From the mobile references:
- list of professionals with avatar, role, rating, city and action button
- interior designer / architect / contractor / installer
- project timeline linked to service milestones

This suggests a curated trust-oriented marketplace, not a noisy freelancer directory.

---

## 14. Project Management UX

Use the dashboard pattern from `dashboard.jpg` and mobile timeline screens.

The project area should combine:
- project progress
- selected products
- budget overview
- upcoming milestones
- team/professionals
- files/messages
- tasks / approvals / installation tracking

This is a major differentiator and must feel polished.

---

## 15. Seller Portal Design Direction

The seller portal should reuse the same design language but be slightly more operational.

### Seller portal should feel:
- clean
- trustworthy
- efficient
- premium, not generic admin

### Key seller sections
- onboarding progress
- product management
- imports
- media
- stock & pricing
- orders
- returns
- settlement / payouts
- API keys / integrations

Use dashboard cards, filtered tables, detail drawers and action panels.

---

## 16. Super Admin Design Direction

The admin must still look consistent with the RefConcept system, but may be denser and more data-driven.

### Required qualities
- structured
- auditable
- efficient
- readable
- low visual noise

### Critical admin areas
- seller approval queue
- product moderation
- payment reconciliation
- bank transfer confirmation
- credit management
- AI provider routing
- commission rules
- ledger / settlements
- failed jobs and webhooks

---

## 17. Component Library Blueprint

A reusable component library should exist for:

### Base
- Button
- IconButton
- LinkButton
- Input
- Select
- MultiSelect
- TextArea
- Search
- Checkbox
- Radio
- Switch
- Tabs
- Chips
- Badge
- Tooltip
- Divider
- Avatar
- Breadcrumb
- Pagination

### Surface
- Card
- Panel
- Drawer
- Modal
- Sheet
- Table
- DataGrid
- StatCard
- MetricRing
- Stepper
- Timeline
- EmptyState
- ErrorState
- LoadingState
- Skeleton

### Commerce
- ProductCard
- ProductRow
- BudgetBreakdown
- PriceSummary
- CartLine
- ShippingStatus
- ReturnStatus

### AI
- RoomUploadCard
- StyleSelector
- BudgetSelector
- DesignPreview
- AIProgressCard
- PromptRefinementChip
- MatchedProductsPanel
- AIUsageStatus

### Admin / Seller
- ApprovalCard
- SettlementCard
- AuditLogViewer
- QueueStatusPanel
- WebhookInboxTable

---

## 18. Motion & Micro-Interactions

Keep motion subtle:
- fade/slide for panels
- gentle hover lift on cards
- soft progress states for AI jobs
- skeleton loading instead of abrupt blank screens

No overly playful motion.

---

## 19. Responsive Rules

The product is WEB-FIRST but must behave well on mobile browsers.

### Desktop strengths
- richer dashboard views
- split layouts
- denser project management

### Mobile/browser priorities
- project setup
- AI generation
- product browsing
- cart/checkout
- order tracking

Complex finance/admin tables can remain desktop-first, but responsive behavior must still be acceptable.

---

## 20. Implementation Rule for Coding Agents

All frontends must implement the visual direction from these references.

If a coding agent generates UI that looks like a generic bootstrap/admin template, it is considered **incorrect**.

The correct implementation should visibly reflect:
- the neutral premium palette
- the calm editorial hero
- the rounded soft cards
- the luxury-modern room imagery
- the budget donut visualization
- the AI guided flow
- the minimalist mobile flows
- the consistent premium SaaS aesthetic

---

## 21. Deliverables Required During Implementation

The coding agent should create and maintain:

- `docs/ui/README.md`
- `docs/ui/design-tokens.md`
- `docs/ui/component-inventory.md`
- `docs/ui/screen-map.md`
- `docs/ui/interaction-patterns.md`

At minimum, it must map all approved reference directions into code and documentation.

---

## 22. Brand Rename Rule

All old-brand UI text from early references must be updated to **RefConcept** in implementation and documentation.
