# ADR-0001 — Web-First + Modular Monolith

## Status
Accepted

## Context
RefConcept has customer, seller, marketplace, payment, AI and finance domains.
The first target is a complete Web platform before mobile.

## Decision
- Build the full Web product first.
- Use Laravel Modular Monolith as the core application.
- Keep AI/CV separable through provider/service interfaces.
- Defer Flutter/AR until `WEB_RELEASE_APPROVED`.

## Consequences
Positive:
- fewer distributed-system failure modes
- faster end-to-end delivery
- one authoritative business core
- mobile later reuses stable API

Negative:
- careful module boundaries are required
- scaling individual domains may require later extraction
