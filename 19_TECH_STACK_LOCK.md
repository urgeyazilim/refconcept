# REFCONCEPT WEB TECHNOLOGY LOCK

The autonomous agent should not casually replace the core stack.

## Core
- Laravel / PHP
- PostgreSQL
- Redis
- S3-compatible object storage

## Web
- Vue 3
- Nuxt
- TypeScript
- responsive SSR-capable storefront

## Testing
- Pest/PHPUnit
- PHPStan/Larastan
- Vitest
- Playwright

## AI
- provider-independent gateway
- OpenAI provider
- Google provider
- fake provider
- optional FastAPI CV service only when justified

## Infrastructure
- Docker
- CI/CD
- staging/production separation
- OpenAPI
- observability

## Change Policy

A core technology change requires an ADR containing:
- reason
- alternatives
- migration cost
- impact
- test plan

Do not change stack because another framework is “easier” for the agent.
