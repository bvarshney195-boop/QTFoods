# Backend Architecture Contract

## Modular monolith first

Core Inventory, Manufacturing, Quality and Finance remain inside one Laravel application and one PostgreSQL transaction boundary for the first operational release.

## Cross-cutting services

Every controlled command should flow through:

1. named actor / authenticated service
2. company / plant / party scope
3. current source record + expected version
4. domain invariant / state-machine validation
5. maker-checker or threshold authority where applicable
6. idempotency check
7. database locking / serialization for conflicting balances
8. atomic business posting + audit + outbox
9. immutable result ID / new version
10. external handoff after commit

## Background jobs

Recommended queues:
- critical-integrations
- imports
- documents
- notifications
- reporting
- planning
- costing
- finance-periodic
- maintenance
- integration-reconcile
- data-retention

Business postings should not be hidden inside ordinary background work. A queued job must expose pending/rejected/acknowledged state where material.

## API errors

- 403: authenticated but denied; do not leak another record
- 409: state/version/idempotency/availability conflict
- 422: business/input validation
- 202: accepted async work with visible status/result reference
