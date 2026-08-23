# GitHub Copilot Instructions — RefConcept

The repository is governed by `/AGENTS.md` and `/REFCONCEPT_MASTER_SPEC.md`.

Do not:
- introduce the old RefOne brand,
- put financial business logic in frontend,
- store money as floats,
- bypass authorization,
- disable failing tests,
- hard-code provider secrets/model IDs.

Prefer small tested changes, domain services, policies, adapters, state machines,
idempotent jobs, OpenAPI updates and automated tests.
