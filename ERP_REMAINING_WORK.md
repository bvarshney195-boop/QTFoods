# Q & T Foods ERP - Remaining Work

Snapshot date: 2026-09-15

## Current position

The agreed functional UAT scope remains implemented: all 71 routed screens are live, role-scoped navigation and API access are fail closed, and the sidebar uses user-friendly business names instead of visible screen codes. The latest runtime-bearing release candidate has passed its complete required GitHub quality gate and is ready to enter controlled UAT. This automated result is not manual UAT sign-off or production approval; the target-platform, governance, recovery, identity, capacity, and independent-security actions below remain mandatory.

### Release evidence status

| Evidence | Commit and date | Status |
| --- | --- | --- |
| Documented local checkpoint | `ad8e12ecf0fe509be264c3efcf108c42b412e04d`, 2026-09-15 | Historical local evidence only. This is the checkpoint associated with the recorded backend, frontend, E2E, k6, ZAP, recovery, and image results below; it is not acceptance evidence for a later commit. |
| Superseded failed candidate | `a2a53fdf9d771c10f9ce92dd65faf8c0cc6d3882`, GitHub Actions run `34988134179`, completed 2026-09-15 15:28 UTC | **RED / superseded.** Backend, frontend, CodeQL, PHP SAST, and Trivy passed, but browser and authenticated dynamic gates failed before the remediation below. |
| Verified implementation candidate | `32b35994bac8b4ed7909ec4ce10cca2dd551b484`, [GitHub Actions run `34997310882`](https://github.com/bhupendravaarshney/Q-TFoods/actions/runs/34997310882), completed 2026-09-15 16:53 UTC | **GREEN / Required quality gate passed.** Backend plus PostgreSQL concurrency, frontend tests/build/audit, both CodeQL analyses, PHP Semgrep SAST, Trivy repository/release-image scanning, all browser E2E workflows, and authenticated k6/ZAP passed. The retained `dynamic-quality-reports`, `trivy-security-reports`, and `semgrep-php-report` artifacts provide exact-SHA evidence. |
| Supporting local verification | Candidate `32b35994bac8b4ed7909ec4ce10cca2dd551b484`, 2026-09-15 | Composer validation/audit pass; 182 fast backend tests / 10,046 assertions and 5 PostgreSQL integrity/concurrency tests / 31 assertions pass; npm audit, 104 frontend tests, and the production build pass; browser E2E passes 22/22 without MinIO; k6 passes 600/600 checks over 300 iterations with zero failed requests/drops (global p95 66.78 ms, p99 89.35 ms); ZAP has no Medium/High findings (four Low and three Informational); Semgrep scans 193 PHP files with zero findings/errors; and Trivy reports zero fixed High/Critical vulnerabilities, High/Critical misconfigurations, or secrets across the repository and all three production images. The isolated recovery drill also passed exact restore and verification (3.76-second backup, 5.45-second restore). |

The current infrastructure remediation removes MinIO, its bootstrap client, credentials, bucket variables, dependency, and volume from the disposable E2E graph. E2E uses the private local `evidence_test` Laravel disk and disables object-storage readiness only there. Browser and dynamic orchestrators now start PostgreSQL/Redis, run migration, start app/worker/scheduler, and wait for health in explicit stages; on failure they persist `docker compose ps -a` and the final 500 log lines before teardown. Production/UAT keep the `private` and `evidence` S3 disks, mandatory object-storage readiness, fail-closed HTTPS endpoint checks, generic `AWS_*` settings, and an externally managed provider. The destructive local recovery drill uses a separate test-only object-store overlay, so recovery evidence remains reproducible without making MinIO a production dependency.

### Senior QA audit remediation status

| Audit finding | Current disposition |
| --- | --- |
| QA-001 / QA-002: E2E and dynamic environments fail to start | **Fixed and independently rerun by CI.** MinIO dependency removed, startup staged, and pre-teardown diagnostics retained. Browser E2E and authenticated k6/ZAP passed in current-SHA run `34997310882`. |
| QA-003: `main` is not protected | **Open external governance action.** Repository administration must require `Required quality gate`, restrict bypass/force-push authority, and assign failure ownership. |
| QA-004: status documents contradicted CI | Corrected. Historical evidence, current pushed SHA, and local remediation evidence are now separately labelled. |
| QA-005 / QA-006: no current DAST/load evidence | **Fixed for the current SHA.** Run `34997310882` passed authenticated k6/ZAP and retained the `dynamic-quality-reports` artifact. This remains a regression smoke/DAST baseline, not production-capacity or independent penetration-test certification. |
| QA-007 / QA-008 / QA-009 | **Open release blockers:** independent penetration test, target backup/restore/RPO/RTO approval, production secrets/SMTP, and local-auth versus SSO/IdP commissioning. |
| QA-010: deprecated artifact action runtime | Fixed in the workflow with SHA-pinned `actions/upload-artifact` v6. |
| QA-011: no PHP-aware SAST | Fixed in the workflow with digest-pinned Semgrep, official PHP/security-audit policies, blocking-error enforcement, and retained JSON evidence. |
| QA-012 / QA-013: bounded DAST and smoke-only load | Accepted only as CI regression baselines; expanded authenticated security scenarios and production-like capacity certification remain required before production approval. |
| QA-014: defects not formally tracked | **Open process action.** Create/assign repository issues or equivalent controlled tickets for the remaining external blockers and future gate failures. |
| QA-015: required fields inferred from template values | Fixed. Every governed command form now declares required paths, minimum line counts, alternatives, and conditional requirements explicitly; false-valued required booleans are supported. |

What remains is primarily target-platform commissioning, organisational control, and independent assurance. This document is the concise handoff view; [`ERP_IMPLEMENTATION_GAPS.md`](ERP_IMPLEMENTATION_GAPS.md) remains the detailed evidence record.

The unchecked boxes in the detailed tracker's "Completion definition for each screen delivery" section are a reusable standard for future screens. They are not unfinished work on the current 71-screen scope.

## Required before production approval

### 1. Approve and provision the target platform

- [ ] Name the service owner, platform owner, security owner, database owner, recovery owner, and on-call owner.
- [ ] Provision the production Linux/Docker platform, image registry, durable storage, public frontend/API DNS, and HTTPS routing.
- [ ] Confirm that only the gateway is publicly reachable; PostgreSQL and Redis must remain private, and the externally managed S3-compatible endpoint must use approved private connectivity or controlled egress.
- [ ] Decide whether the current single-host baseline meets the approved availability objective. If it does not, design and test the required high-availability and geographic-failure architecture.
- [ ] Retain immutable application, gateway, and recovery image tags for rollback.

Completion evidence: approved architecture and ownership record, reachable HTTPS frontend/API, private dependency checks, successful liveness/readiness probes, and a recorded availability-risk decision.

### 2. Provision production configuration, secrets, and email delivery

- [ ] Store the Laravel application key, PostgreSQL password, Redis password, managed object-storage credentials, SMTP credentials, metrics token, and applicable outbox/alert signing secrets in the target secret manager.
- [ ] Establish access control, rotation, break-glass access, and audit ownership for every secret.
- [ ] Replace every placeholder and example domain in the production environment without committing the resulting file.
- [ ] Configure a TLS-capable production email service and verify invitation, email-verification, and password-reset delivery.
- [ ] Decide whether the implemented local identity lifecycle is acceptable for launch or enterprise IdP/SSO is an additional requirement.
- [ ] Run `php artisan qt:deployment:verify` from the release image and resolve every failure without weakening the production environment policy.

Completion evidence: successful deployment-policy output, secret-manager inventory and rotation record, and received end-to-end identity emails with preview links disabled.

### 3. Enforce repository governance

- [ ] Protect `main` and require the stable `Required quality gate` check.
- [ ] Restrict force pushes, deletion, and bypass authority according to the organisation's change policy.
- [ ] Assign owners for pull-request failures, weekly scheduled quality runs, CodeQL/Trivy findings, and Dependabot updates.

Completion evidence: exported or reviewed branch/ruleset configuration plus one successful pull-request run of the required aggregate check.

### 4. Connect production observability and incident response

- [ ] Ship JSON container logs to the approved collector without multiline transformation and index request, correlation, trace, and span identifiers.
- [ ] Scrape the protected Prometheus endpoint over HTTPS and configure dashboards, retention, and scrape-failure alerts.
- [ ] Route `operational_alert` events to the approved paging service and named on-call rota.
- [ ] Tune thresholds from production-like measurements.
- [ ] Test alert firing, duplicate suppression, renotification, recovery notification, and receiver failure before accepting live traffic.

Completion evidence: searchable correlated request/audit/outbox trail, working dashboards, paging receipts, tested alert lifecycle, retention approval, and a named incident owner.

### 5. Operate and approve backup and recovery

- [ ] Approve RPO, RTO, retention, geographic recovery needs, and named recovery decision-makers.
- [ ] Schedule backups no less frequently than the approved RPO and monitor missed/failed jobs externally.
- [ ] Store verified copies on encrypted, access-restricted, immutable off-host storage under a separate backup identity.
- [ ] Protect Caddy certificate/configuration state separately from application snapshots.
- [ ] Run the documented restore against isolated target infrastructure, including exact object restoration, Redis invalidation, and queue/outbox reconciliation.
- [ ] Measure the target drill against the approved RPO/RTO and close every defect before launch.

Completion evidence: snapshot ID and manifest, checksum output, immutable off-host version identifier, restore timings, application verification output, named approvals, corrective actions, and next drill date. Follow [`BackEnd/docs/RECOVERY_RUNBOOK.md`](BackEnd/docs/RECOVERY_RUNBOOK.md).

### 6. Complete independent security assurance

- [ ] Commission an authorised independent penetration test against the approved target environment.
- [ ] Give the assessor the application boundaries, roles, APIs, partner-tenant model, file exchange, and administrative workflows needed for meaningful authenticated testing.
- [ ] Remediate findings, retest fixes, and formally approve residual risk.
- [ ] Re-run the retained ZAP observations through the production HTTPS gateway: intentional readable `XSRF-TOKEN`, development-server `X-Powered-By`, secure-response `X-Content-Type-Options`, generic-probe content types/404s, and informational authentication/session discovery.

Completion evidence: final independent report, remediation/retest record, production-gateway scan artifacts, and signed residual-risk acceptance with no unapproved release-blocking finding.

### 7. Complete production-like capacity and resilience testing

- [ ] Define realistic concurrency, transaction mix, file sizes, data volume, peak periods, background-job load, and service objectives with business owners.
- [ ] Run capacity, sustained-load, spike, and relevant dependency-failure tests on production-like infrastructure.
- [ ] Measure API latency/error rate, database and Redis behavior, object-storage throughput, queue/outbox lag, resource saturation, recovery, and dropped work.
- [ ] Remediate bottlenecks and update CI k6 thresholds when measured service objectives differ from the current smoke profile.
- [ ] Record the supported operating envelope and scaling/runbook triggers.

Completion evidence: approved workload model, raw results, capacity limit, service objectives, remediation/retest record, and named approval.

### 8. Complete release commissioning and approval

- [ ] Run the production Compose/configuration preflight, build all release targets, and execute the full quality workflow against the release commit.
- [ ] Configure production companies, plants, users, roles, policies, integrations, and opening/import data through an approved cutover process where required.
- [ ] Have business owners execute and sign off representative high-risk workflows, including approvals, inventory posting, production/quality release, dispatch/returns, finance posting, partner isolation, reports, and evidence retrieval.
- [ ] Rehearse rollback using immutable images and confirm schema compatibility.
- [ ] Assemble the deployment, security, capacity, observability, backup/recovery, and business-acceptance evidence into one go-live decision record.

Completion evidence: signed release checklist and go-live approval with owners, timestamps, release tag, rollback tag, known limitations, and accepted risks.

## Deferred product and integration extensions

These are described in the detailed screen inventory but are not defects in the current implemented scope. Promote an item into required work only when a contract, regulation, or approved business requirement makes it necessary.

| Area | Deferred examples |
| --- | --- |
| Identity, administration, and support | Enterprise IdP/SSO, bulk identity/party onboarding, external service-desk and knowledge-authoring integration, additional rule types, audit exports, and partner-specific transports. |
| Warehouse, procurement, and logistics | Scanner/barcode tasks, weighbridge and ASN ingestion, carrier/route integration, supplier acknowledgements/debit notes, printer/line integration, serialization, pallets, and license plates. |
| Planning, manufacturing, and quality | Statistical forecasting, automatic order demand, multi-echelon/substitution planning, solver-backed sequencing, alternate recipes/routes, OEE/machine telemetry, LIMS/instrument integration, electronic instructions/signatures, certificates, full CAPA, and regulatory workflows. |
| Commercial, finance, and reporting | External CRM/e-signatures, bank connectors/statements and treasury, statutory payroll and asset tax books, standard-cost/GL settlement, intercompany tax/invoicing/transfer pricing, scheduled/custom analytics, and external archival/compliance connectors. |
| Customer and partner automation | Enterprise federation, automated notifications, bulk partner onboarding, supplier-facing workflows, carrier/customer exchange, and additional external document transports. |

## Recommended execution order

1. Assign owners and approve the target architecture, availability objective, RPO, and RTO.
2. Enable branch protection and failure ownership before accepting further release changes.
3. Provision infrastructure, secrets, DNS/TLS, SMTP, and release configuration.
4. Deploy the release candidate and connect observability, paging, and scheduled immutable backups.
5. Complete target recovery, independent penetration, capacity, resilience, and business-acceptance exercises.
6. Remediate and retest findings, then approve the evidence-backed production release.

## Ongoing after launch

- [ ] Triage every required/scheduled quality-gate failure and dependency update.
- [ ] Rotate secrets and review access on the approved schedule.
- [ ] Monitor readiness, alerts, queue/outbox age, failed jobs, audit-context coverage, storage, and capacity trends.
- [ ] Verify backups continuously and run an isolated recovery exercise at least quarterly and after material persistence changes.
- [ ] Reassess penetration, capacity, recovery, and availability controls after material architecture or trust-boundary changes.
- [ ] Keep the OpenAPI contract, DAST surface, runbooks, permissions matrix, and deferred-extension decisions aligned with each release.
