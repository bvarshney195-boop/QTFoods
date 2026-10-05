# QT Foods Final Master Production Readiness & UAT Audit — Retest Evidence

**Retest date:** 5 October 2026  
**Source of truth:** `C:\Users\bhupe\Downloads\QT_Foods_Final_Master_Production_Readiness_UAT_Audit.html`  
**Tested codebase:** the current working tree in `C:\Users\bhupe\Downloads\latestQT\QTFoods`

## Release decision

**Formal deployment decision: NO-GO — 30 PASS, 2 FAIL. Code remediation: 32/32 addressed with passing executable release-candidate tests.**

The application changes and all locally executable acceptance tests pass on both SQLite and PostgreSQL. `SEC-01` and `OPS-01` remain formal FAIL results only because their wording requires evidence from the deployed production identity store, external receiver and durable object store. The code now supplies fail-closed production gates and a real-network release probe for collecting that evidence; this report does not substitute the disposable receiver or test storage for the target services.

This report does not claim that the tested working tree has been deployed.

## Authentication acceptance result

PASS for the implemented login flow:

- The login screen presents Password, Email OTP, and Google Authenticator as explicit choices.
- The login bundle contains no demo-role chooser, preset email, preset password, or feature flag capable of restoring those shortcuts; Chromium verifies that the email starts empty and no `prototype` guidance is rendered.
- Selecting Password reveals the password field and performs password verification.
- Selecting Email OTP sends a short-lived, attempt-limited code to the registered email. An unknown email receives the required organisation-administrator guidance and no challenge is issued.
- Selecting Google Authenticator asks for TOTP when already enrolled. If not enrolled, the user must first prove control of the registered email; only then is the enrolment QR/secret shown, after which a valid TOTP completes enrolment.
- A privileged `ERP_ADMIN` cannot establish a session with a password alone. Password primary authentication requires Email OTP or TOTP as a second factor. TOTP used as the primary method requires independent Email OTP. Email OTP used as the primary method requires TOTP or TOTP enrolment.
- Browser evidence explicitly checks `/api/v1/me`: it returns `401` after the privileged password is accepted and remains `401` after merely choosing the second factor; it returns `200` only after the factor succeeds.

## Executed test evidence

| Test gate | Result | Evidence |
|---|---:|---|
| Backend full SQLite suite | PASS | **204 tests, 11,234 assertions; 4 skipped; 0 failures/errors.** The four catalog checks require PostgreSQL and are covered by the next gate. |
| Backend PostgreSQL relational/release suite | PASS | **25 tests, 261 assertions; 0 skipped/failures/errors.** Covers the four catalog checks, concurrency, scoped cost-provenance FK/checks, identity quarantine/isolation and the live-network outbox lifecycle. |
| Frontend full unit suite | PASS | **34 files, 135 tests; 0 failures.** |
| Frontend production build | PASS | `vite build` completed; **217 modules transformed**. |
| Chromium final audit acceptance | PASS | **6/6 tests**: three-method login without demo presets, unregistered-email guidance, Email OTP sign-in, TOTP enrolment/sign-in, privileged MFA non-bypass, and the responsive/design contract. |

Commands used for the final gates:

```powershell
docker run --rm --user root -e APP_ENV=testing qtfoods-audit-backend ./vendor/bin/phpunit --colors=never
docker run --rm --user root --network qtfoods-audit-pg_default -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=qtfoods_e2e -e DB_USERNAME=qtfoods_e2e -e DB_PASSWORD=qtfoods_e2e qtfoods-audit-backend ./vendor/bin/phpunit -c phpunit.pgsql.xml --colors=never tests/Feature/MasterTransactionRelationalIntegrityTest.php tests/Feature/StockLockingPostgresTest.php tests/Feature/ApprovalGovernanceEndpointTest.php tests/Feature/FoundationAdministrationEndpointTest.php tests/Feature/ProductionIdentitySecurityTest.php tests/Feature/OutboxLiveReceiverAcceptanceTest.php
npm test -- --run
npm run build
$env:PLAYWRIGHT_WEB_PORT='4175'; npx playwright test e2e/final-audit-acceptance.spec.ts --project=chromium
```

The backend container was run as root only to let the test harness write its generated `public/index.html`; the production runtime user/configuration was not changed.

## Finding-by-finding result

| ID | Severity / area | Result | Acceptance evidence |
|---|---|---:|---|
| SEC-01 | Critical security | **FAIL** | Release-candidate behavior passes: the login preset UI and build flag were removed; migration `000038` classifies the seeded company/plants/users, makes demo users inactive, replaces their shared hashes and revokes their sessions; production context resolution excludes synthetic companies/plants; startup runs `qt:security:verify-identities` and fails on any active/unclassified demo, valid `prototype` hash, active demo session or real-user synthetic assignment. `ProductionIdentitySecurityTest` proves detection, clean post-quarantine inventory and context isolation; MFA backend/browser tests prove privileged non-bypass. **Still missing:** the verifier JSON from the deployed production database. |
| FUN-01 | High functional / finance | **PASS** | `OrderToCashEndpointTest::test_draft_and_cancelled_orders_never_enter_recognized_revenue` verifies draft and cancelled recognized revenue is `0.000000`, booked value remains separately available, and the summary reconciles. |
| FUN-02 | High functional / returns | **PASS** | `UnsoldReturnCommandEndpointTest::test_missing_position_is_safely_provisioned_by_location_and_reconciles_through_finance` verifies an eligible quarantine position is selected/provisioned by plant, lot, SKU, owner, quality and UOM and that receipt reconciles downstream. `UnsoldReturnCasePanel` tests verify missing setup blocks receipt with actionable guidance and an eligible destination enables it. |
| OPS-01 | High operations / integration | **FAIL** | Release-candidate behavior passes over a separate real TCP receiver—not an HTTP facade or log ACK. `OutboxLiveReceiverAcceptanceTest` validates HMAC and stable idempotency key, rejects an ACK bound to another event, records RETRY then automatic QUARANTINED, performs idempotent operator replay, restarts the receiver/storage client, processes through three worker identities, re-delivers with the same ACK and exactly one receiver side effect, detects a 10-minute backlog, and verifies the evidence SHA-256. Production requires HTTP plus bound ACK and a positive age threshold. `qt:release:create-outbox-probe` and `qt:release:verify-outbox` emit retained JSON evidence. **Still missing:** those commands passing against the deployed external receiver, worker and S3-compatible evidence store after an actual service restart. |
| FIN-01 | High finance | **PASS** | `OrderToCashEndpointTest::test_lead_to_cash_claim_and_profitability_chain_is_governed` verifies recognized, costed and uncosted revenue, `cost_coverage_percent`, partial-margin behavior, and a controlled provenance backfill that produces audit/outbox evidence and reaches complete coverage. PostgreSQL additionally enforces the source as the exact company/plant-scoped batch-cost row and validates provenance-state consistency. |
| UI-01 | High UI/UX | **PASS** | Chromium acceptance checks a full-width register, local horizontal table scrolling, sticky identifier/action columns, a 480–640 px desktop drawer, and an exact full-width mobile drawer. |
| UI-02 | Medium UI/UX | **PASS** | Chromium verifies business guidance uses a neutral white surface; success styling remains reserved for successful status/feedback. `PageHeader` unit coverage verifies business wording rather than internal delivery codes. |
| UI-03 | Medium UI/UX | **PASS** | `FeedbackToast` tests verify top-right accessible announcement, no focus theft, automatic dismissal after five seconds, and an explicit dismiss button. |
| UI-04 | Medium form control | **PASS** | `P2Workspaces` verifies business-specific quantity wording, inline error association through `aria-describedby`, focus on the invalid input, and no raw schema/path wording. |
| UI-05 | Medium functional / approval | **PASS** | `PurchaseRequisitionWorkspace::requires rejection evidence before returning a request for correction` verifies confirmation names the request, requester, amount and reason, requires the reason, and retains safe decision focus. |
| UI-06 | Medium UI/UX | **PASS** | Browser drawer evidence exposes the customer/business details and keeps the UUID inside collapsed technical details. |
| UI-07 | Medium formatting | **PASS** | Browser evidence verifies `₹224.20` and non-wrapping numeric cells. Shared formatting renders money to two decimals and unit costs to as many as four, with tabular numerals. |
| UI-08 | Medium functional / allocation | **PASS** | Order-to-cash endpoint coverage asserts allocation totals, picked quantities, and FEFO-derived allocation fields; unknown values are represented as unavailable rather than fabricated. |
| UI-09 | Medium functional / UOM | **PASS** | Inventory foundation coverage verifies mixed-UOM stock is not presented as one summed quantity and instead provides a per-UOM breakdown. |
| UI-10 | Medium finance UI | **PASS** | Order-to-cash coverage verifies `PARTIALLY_PAID` then `SETTLED` while `document_retention_status` remains a separate field. |
| UI-11 | Medium terminology | **PASS** | App-shell/page-header tests and browser identity evidence use “Finance Manager” and business names consistently, with internal codes kept out of primary labels. |
| UI-12 | Medium typography / zoom | **PASS** | Browser computed-style assertions verify 16/24 px body, 28/36 px heading and 14 px table text at 1920, 1366, 768, 683 and 390 CSS px. The 683 px case reproduces a 1366 px browser at 200% zoom; every case verifies no document-level horizontal overflow. |
| UI-13 | Medium visual system | **PASS** | Browser computed styles verify the `#F6F8FA` page background, white panels, `#DDE5E1` borders and 10 px radius. |
| UI-14 | Medium responsive forms | **PASS** | Browser evidence verifies one document scroll context, two form columns above 1024 px, one column below it, minimum field sizing, sticky form actions, and no viewport overflow. |
| UI-15 | Low navigation | **PASS** | `AppShell` tests and browser evidence verify one Ctrl+K hint, SVG navigation icons, compact dismissible recent pages, persistent navigation and the full identity/role tooltip. |
| UI-16 | Medium permission UX | **PASS** | `OptimisationWorkspace::uses permission-aware prerequisite guidance and never offers an unavailable create action` verifies missing released demand or write permission hides `+ New` and shows business guidance without `PLAN-DEM`. |
| UI-17 | Medium status semantics | **PASS** | `StatusBadge` tests verify semantic mappings; browser evidence verifies Draft is neutral rather than success-green. |
| UI-18 | Medium reporting | **PASS** | `ReportingHelpWorkspaces` verifies snapshot totals and rows, export creation/title, collapsed integrity metadata and a working column picker. The report uses the page rather than a nested competing scroller. Backend report/export tests verify immutable source rows. |
| UI-19 | Medium returns UX | **PASS** | `UnsoldReturnCasePanel` tests verify missing quarantine destination disables receipt and gives configuration/retry guidance, while an eligible destination enables it; backend tests prove the safe receiving path. |
| UI-20 | Low security UX | **PASS** | `AccountSecurityPanel` verifies Active is the default tab, History is paginated, identifiers are secondary, and revoke confirmation names the browser/device. Identity lifecycle endpoint tests verify current-device and scoped administrative revocation. |
| UI-21 | Medium async UX | **PASS** | `P2Workspaces` verifies `aria-busy`, loading state, preserved register/filters on failure, retry, saving-state disablement and duplicate-submit prevention. |
| UI-22 | Medium state hygiene | **PASS** | `P2Workspaces` verifies selected detail clears when filtering removes the record and receipt allocation/total refresh when the selected invoice changes; form errors clear when the form closes and reopens. |
| UI-23 | Medium partner security | **PASS** | `PartnerPortalWorkspace` uses an entitled business-order picker and submits only `sales_order_id`; UUID and tenant fields are absent. `PartnerPortalEndpointTest::test_partner_workspace_is_exactly_tenant_scoped_and_entitlement_gated` verifies server-derived tenant scope and rejection of foreign records. |
| OBS-01 | Medium finance observation | **PASS** | `FinanceOperationsEndpointTest::test_expense_manual_ledger_overhead_asset_and_payroll_are_posted_with_controls` asserts historical acquisition cost `120000`, current carrying amount `117000`, disposal carrying amount `117000`, then zero current carrying amount, with a balanced posted `120000` journal. |
| OBS-02 | Medium manufacturing observation | **PASS** | `ManufacturingExecutionEndpointTest::test_complete_production_quality_packing_trace_recall_and_cost_chain` asserts first-pass good `94`, recovered `2`, final good `96`, scrapped rework `1`, first-pass yield `94%`, final yield `96%`, and reconciliation to the recorded input/output events. |
| OBS-03 | Medium date/time observation | **PASS** | Backend persistence is now fail-closed to UTC (`APP_TIMEZONE=UTC`), while each plant retains an explicit IANA display timezone. PostgreSQL proves a ten-minute-old outbox record is measured as ten minutes old rather than shifted to zero. Frontend `dateTime` tests verify the same instant renders once in `Asia/Kolkata` regardless of source offset and date-only values retain their calendar date. |
| OBS-04 | Medium partner security observation | **PASS** | Partner endpoint coverage verifies an explicit response allowlist, including absence of company/plant/creator/notes/credit snapshots, and proves tenant entitlement plus foreign-record rejection. |

## Required production closure evidence

The release remains NO-GO until both items below are demonstrated and attached to the release record:

1. **SEC-01:** Deploy the migration and retain the successful JSON from `php artisan qt:security:verify-identities`. The production Compose migration service runs it automatically and will refuse startup on an unsafe inventory. Also retain one rejected login attempt for each historically shared demo email.
2. **OPS-01:** Run `php artisan qt:release:create-outbox-probe` against the target services, exercise the approved receiver failure/recovery path, restart the target application/worker/scheduler, and run `php artisan qt:release:verify-outbox EVENT_UUID --require-failure-lifecycle --require-idempotent-replay --require-worker-restart --evidence-path=PATH --evidence-sha256=SHA256`. Retain the passing JSON plus receiver-side proof of one business side effect.

Only after those environment-level checks pass should the two rows and the release decision be changed.
