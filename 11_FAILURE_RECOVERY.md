# REFCONCEPT AUTONOMOUS FAILURE RECOVERY

## Compile/Build Failure
1. capture exact error
2. isolate root cause
3. fix smallest cause
4. run narrow test
5. run affected suite
6. persist result

## Test Failure
Classify:
- implementation bug
- test bug
- fixture bug
- flaky infrastructure

Do not weaken a correct test to pass buggy code.

## Migration Failure
- never destroy production data
- inspect current schema/migration state
- prefer forward-safe correction
- use local rollback only when safe
- add regression test

## Provider Failure
- preserve interface
- use sandbox/fake
- record external live validation
- continue independent work

## Dependency/API Version Drift
- inspect the current official provider/package documentation available to the coding environment
- choose a compatible current stable version
- document in ADR
- update contract tests

## Three-Fix Rule
After 3 failed attempts:
- stop random edits
- create minimal reproduction
- compare last green snapshot
- inspect logs/trace
- select alternate implementation
- retest gate

## Context/Session Stop
Persist repository state before stopping.
Next execution resumes via `10_REPOSITORY_MEMORY_PROTOCOL.md`.

## Git Safety
Before risky refactor:
- green relevant tests
- commit/snapshot
- then refactor

Never disable CI/test/security rules to hide a problem.
