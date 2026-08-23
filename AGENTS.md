# AGENTS.md — RefConcept Autonomous Engineering Contract

## Mission

Build **RefConcept WEB** to a verifiable release state from the master specification.

## Mandatory Read Order

1. `REFCONCEPT_MASTER_SPEC.md`
2. `01_AUTONOMOUS_ORCHESTRATOR.md`
3. `02_AGENT_TEAM.md`
4. `03_INDEPENDENT_TEST_AGENT.md`
5. `04_WEB_PHASE_PLAN.md`
6. `05_ARCHITECTURE_AND_CODE_RULES.md`
7. `06_SECURITY_PAYMENT_FINANCE_RULES.md`
8. `07_AI_ENGINE_RULES.md`
9. `08_DATABASE_AND_DOMAIN_RULES.md`
10. `09_FRONTEND_UX_RULES.md`
11. `10_REPOSITORY_MEMORY_PROTOCOL.md`
12. `11_FAILURE_RECOVERY.md`
13. `12_FINAL_WEB_ACCEPTANCE.md`
14. `13_PROGRESS_STATE.md`
15. `14_TASK_LEDGER.md`

## Hard Rules

- Project/brand: **RefConcept**.
- WEB first; mobile is forbidden before `WEB_RELEASE_APPROVED`.
- Do not request phase-by-phase user confirmation.
- Repository state is persistent memory.
- Test Agent is independent.
- Do not claim completion from scaffolding or mocked UI.
- Financial operations must be idempotent and auditable.
- External services must be adapter-based and testable with sandbox/fakes.
- Never hard-code AI model IDs or production secrets.
- Keep API documentation, migrations and tests synchronized with code.

## Resume

On any resumed session:
1. inspect git
2. read progress state
3. read task ledger
4. read latest test report
5. run a small health test
6. continue the first incomplete task


## Design Reference Rule

Before implementing or modifying UI, also read:

16. `21_DESIGN_SYSTEM_UI_SPEC.md`
17. `22_SCREEN_BLUEPRINTS_INFORMATION_ARCHITECTURE.md`
18. `design_refs/README.md`

The files inside `design_refs/` are approved visual references and the implemented UI must reflect them.
