# Domain modules

Each subdirectory is a bounded context from `REFCONCEPT_MASTER_SPEC.md` §7.

## Rules

1. **A domain owns its tables.** Another domain reads through this domain's
   Actions/Queries or a published event, never by querying its models directly.
2. **Actions are the write API.** A controller resolves an Action and passes a DTO.
   Business rules do not live in controllers, models or jobs.
3. **Policies are mandatory** on anything reachable by a seller or customer.
   Tenant isolation (`seller_id` scoping) is enforced there and tested per endpoint.
4. **Money is `Money`**, never `float`, never a bare `int` passed around loosely.
5. **Domain events** carry facts in the past tense (`OrderPaid`, `DesignGenerated`)
   and are the seam other domains hook into.
6. **Tests live with the domain** under `Tests/`, and a feature is not complete
   without them.

## Adding a domain

Create it when the phase that needs it starts. Minimum viable slice:

```text
<Domain>/
  Actions/       DTOs/       Enums/
  Models/        Policies/   Http/Controllers/
  Tests/
```

Add the remaining directories only when something goes in them.

## Reference implementation

`Administration/` contains the platform health check slice — a small, complete
example of Enum → DTO → Service → Controller with no framework logic leaking into
the domain layer.
