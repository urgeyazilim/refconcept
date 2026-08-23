# REFCONCEPT AUTONOMOUS COMPLETION PROTOCOL

## The One-Shot Promise Means

One human start command.

It does **not** mean:
- one giant source-code response,
- no testing,
- no incremental commits,
- no retries,
- no external production prerequisites.

It means the coding system must autonomously continue:

```text
plan
→ implement
→ test
→ fix
→ retest
→ persist
→ next
```

until the Web gate is approved.

## Completion Is Evidence-Based

Evidence files:
- git history/snapshots
- migrations
- OpenAPI
- automated test reports
- security checklist
- financial invariant results
- deployment docs
- progress/task ledger
- Test Agent final decision

## If Runtime Session Ends

A new coding-agent invocation should need only:

```text
Resume RefConcept autonomously according to AGENTS.md.
```

Because the complete state is in the repository.

## Forbidden Final Message

“Everything is complete” when:
- only scaffolds exist
- provider adapters are untested
- E2E does not pass
- finance invariants fail
- P0/P1 exists
- Test Agent has not approved

## Required Final Message State

```text
REFCONCEPT WEB
STATUS: WEB_RELEASE_APPROVED
COMMIT: <hash>
TESTS: <summary>
EXTERNAL LIVE VALIDATIONS: <list>
DEPLOYMENT: <status>
```
