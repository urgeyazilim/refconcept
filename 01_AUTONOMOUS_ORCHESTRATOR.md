# REFCONCEPT AUTONOMOUS ORCHESTRATOR

## Objective

Turn one user command into a controlled multi-phase software factory run.

The Orchestrator owns:
- dependency graph
- task decomposition
- role assignment
- phase sequencing
- test gates
- failure recovery
- repository state persistence
- final release decision handoff to Test Agent

It does **not** bypass tests and does **not** treat generated code as proof of correctness.

## Execution Loop

```text
BOOT
 ↓
Read master specification
 ↓
Read progress/task ledger
 ↓
Inspect repository & git
 ↓
Resolve current phase
 ↓
Select smallest executable task
 ↓
Assign specialist agent
 ↓
Implement
 ↓
Self-check
 ↓
Independent Test Agent
 ↓
FAIL ──→ Diagnose → Fix → Re-run
 ↓ PASS
Persist state
 ↓
Next task / next phase
 ↓
Full Web Release Gate
```

## Task Sizing Rule

Good task:
- one migration set
- one aggregate/workflow
- one API group
- one UI flow
- one provider adapter
- one test group

Bad task:
- “build marketplace”
- “finish admin”
- “do payments”

## Phase Closure Checklist

A phase closes only when:
- required DB/schema exists
- backend business flow exists
- authorization exists
- validation exists
- UI exists when required
- errors/loading states exist
- audit/logging exists when required
- API docs updated
- tests added
- tests pass
- Test Agent says `PHASE_APPROVED`
- progress/task state persisted

## Missing External Dependency Policy

Examples:
- iyzico production merchant account
- QNB production keys
- real bank statement API
- production S3
- mail/SMS production key

Action:
1. define production interface
2. implement sandbox/fake
3. add contract fixtures
4. add `.env.example`
5. document live validation dependency
6. continue all independent work

Never invent credentials.

## No-Human-Approval Policy

Do not ask the user:
- “continue?”
- “shall I move to phase 2?”
- “should I run tests?”

Proceed automatically.

Only stop the whole run when:
- proceeding risks irreversible data loss,
- a security boundary cannot be resolved safely,
- repository is corrupted beyond a recoverable snapshot.

## Web/Mobile Boundary

Phase 0–22: WEB only.

After Test Agent writes:
`WEB_RELEASE_APPROVED`

the mobile milestone may begin later.
