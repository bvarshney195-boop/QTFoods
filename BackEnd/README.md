# Q & T FOODS LTD ERP + CRM Backend

Laravel 13 modular-monolith backend for the Q & T FOODS manufacturing ERP.

## Implemented foundation

- PHP 8.5, PostgreSQL 18, and Redis runtime
- CSRF-protected, verified-email server-side authentication with a revocable logical device registry
- invitation-first onboarding, one-time password reset and email verification links stored only as hashes
- encrypted TOTP MFA, short-lived login challenges, and individually hashed one-use recovery codes
- self-service password/MFA/device controls plus scoped ERP-administrator invitation, verification, and device revocation
- operation- and identity-specific rate limits that avoid cross-user lockout behind a shared IP
- named users, roles, permissions, effective role assignments, companies, and plants
- mandatory authorised-context selection before business API access
- server-side screen and transaction-action permission middleware
- transactional audit, reliable outbox, idempotency, and maker-checker services
- generated/validated request and correlation UUIDs, W3C trace propagation, durable audit/outbox trace linkage, structured request/queue/outbox logs, dependency readiness, protected Prometheus metrics, and scheduled threshold alerts
- an opt-in unprivileged recovery image with quiesced atomic PostgreSQL/S3-compatible object snapshots, exact SHA-256 inventories, bounded retention, full-database/object restoration, Redis invalidation, and post-restore object/outbox/failed-job reconciliation
- scoped audit search/detail APIs with actor, request/correlation, outcome, date, command, and entity filters plus safe-diff inspection and permissioned inline/download evidence access
- PostgreSQL-safe outbox claiming, immutable delivery-attempt history, persisted acknowledgements, bounded exponential retries, stale-lock recovery, automatic/manual quarantine, optimistic operator replay, and an optional HMAC-signed HTTP transport
- versioned global/plant approval policies with supported Unsold Return and Purchase Requisition rule creation, contiguous quantity/value authority bands, immutable request snapshots, SLA deadlines, one-time escalation routing, temporary non-chainable delegation, and rejection/resubmission lineage
- lifecycle-safe company, plant, hierarchical-location, local-user, role, permission, and effective role-assignment administration with scoped reads, optimistic versions, idempotent writes, audit, and outbox records
- company-scoped party master data with immutable codes, organisation/individual identity, multiple operational roles, addresses, contacts, unique tax registrations, commercial terms, optimistic aggregate replacement, dependency-safe lifecycle transitions, and transactional audit/outbox records
- company-scoped brand/agreement, catalog-item/UOM, stock-bearing SKU/pack, recipe/BOM, production-route, and quality-specification aggregates with immutable codes, child ownership, scoped uniqueness, active-reference and positive-stock safeguards, optimistic lifecycle commands, and transactional audit/outbox records
- company-scoped inventory owners and traced lots with expiry/lifecycle evidence, a governed quality-status catalog, plant stock coordinates, and active reservation rows constrained by relational scope keys
- live stock, owner, lot, and movement-ledger list/detail APIs with filters, counters, lookups, reservation history, and server-derived available/blocked/reserved quantities
- row-locked, optimistic, idempotent stock reservation/release commands with overcommit, quality, expiry, lot, owner, audit, outbox, and projection-reconciliation safeguards
- versioned draft/post/cancel aggregates for issue, return, transfer, count, adjustment, expiry, and disposal, with deterministic stock locking, type-specific coordinate and quantity controls, linked immutable movements, and permissioned APIs
- versioned INR purchase-requisition headers and governed item lines with scoped draft/update/submit/cancel APIs, value-band approval routing, maker-checker decisions, rejection/resubmission lineage, linked work items, and transactional audit/outbox evidence; foreign currency remains blocked until FX normalization is governed
- approved-requisition RFQs with multi-supplier invitation, complete quote capture, server-ranked landed-value/delivery comparison, reasoned non-lowest/late award, and database-enforced source snapshots and approval ceilings
- awarded-quote purchase orders with exact requisition/RFQ/quote line identity, immutable commercial revision snapshots, quantity/ceiling-bounded amendments before or after issue, and reasoned cancellation
- plant-scoped gate entries and partial GRNs that lock remaining PO quantities, resolve traced purchase lots, receive stock into Quality Hold, and create incoming-QC work with immutable movement evidence
- all-line incoming-QC decisions that split held quantities into released/rejected positions, plus supplier returns constrained to rejected on-hand stock and posted as immutable outbound movements
- payable invoices with server-calculated tax, PO/accepted-GRN/price three-way matching, correctable match exceptions, maker-checker invoice approval, controlled single-supplier multi-invoice payment proposals, allocation, settlement status, and unique bank-statement reconciliation
- plant-scoped demand plans with time-phased manufactured-SKU lines, effective recipe/route release validation, immutable MRP recipe and inventory-netting snapshots, route-operation capacity schedules, and atomic FEFO material reservation/release under stock-position locks
- one-order-per-released-schedule-line production conversion with immutable recipe, route, material, and ordered-stage snapshots; versioned release/cancel/complete controls; exact row-locked reserved-material consumption; immutable issue, stage, output, audit, and outbox evidence; and reconciled good/loss/rework completion gates
- specification-snapshot lab samples with typed result evaluation, automatic failed-sample deviations and holds, reasoned CAPA/disposition and food-safety release controls, plus versioned SKU artwork/coding approval and retirement
- quality-gated packing into released finished stock with rendered coding and material-to-finished-lot genealogy; bidirectional trace, classified recall containment and stock blocking; and immutable versioned INR batch-cost snapshots with yield, unit-cost, material-usage, stage-time, and total variance
- governed lead qualification/conversion, versioned price lists and discount ceilings, finance-owned customer credit, contract commitments, server-priced/taxed sales orders, immutable revisions, cancellation, and third-party work lifecycle
- row-locked FEFO sales allocation and picking, exact-lot shipment/load/dispatch stock issue, atomic receivable creation, proof-of-delivery outcomes, shipment-line claims, returned-goods receipt, and credit/replacement/reject resolution
- scoped receivable ageing/exposure, atomic customer-receipt allocation, immutable balance transactions, and live order revenue/cost/margin reporting
- balanced general-ledger journals with posting/reversal/period-close controls; itemized expense maker-checker posting; overhead allocation; asset activation/depreciation/disposal; payroll snapshots/posting; and maintenance cost accounting
- masked bank identities plus deterministic SHA-256 AP-bank/GST exports with single-use source selection and receiving-system acknowledgements
- ledger-isolated finance simulations, maker-checker adjustments, mapped/validated legacy imports, line-reconciled opening balances, and immutable non-mutating support diagnostic snapshots
- private finance bill archive with MIME/size/retention validation, SHA-256 duplicate detection, scoped metadata, and authenticated S3-compatible retrieval
- effective-dated legal-entity consolidation groups, governed plant lanes and item/UOM mappings, dual-scope cross-company authority, independent source/destination acceptance, separate dispatch/receipt stock movements, in-transit evidence, and balanced maker-checker consolidation snapshots
- immutable plant-scoped trial-balance, receivable-ageing, inventory-availability, and order-fulfilment report runs with explicit cutoff/source freshness, stored rows/totals, snapshot SHA-256, and deterministic authenticated CSV/JSON exports
- role-filtered published guidance plus requester-owned/support-manager-visible support cases with optimistic start, comment, resolve, reopen, and close transitions and an ordered immutable event history
- schema-wide PostgreSQL hardening for all current master and transaction tables with scoped/composite foreign keys, lifecycle/type/quantity/amount checks, uniqueness and partial indexes, ownership/party-consistency triggers, and an explicit polymorphic/transport-identifier allowlist
- persisted, role/company/plant-scoped approval/task/exception queue with live counters, ageing, deadlines, claim, manager assignment, completion, audit/outbox records, and exact workflow drill-through
- controlled `RET-UNSOLD` request-to-finance commands, scoped list/detail reads, status history, and cascading customer/shipment/invoice/SKU/lot/quarantine-position lookups
- required `If-Match` and idempotency controls on receipt, disposition, loss posting, invoice confirmation, net credit, tax review, final settlement, and evidence upload
- scoped physical receipt posting that updates shipment-return totals and return-quarantine stock with auditable stock movements
- scoped loss-disposition approval inbox/detail APIs with exact direct/delegated authority checks, maker-checker separation, policy/SLA/authority snapshots, optimistic approval versions, and idempotent approve/reject commands
- rejection returns the versioned case to Quality quarantine and links its corrected resubmission; approval atomically routes restock, repack, and rework quantities to configured plant positions and unlocks Finance loss posting
- Finance loss posting issues the approved destroyed quantity out of quarantine; case detail exposes the full receipt-to-outcome stock movement history
- dedicated Finance permission and immutable action history for source-invoice confirmation, net credit note, tax treatment, and exactly one final receivable adjustment, refund, or replacement
- open-invoice settlement atomically reduces the receivable balance; refund and replacement paths require a fully paid invoice
- dedicated evidence permission for private PDF/image/text upload and download across all return-workflow roles
- evidence uploads are MIME-restricted, size-limited, SHA-256 hashed, case-versioned, idempotent, audit/outbox linked, and assigned a server-controlled seven-year retention date
- scoped case detail exposes evidence integrity, retention, uploader, and upload-audit metadata without storage paths; successful no-store/nosniff downloads are also audited
- live evidence uses a private S3-compatible bucket; local development may provision MinIO, while UAT/production require a separately managed external provider. Startup migrates any legacy private-volume objects and verifies their stored SHA-256 hashes before the application starts

The selected company and plant are authoritative. Business requests cannot substitute a different scope. Master data, stock, procure-to-pay, manufacturing, work/approval, order-to-cash, dispatch, claims, receivables, core/supplement finance, multi-plant transfer/consolidation, reporting, support cases, return treatment, and private evidence/archive commands are all constrained to the same active context and reference chain. Existing-record commands require the current optimistic version where applicable, and safe idempotent replays return the original command result instead of duplicating aggregate replacements, transitions, reservations, stock/ledger movements, approvals, settlements, exports, or attachments.

## Local ERP flow

1. Fetch `GET /api/v1/auth/csrf`.
2. Sign in with `POST /api/v1/auth/login`; complete `POST /api/v1/auth/mfa/challenge` when requested.
3. Inspect the authorised contexts returned with the session.
4. Select one with `POST /api/v1/contexts/select`.
5. Use protected business APIs. The session context, logical device, and screen permissions are checked on the server.
6. Manage password, TOTP/recovery codes, and devices under `/api/v1/auth/*` as needed.
7. Sign out with `POST /api/v1/auth/logout`, which revokes the current logical device record.

All mutating session endpoints require the returned CSRF token in `X-CSRF-TOKEN`.

## Local demo accounts

All accounts use password `prototype`.

| Role | Email |
| --- | --- |
| Sales Manager | `demo.user@qtfoods.local` |
| Operations Manager | `operations.user@qtfoods.local` |
| Finance Manager | `finance.user@qtfoods.local` |
| ERP Administrator | `admin.user@qtfoods.local` |
| BI Analyst | `bi.user@qtfoods.local` |
| Partner User | `partner.user@qtfoods.local` |

The container seeds these accounts at startup. They are development-only credentials.

`BI_ANALYST` is a least-privilege system role with `BI-REP` and `BI-PROFIT` screen access plus only `ACTION:BI-REP:RUN` and `ACTION:BI-REP:EXPORT`. It is suitable for read-only management/BI users who must not post operational or finance transactions.

## Run with Docker

```bash
docker compose up -d --build
```

The local-development Compose graph health-checks PostgreSQL, Redis, and MinIO, creates a non-public object bucket used by the scoped evidence and finance-archive prefixes, runs migrations/seeding and the integrity-checked legacy-evidence migration once, then starts the API, Redis queue worker, and scheduler. The scheduler enqueues an outbox-delivery batch every minute and evaluates operational alerts every five minutes; the worker consumes the `outbox` and `default` queues. The API serves at `http://localhost:8000`, liveness is available at `http://localhost:8000/api/health`, readiness at `http://localhost:8000/api/ready`, local metrics at `http://localhost:8000/api/metrics`, and the optional local MinIO console at `http://localhost:9001`.

For a synchronous operational probe or recovery batch, run:

```bash
docker compose exec app php artisan qt:outbox:process --limit=50
docker compose exec app php artisan qt:observability:check
docker compose exec app php artisan qt:recovery:verify --object-limit=0
```

Run the isolated test suite with:

```bash
docker compose run --rm --no-deps app composer test
```

The fast profile uses SQLite and skips the four PostgreSQL catalog/write-rejection checks in `MasterTransactionRelationalIntegrityTest`; the process-level stock-lock test is excluded from that profile. Run all five database-specific tests against a prepared E2E PostgreSQL database with `vendor/bin/phpunit -c phpunit.pgsql.xml`. The dedicated profile also starts two service processes, observes the contender blocked by the holder in PostgreSQL, and proves the second command re-reads committed stock rather than over-consuming it. The live Playwright setup below builds and seeds a disposable PostgreSQL database automatically.

The verified P2 baseline is 7 feature tests / 206 assertions on both SQLite and PostgreSQL; the focused multi-plant scale, partner-portal, optimisation, and reporting/help suites are respectively 4 tests / 84 assertions, 4 tests / 73 assertions, 4 tests / 139 assertions, and 4 tests / 84 assertions on both databases. The route-authorisation, production-configuration, observability, and recovery suites add 5 tests / 7,373 assertions, 9 tests / 61 assertions, 6 tests / 61 assertions, and 3 tests / 22 assertions respectively. The route matrix classifies all 480 v1 routes and resolves every business screen/action gate across all seeded roles and contexts. The complete fast suite is 182 tests / 10,046 assertions, and the PostgreSQL integrity/concurrency profile is 5 tests / 31 assertions. The disposable recovery drill additionally passes against live PostgreSQL, Redis, and its isolated test-only S3-compatible object-store fixture.

The frontend also owns live Chromium integration tests that run this backend against disposable PostgreSQL and Redis volumes plus the E2E-only private local `evidence_test` Laravel disk:

```bash
cd ../FrontEnd
npm run test:e2e
npm run test:e2e:a11y
```

Its dedicated `docker-compose.e2e.yml` project is reset and removed automatically, has no object-storage service or credentials, and does not modify the normal development database or object volume. Object-storage readiness is disabled only in this disposable environment; production keeps it mandatory. The complete suite currently contains 22 workflows; the focused accessibility command runs four of them, including automated WCAG A/AA coverage over all registered business screens. Startup is staged through database/cache health, migration, application processes, and API health; failures retain `test-results/e2e-stack-diagnostics.txt` before teardown.

The same disposable Compose definition exposes opt-in, digest-pinned k6 and ZAP services under the `quality` profile. Run the authenticated load and active API-security gates through `npm run test:quality:dynamic` from `FrontEnd`; the orchestrator uses a separate `qtfoods-erp-quality` project and removes its volumes on completion. Locked dependency, CodeQL, Trivy repository/release-image, browser, and dynamic gate policy is documented in [`../quality/README.md`](../quality/README.md).

The Compose key, service passwords, log mailer, and identity preview links are local-development values. Do not promote this Compose file or its seeded data into a shared environment.

## Production deployment baseline

Use `docker-compose.production.yml` and `.env.production.example` for production-oriented builds. That stack builds a no-dev PHP-FPM image and a separate recovery image running as unprivileged users, exposes only the source-built Caddy TLS gateway, keeps PostgreSQL/Redis private, and connects the S3-backed `private` and `evidence` disks to a pre-provisioned external managed S3-compatible provider through generic `AWS_*` settings. It does not deploy or require MinIO. It runs migrations without demo seeding, emits JSON stderr telemetry, protects metrics with a dedicated token, schedules operational alerts, keeps object-storage readiness mandatory, and refuses unsafe production/recovery policy at application boot. The release build compiles its provider-neutral S3-compatible recovery client from a pinned source revision with checked security-dependency upgrades; Trivy scans both resulting Go binaries.

The deployment preflight, secret inputs, frontend relationship, startup order, and observability contract are documented in [`docs/PRODUCTION_DEPLOYMENT.md`](docs/PRODUCTION_DEPLOYMENT.md); backup, restore, queue/outbox reconciliation, and rollback are in [`docs/RECOVERY_RUNBOOK.md`](docs/RECOVERY_RUNBOOK.md). Actual secret provisioning, external collector/paging integration, scheduled encrypted off-host backup custody, target-environment drills/recovery approval, protected-branch administration, and independent penetration/capacity approval remain deployment or organisational responsibilities.

## Delivery status

The complete local identity/control foundation and all 71 routed screens are functional. The portal includes party-bound exact-tenant access and private checksum-backed document exchange; optimisation captures immutable inputs and independently reviewed non-posting recommendations; reporting stores cutoff-bound checksummed snapshots and deterministic exports; and help/support combines role-filtered guidance with a scoped, versioned requester/manager case lifecycle. No routed page remains a deliberate prototype. Cross-cutting production hardening is tracked separately.

API references:

- `docs/openapi.yaml`
- `docs/frontend-backend-endpoint-map.json`
- `../ERP_IMPLEMENTATION_GAPS.md`
