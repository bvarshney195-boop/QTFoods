# Q&T Foods ERP/CRM — Formal User Acceptance Test Plan

## Purpose

This plan converts final usability review into auditable acceptance evidence. Testing must be performed by named Q&T Foods users in the Render training environment; the development team must not self-approve business acceptance.

## Required participants

| Role | Minimum testers | Primary journeys |
|---|---:|---|
| ERP administrator | 1 | context, access, audit, settings |
| Procurement | 2 | requisition → RFQ → PO → receipt |
| Stores / inventory | 2 | lot search, receipt, issue, transfer, expiry |
| Production / quality | 2 | schedule → batch → stage → QC → packing |
| Sales / dispatch | 2 | customer/order → allocation → dispatch → POD |
| Finance | 2 | receivables, payables, reports, controlled import |
| Executive / promoter | 1 | dashboard, comparisons, targets, drill-through |

## Acceptance scenarios

Each tester records `PASS`, `FAIL`, or `BLOCKED`, elapsed time, severity, evidence and comments.

1. Sign in, select the authorised company/plant, and confirm inaccessible modules stay hidden.
2. Locate a customer, supplier, SKU, invoice, PO and production batch using global search.
3. Save a work-queue view, sign in on a second browser/device, and confirm the view synchronises.
4. Personalise dashboard sections, sign in on a second browser/device, and confirm synchronisation.
5. Open notification history, mark an item read, dismiss another, change preferences, and confirm persistence.
6. Select 7/30/90/365-day analytics periods, compare current/prior values, set a target and drill through.
7. Upload a valid party CSV, review the preview, commit drafts, inspect created records, then roll back.
8. Upload an invalid item CSV and confirm row/field/value errors plus downloadable CSV error report.
9. Complete one end-to-end journey for the tester’s operational role.
10. Repeat core tasks at desktop and mobile widths using keyboard-only navigation where practical.

## Acceptance thresholds

- 100% of critical journeys pass.
- No open severity-1 or severity-2 defects.
- At least 90% task completion without facilitator intervention.
- Median System Usability Scale (SUS) score ≥ 80.
- Median task satisfaction ≥ 4/5.
- Search success ≥ 95% for known records.
- No cross-company or cross-plant data exposure.

## Evidence register

| Test ID | Tester | Role | Scenario | Result | Time | Severity | Evidence link | Comment |
|---|---|---|---|---|---:|---|---|---|
| UAT-001 |  |  |  |  |  |  |  |  |

## Defect severity

- **S1 Critical:** data exposure, incorrect posting, financial/stock corruption, authentication bypass.
- **S2 High:** core role journey cannot finish or produces materially wrong output.
- **S3 Medium:** workaround exists but creates delay, confusion or accessibility failure.
- **S4 Low:** cosmetic or wording issue without task impact.

## Sign-off

Business sign-off requires the Process Owner, Finance Controller and ERP Administrator to confirm that all thresholds are met. Any accepted residual issue must have an owner, due date and documented operational workaround.
