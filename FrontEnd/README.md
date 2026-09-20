# Q & T FOODS LTD ERP + CRM Frontend

This React application now opens as a role-scoped ERP workspace rather than a catalogue of every prototype screen.

## ERP entry flow

1. `ACC-LOGIN` verifies a named account and creates a server-side Laravel session using CSRF protection; MFA-enabled users complete a TOTP or one-use recovery-code challenge.
2. `ACC-CTX` shows only company and plant assignments authorised for that user.
3. The backend returns the roles and screen permissions for the selected context.
4. The application shell builds its navigation from those permissions and redirects unauthorised hashes to the user's home screen.
5. Backend middleware independently enforces the same context and screen access on API requests.
6. Account Security manages password, TOTP/recovery codes, and revocable logical device sessions; logout, device revocation, expiry, and revoked-context recovery return the user to the appropriate gate.

## Local demo accounts

All local demo accounts use password `prototype`.

| Role | Email | Context |
| --- | --- | --- |
| Sales Manager | `demo.user@qtfoods.local` | Training Plant |
| Operations Manager | `operations.user@qtfoods.local` | Training Plant |
| Finance Manager | `finance.user@qtfoods.local` | Both plants |
| ERP Administrator | `admin.user@qtfoods.local` | Both plants |
| BI Analyst | `bi.user@qtfoods.local` | Both plants |
| Partner User | `partner.user@qtfoods.local` | Training Plant / North Market tenant |

These credentials are seeded development data and must not be used outside a local environment.

The seeded `BI_ANALYST` role is intentionally read-only outside reporting. It can open `BI-REP` and `BI-PROFIT`, run controlled report snapshots, and export CSV/JSON, but it has no sales, inventory, production, finance-posting, or administration actions.

## Run

Start the backend first:

```bash
cd ../BackEnd
docker compose up -d --build app
```

Then start the frontend:

```bash
npm install
npm run dev
```

Open `http://localhost:5173`. During local development, Vite proxies `/api` to `http://127.0.0.1:8000`. Set `VITE_API_BASE_URL` only when the deployed API uses a different origin.

## Build

```bash
npm run build
```

For a deployed build, copy `.env.production.example` to the Git-ignored `.env.production`, replace the example host, and set `VITE_API_BASE_URL` to the exact HTTPS API origin. Publish `dist/` from an HTTPS static host with SPA fallback. The frontend origin must be present verbatim in the backend's `QT_CORS_ALLOWED_ORIGINS`; use sibling same-site HTTPS hosts so the secure `SameSite=Lax` session cookie is sent. See [`../BackEnd/docs/PRODUCTION_DEPLOYMENT.md`](../BackEnd/docs/PRODUCTION_DEPLOYMENT.md).

## Automated tests

Run the Vitest component suite:

```bash
npm test
```

Install the Playwright browser once, then run the live multi-role workflow:

```bash
npm run test:e2e:install
npm run test:e2e
npm run test:e2e:a11y
npm run test:quality:repository
npm run test:quality:dynamic
```

The browser suite requires Docker. It builds a separate backend at `127.0.0.1:18000`, starts the frontend at `127.0.0.1:4173`, migrates and seeds isolated PostgreSQL, Redis, and private MinIO storage, runs the real queue worker and scheduler, and removes all disposable volumes after the run. It never resets the normal development stack. `test:e2e:a11y` runs the focused four-test accessibility and browser-edge subset; it audits WCAG A/AA rules across login, context selection, both shell layouts, the security dialog, and every registered business screen.

The current verified baseline is 100 Vitest component tests and 22 live Chromium workflows, plus a clean production type-check/build.

The two quality commands require Docker. The repository command scans source, dependency manifests, configuration, and all three production backend image targets with pinned Trivy. The dynamic command uses its own disposable Compose project for the authenticated k6 threshold test and ZAP active API scan. Gate scope, exact thresholds, reports, CI behavior, and the required independent pre-release penetration/capacity work are documented in [`../quality/README.md`](../quality/README.md).

Authentication, context selection, and access control use the live backend. Invitation acceptance, password reset, email verification, TOTP/recovery challenges, password change, and self/admin logical-device revocation are live; development email links are environment-gated. `ADM-ORG`, `ADM-LOC`, `ADM-USER`, and `ADM-ROLE` provide lifecycle-safe company/plant, hierarchical-location, invitation/direct-user/effective-assignment, and custom-role/permission administration. `ADM-RULE` creates and versions plant policies for Unsold Return and Purchase Requisition, with contiguous authority bands, SLA/escalation routing, and temporary approval delegation while showing the immutable policy applied to submitted work. `ADM-AUD` searches scoped immutable events, exposes safe diffs and identifiers, and privately views/downloads linked evidence without revealing object-storage paths. `ADM-INT` monitors delivery health and runtime settings, drills into payload/acknowledgement/attempt history, processes due work, and performs permissioned version-safe retry or quarantine operations.

`WRK-HOME` is a persisted, role/company/plant-scoped control queue for approvals, tasks, and exceptions with real counters, filters, ageing, deadlines, ownership, claim/completion actions, and record-level drill-through. `MD-PARTY` is a company-scoped party register and aggregate editor for immutable codes, organisation/individual identity, customer/supplier/carrier/service roles, multiple addresses and contacts, tax registrations, commercial terms, and controlled status transitions. `MD-BRAND`, `MD-ITEM`, `MD-SKU`, `MD-REC`, `MD-ROUTE`, and `MD-SPEC` are live company-scoped workspaces for brand agreements, catalog item families and UOM conversions, stock-bearing SKUs and packs, recipes/BOMs, production routes, and typed quality specifications. Their nested create/edit and lifecycle commands preserve owned child identifiers, enforce dependencies and uniqueness, and send optimistic versions plus idempotency keys; successful mutations are audited and outbox-backed.

`INV-STK` is a live four-register inventory workspace. It exposes scoped physical positions with owner, lot, location, quality and expiry context; derives total, available, blocked and reserved quantities on the server; opens reservation history; supports guarded reserve/release plus owner/lot create, edit and lifecycle commands; and provides a filterable movement ledger with operation and audit evidence.

`INV-ISS`, `INV-TRF`, `INV-COUNT`, and `INV-EXP` are live inventory-operation workspaces covering issue/return, transfer, count/adjustment, and expiry/disposal. Each supports filtered list/detail views, draft creation and editing, optimistic post/cancel commands, type-specific position controls, and linked ledger evidence with real loading, empty, validation, conflict, failure and success states.

`PUR-REQ` is a live INR purchase-requisition workspace with scoped item lookups, server-derived estimates, list/detail/filtering, draft creation and editing, optimistic submission and cancellation, policy/value-band evidence, and a direct-or-delegated approval inbox. Foreign currency is rejected until an explicit FX normalization policy exists. Rejection returns the request for correction and preserves resubmission lineage; maker-checker separation and exact authority remain server-enforced.

`PUR-RFQ` converts approved requisitions into governed multi-supplier sourcing events with exact source lines, issuance, complete supplier-quote entry/editing, server-calculated landed totals, ranked delivery/value comparison, and evidence-backed award. `PUR-PO` converts the awarded quote into a draft order, shows its complete source and commercial totals, records quantity/price/term amendments as immutable revisions before or after issue, and captures cancellation evidence. Both screens use scoped lookups, server-authorised actions, optimistic versions, idempotency keys, and real loading, empty, validation, conflict, failure, and success states.

`INB-GATE`, `INB-GRN`, `QC-IN`, and `INB-RETURN` complete the physical inbound chain. Operations records an issued-PO vehicle arrival, creates partial receipts by open quantity and lot, posts them into Quality Hold, splits each inspected lot into released/rejected stock, and can return only rejected on-hand quantities to the originating supplier. `FIN-AP` then creates tax-calculated supplier invoices, matches quantity and price against the PO and QC-accepted GRNs, enforces maker-checker invoice and proposal approvals, limits each multi-invoice proposal to one supplier, posts allocations, and reconciles payments to unique bank-statement references.

`PLAN-DEM`, `PLAN-MRP`, and `PLAN-SCH` are live manufacturing-planning workspaces. Operations time-phases finished/intermediate demand, releases only SKUs with effective recipes and active routes, snapshots recipe explosion and reservable-stock netting, schedules planned orders against route-derived work-center loads, and atomically reserves every required material by FEFO. Overloaded capacity or a live material shortage blocks release; cancellation returns linked reservation quantities to the inventory projection under row locks.

`PRO-ORDER`, `PRO-STAGE`, and `PRO-LOSS` continue each released schedule through production-order snapshots, exact reserved-material issue, route-sequenced stage events, actual time, good/loss/rework capture, rework resolution, and controlled completion. `QC-LAB` snapshots and evaluates the effective specification, while `QC-SAFE` exposes failed-sample deviations, CAPA/disposition, typed food-safety holds, and the enforced batch-release gates.

`PACK-ART` controls SKU artwork/coding revisions and approval, `PACK-RUN` validates the released batch and artwork before rendering lot coding and receiving traced finished stock, and `FG-LOT` drills into its production, packing, stock, input-lot, and recall evidence. `TRACE-CASE` traverses genealogy in both directions and opens/closes classified stock-blocking recalls; `COST-BATCH` finalizes immutable INR material/conversion cost, yield, unit-cost, and variance snapshots. All ten pages use live permission-aware API workspaces with optimistic versions and idempotency keys where applicable.

`CRM-LEAD`, `CRM-PRICE`, `CRM-ORDER`, and `CON-WORK` are live P2 commercial workspaces. They cover enquiry qualification/conversion, versioned price lists and discount ceilings, finance-controlled customer credit, customer contracts and committed-quantity consumption, server-priced/taxed sales orders with immutable amendments, and released/completed third-party work.

`DSP-PICK`, `DSP-LOAD`, and `DSP-POD` continue confirmed orders through locked FEFO lot allocation, picking, shipment creation, load, exact stock issue, receivable creation, and proof of delivery. `RET-CASE` ties claims to exact shipment-line quantities and governs receipt, credit, replacement, or rejection. `FIN-AR` exposes live ageing and atomically allocated collections, while `BI-PROFIT` derives order revenue, cost, margin, and margin percentage from live transactions.

`FIN-EXP`, `FIN-GL`, `COST-OH`, `ASSET-REG`, `HR-PAY`, and `ENG-MNT` provide itemized expense approval/posting, balanced journals and reversals, fiscal-period close, overhead allocation, asset depreciation/disposal, payroll snapshots/posting, and maintenance cost accounting. `FIN-AP` includes a second bank/statutory area for masked bank identities, deterministic SHA-256 AP/GST exports, single-use source selection, and acknowledgement history.

`FIN-SIM`, `FIN-ADJ`, `FIN-LEGACY`, `FIN-ARCH`, `FIN-OPEN`, and `FIN-SUP` are also live. Simulations are explicitly ledger-isolated; adjustments use maker-checker posting; historical rows are mapped and validated before posting; opening balances require line reconciliation; support diagnostics preserve snapshots without rewriting ledger data; and the private bill archive validates MIME, size, retention, checksum, duplicate content, scope, and permission before storing or retrieving bytes from MinIO.

`SCALE-PLANT` is a live P3 multi-plant workspace. It governs effective-dated legal-entity groups, plant-to-plant routes, explicit item/UOM mappings, transfer draft/submission/source approval, independent destination acceptance for cross-company lanes, separate dispatch and receipt stock movements, in-transit evidence, and balanced exchange-rate/elimination consolidation snapshots with maker-checker finalization. Source and destination visibility and commands remain constrained to their exact selected contexts.

`PORTAL-EXT` is a live dual-mode P3 partner workspace. Internal administrators govern effective party-bound access grants and explicit entitlements, then privately publish checksum-backed documents to one customer tenant. External users see only their entitled orders, shipments, invoices, claims, and documents; can submit exact-shipment claims and private inbound documents; and can download or acknowledge available outbound documents. Receipt acknowledgement is deliberately informational and never approves an internal workflow.

`OPT-PLAN` is a live P3 decision-support workspace. Operations captures an immutable, checksummed version of a released demand plan plus eligible/excluded stock, MRP shortages, and finalized costs; generates deterministic net-requirement stock/production recommendations with rationale and explicit limitations; and submits an unchanged source for independent Finance approval. Outcomes are versioned and every recommendation must have one before completion. The workspace never posts stock, production, purchasing, scheduling, or finance transactions.

`BI-REP` generates immutable, scoped trial-balance, receivable-ageing, inventory-availability, and order-fulfilment snapshots with explicit cutoffs, source freshness, stored rows/totals, and SHA-256 integrity. CSV and JSON exports are materialised from those stored rows. `ADM-HELP` searches role-relevant published guidance and supports requester-owned cases through a versioned support-manager start/resolution and requester reopen/closure lifecycle.

`RET-UNSOLD` has scoped customer/shipment/invoice/inventory lookups, a validated Sales create flow, a live recent-case detail workspace, role/state-aware Stores and Quality actions, a direct-or-delegated reviewer inbox, and approved inventory outcome/loss posting. Its staged Finance UI separately confirms the source invoice, records net credit and tax treatment, and completes exactly one receivable adjustment, refund, or replacement while showing the immutable action and balance history. The case workspace can attach privately retained PDF/image/text evidence in MinIO and shows its integrity hash, upload audit link, uploader, retention date, and authenticated download action. Current verification counts and production-readiness gaps are maintained in `ERP_IMPLEMENTATION_GAPS.md`.

See [`../ERP_IMPLEMENTATION_GAPS.md`](../ERP_IMPLEMENTATION_GAPS.md) for the complete screen inventory, prioritised backlog, and definition of done.
