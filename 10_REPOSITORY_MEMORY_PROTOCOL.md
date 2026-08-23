# REFCONCEPT REPOSITORY MEMORY PROTOCOL

One-shot autonomy must survive context/session limits.

## Repository Is Long-Term Memory

Mandatory files:
- `13_PROGRESS_STATE.md`
- `14_TASK_LEDGER.md`
- `TEST_REPORT.md`
- `CHANGELOG.md`
- `docs/ADR/*`

## Resume Protocol

At every start/resume:

```bash
git status
git log -n 10 --oneline
```

Then read:
1. `13_PROGRESS_STATE.md`
2. `14_TASK_LEDGER.md`
3. latest `TEST_REPORT.md`
4. current phase requirements

Run the smallest relevant health test.

Continue the first incomplete task.

## After Every Atomic Task

Persist:
- task status
- files changed
- migrations
- API changes
- tests run/results
- blockers
- next task
- commit hash/snapshot if available

## Context Exhaustion

Before the agent loses context, it must write:
- exact current phase/task
- what was completed
- what is failing
- exact failing command/test names
- next intended action
- unresolved decisions

The next session must not depend on chat memory.

## Architecture Decisions

Create ADR for durable decisions:
- provider strategy
- domain boundary
- schema pattern
- deployment architecture
- security trade-off
- search engine switch
- payment flow strategy
