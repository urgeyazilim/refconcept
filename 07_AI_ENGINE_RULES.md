# REFCONCEPT AI ENGINE RULES

## Product Principle

RefConcept is not “an image generator”.

AI is an orchestration layer connecting:

```text
Room Understanding
→ Design Planning
→ Image Generation/Edit
→ Object Extraction
→ Real Product Matching
→ Budget Validation
→ Commerce
```

## Provider Independence

Required concepts:
- `ai_providers`
- `ai_models`
- `ai_task_types`
- `ai_task_routes`
- `prompt_templates`
- `prompt_versions`
- `ai_requests`
- `ai_usage`
- `ai_cost_rates`

Do not hard-code a particular model name.

Admin chooses:
- primary provider/model
- fallback
- timeout
- retry
- max cost
- credit cost
- active prompt version

## Task Types

```text
ROOM_ANALYSIS
DESIGN_PLAN
IMAGE_RENDER_DRAFT
IMAGE_RENDER_PREMIUM
IMAGE_EDIT
OBJECT_EXTRACTION
PRODUCT_TAGGING
PRODUCT_QUERY_REWRITE
PRODUCT_MATCH_RERANK
BUDGET_OPTIMIZE
SUPPORT_ASSIST
CATALOG_ENRICHMENT
```

## Room Intelligence

Photo/floor plan/scan should produce a structured schema such as:

```json
{
  "room_type": "living_room",
  "measurement_quality": "estimated",
  "dominant_colors": [],
  "fixed_elements": [],
  "movable_objects": [],
  "surfaces": {},
  "constraints": [],
  "warnings": []
}
```

Use strict/validated structured output where provider capabilities allow it.

## Design Generation

Input:
- original room
- room constraints
- desired style
- budget
- preserve/change instructions
- optional product constraints

Output:
- design job/version
- render asset
- design metadata
- validation result

Original room media is immutable.

Every edit creates a new design version.

## Credit Lifecycle

```text
create AI job
→ atomic credit reserve
→ queue
→ provider
→ validate
→ success: consume
→ failure after retry: release
```

The same job/idempotency key cannot consume twice.

## Product Matching

Pipeline:
```text
design object
→ crop/attributes
→ text embedding
→ optional visual embedding
→ candidate retrieval
→ category/dimension/budget/stock/location hard filters
→ rerank
→ top real catalog matches
```

AI does not invent authoritative price or stock.
Price/stock comes from RefConcept database/integration.

## Cost Control

Log:
- task
- provider
- model
- input/output usage
- image count/resolution
- provider cost
- credits charged
- latency
- status

Support:
- per-user concurrency
- global cost cap
- provider cost cap
- fallback
- kill switch
- rate limit

## Fake AI Provider

CI must use deterministic `FakeAiProvider` capable of:
- success
- malformed JSON
- timeout
- provider error
- image failure
- slow response

Do not spend real AI money in standard CI.

## AI Regression Dataset

Maintain fixtures for:
- living room
- bedroom
- office
- difficult geometry
- preserve floor
- preserve TV/window
- budget constraints
- style changes

Run prompt/model regression before routing changes are promoted.
