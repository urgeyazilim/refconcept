# REFCONCEPT SCREEN BLUEPRINTS & INFORMATION ARCHITECTURE

This file translates the approved visual references into concrete screen-level implementation guidance.

---

## 1. Application Surfaces

RefConcept WEB consists of 3 main surfaces:

1. **Public / Customer Storefront**
2. **Seller Portal**
3. **Super Admin**

---

## 2. Customer Storefront Screen Map

## 2.1 Public marketing pages
- Home
- How it works
- Marketplace / Products
- Professionals
- Pricing / Credits
- About
- Login / Register

## 2.2 Auth / account
- Sign in
- Sign up
- Email verification
- Forgot password
- Profile
- Addresses
- Saved projects
- Orders
- Saved designs
- Wishlist
- Documents / invoices
- Messages

## 2.3 Project creation flow
- project list
- create project
- room type picker
- upload room / upload floor plan / draw dimensions
- choose style
- choose budget
- confirm generation

## 2.4 AI results flow
- design loading / progress state
- design result
- version comparison
- matched products
- alternative products
- budget view
- AI refinement actions
- save/share

## 2.5 Commerce flow
- category list
- search results
- product detail
- bundle / room set
- cart
- checkout
- payment status
- order detail
- shipping / return

---

## 3. Homepage Wireframe Logic

### Header
- RefConcept logo
- Platform
- Solutions
- Resources
- Pricing
- About
- Sign in
- Get started

### Hero
Left:
- tagline / value proposition
- supporting paragraph
- primary CTA
- secondary CTA

Right:
- premium living room image
- floating AI progress card or design card

### Capability strip
Six pillar cards:
- AI Design
- Products
- Budgeting
- Purchase
- Professionals
- Project Management

### Dashboard preview section
Large product preview:
- project image
- selected products
- budget ring
- timeline

### Social proof
- trusted brands / professionals
- metrics

### Final CTA
- start project
- explore marketplace

---

## 4. AI Project Setup Screens

### 4.1 Project dashboard
Should include:
- project card
- recent activity
- room list
- create new design

### 4.2 Room type chooser
Grid of room cards:
- living room
- bedroom
- dining room
- kitchen
- bathroom
- office
- custom

### 4.3 Start method screen
Three entry cards:
- upload room photo
- upload floor plan
- draw dimensions manually

### 4.4 Style selection
Chips or segmented cards with image support.

### 4.5 Budget selection
Preset tiers + optional custom entry + “estimated total” explanation.

### 4.6 Generate CTA
One focused CTA: “Generate Design”

---

## 5. AI Result Screen Blueprint

Main structure:

### Left or top main region
- hero render
- quick view switch (Before/After or Compare)
- save / favorite / share

### Right or lower supporting region
Tabs / sections:
- Shopping List
- Alternatives
- Budget
- Notes / Suggestions

### Matched product list item
- image
- brand
- name
- price
- stock
- shipping
- add to cart

### Refinement actions
Quick chips:
- Suggest another sofa
- Reduce budget
- Make this room brighter
- Replace flooring
- Show Scandinavian alternatives

---

## 6. Budget Screen Blueprint

### Summary
- total budget
- used %
- remaining
- estimated total

### Breakdown
- furniture
- finishes
- lighting
- decor
- services
- other

### Actions
- buy all
- swap expensive items
- lock budget
- ask AI to optimize

---

## 7. Marketplace Screen Blueprint

### Top
- search field
- primary category shortcuts
- filter button

### Content blocks
- Our Picks for You
- Best Sellers
- Recommended by AI
- Recently viewed

### Product card
- image
- title
- brand
- price
- stock
- quick add

---

## 8. Professionals Screen Blueprint

### Directory
- search
- category tabs
- location/rating filters
- expert cards

### Expert card
- avatar
- name
- role
- rating
- location
- price/range if applicable
- CTA: Book / Contact

---

## 9. Project Timeline Screen Blueprint

Vertical timeline with stages:
- Planning
- Products Ordered
- Shipping
- Installation
- Completed

Each item has:
- date
- status
- note
- optional linked files/tasks

---

## 10. AI Assistant Screen Blueprint

### Chat structure
- AI welcome prompt
- quick action chips
- message feed
- structured suggestions
- input box
- voice/mic optional later

### Tone
The assistant must feel like a design concierge, not a generic bot.

---

## 11. Seller Portal IA

Main navigation:
- Overview
- Onboarding
- Products
- Categories/Attributes
- Imports
- Price Lists
- Inventory
- Orders
- Returns
- Finance
- Settlements
- Team
- Integrations
- Settings

### Seller dashboard
- onboarding/compliance status
- sales summary
- order queue
- low stock
- payout status
- moderation alerts

---

## 12. Super Admin IA

Main navigation:
- Dashboard
- Users
- Sellers
- Seller Applications
- Product Moderation
- Catalog
- Orders
- Payments
- Bank Transfers
- Refunds
- Credits
- AI Providers
- AI Models
- AI Prompts
- AI Routes
- Commissions
- Ledger
- Settlements
- Payouts
- Webhooks
- Jobs / Queue
- Audit Logs
- Feature Flags
- Settings

---

## 13. Data-Dense UI Patterns

For Seller/Admin:
- filters above tables
- row actions on hover / menu
- detail drawer or side panel
- bulk actions where justified
- status chips
- summary cards at top
- audit history drawers

---

## 14. Design Tokens That Must Exist in Code

The coding agent should create tokens for:

- colors
- typography
- spacing
- radius
- shadows
- breakpoints
- z-index
- motion timings
- component variants

These should be shared between storefront, seller portal and admin as much as practical.

---

## 15. UI Acceptance Rule

A screen is not accepted if it only works functionally but ignores the approved design language.

The implementation should visibly communicate the same family as the reference images:
- soft premium palette
- quiet luxury
- rounded modern UI
- AI-guided workflow
- product-rich commerce interface
- elegant dashboards
