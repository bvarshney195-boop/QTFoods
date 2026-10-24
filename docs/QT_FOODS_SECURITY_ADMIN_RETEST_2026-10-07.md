# QT Foods Authentication and User Administration — Supplemental Retest

**Retest date:** 7 October 2026

**Source of truth:** `C:\Users\bhupe\Downloads\QT_Foods_Final_Master_Production_Readiness_UAT_Audit.html`

**Scope:** Authentication-method behavior, privileged MFA, organisation layout, user password reset/deletion, and administrator-controlled MFA requested after the 6 October master retest.

## Release decision

The requested amendments pass their executable acceptance tests. The master audit decision remains **NO-GO — 30 PASS, 2 FAIL** until the production-only evidence required by `SEC-01` and `OPS-01` is retained. No local, CI, preview-mail, or disposable-storage result is being substituted for those two production acceptance criteria.

## Amendment acceptance results

| Requirement | Result | Executed evidence |
|---|---:|---|
| Password, Email OTP, and Google Authenticator are explicit login choices | **PASS** | Chromium final-audit login acceptance verifies all three choices, branch-specific controls, and no demo credential presets. |
| A normal user can complete authentication with the selected password, Email OTP, or enrolled TOTP method | **PASS** | `AuthenticationMethodFlowTest` and `IdentityLifecycleEndpointTest` exercise each complete primary method, including password and Email OTP for a standard user that already has TOTP enrolled. |
| Password-only authentication cannot bypass MFA for `ERP_ADMIN` or an administrator-governed account | **PASS** | Backend tests stop privileged password login at `SELECT_SECOND_FACTOR`; Chromium verifies `/api/v1/me` remains `401` until the independent factor succeeds. TOTP primary requires password as its independent second factor. |
| A user without TOTP can enrol directly without relying on Email OTP | **PASS** | Password proof is required before the QR/secret is disclosed; a valid first TOTP confirms enrolment and completes sign-in. |
| User register exposes administrator-controlled Enable MFA and Disable MFA actions | **PASS** | Component and Chromium tests execute enable and disable confirmations. Both revoke active sessions; disable also removes the TOTP secret and recovery codes. Role-required MFA cannot be disabled. |
| A policy-required user cannot disable MFA from Account Security | **PASS** | UI coverage removes the self-service disable action, and an endpoint test directly attempts the call and receives `422` while enrolment remains enabled. |
| Administrator can issue a write-only temporary password | **PASS** | Backend and browser tests prove the password is hashed, never returned, sessions/tokens are revoked, and an audit event plus outbox event is written. |
| Temporary-password user must replace it before ERP access | **PASS** | `/me`, password replacement, and logout remain available; context selection and business routes return `409 PASSWORD_CHANGE_REQUIRED` until successful replacement. Chromium completes the replacement journey. |
| Administrator can delete a user without destroying audit history | **PASS** | Backend and Chromium tests prove retained tombstone state, revoked assignments/sessions/tokens/invitations/MFA, rejected later login, audit/outbox evidence, self-delete prevention, open-work protection, and last-ERP-administrator protection. |
| Organisation editor/card starts on the next row | **PASS** | Chromium checks a one-column computed grid and verifies the editor top is at or below the register bottom. |
| Emergency production administrator initialization accepts a nine-character mixed-case alphanumeric password | **PASS** | Command coverage accepts an exact nine-character compliant password, rejects eight characters, and still requires MFA for the administrator login. |

## Executed gate evidence

| Gate | Result | Evidence |
|---|---:|---|
| Backend full SQLite suite | **PASS** | **220 tests, 11,439 assertions; 4 expected PostgreSQL-only skips; 0 failures/errors.** |
| Backend PostgreSQL suite | **PASS** | **24 tests, 269 assertions; 0 failures/errors.** Includes the new migration and foundation-administration cases. |
| Frontend full unit suite | **PASS** | **35 files, 143 tests; 0 failures.** |
| Frontend production build | **PASS** | TypeScript and Vite completed; **218 modules transformed**. |
| Chromium audit and business workflows | **PASS** | **30/30 scenarios passed** in one clean run on PostgreSQL/Redis, including all final-audit, accessibility, responsive, user-administration, identity-security, and cross-module workflows. |
| Authenticated load gate | **PASS** | **301 iterations, 602/602 checks, 0 failed requests, 0 dropped iterations, p95 92.35 ms, p99 113.93 ms.** |
| Active API security gate | **PASS** | ZAP imported 15 bounded URLs and reported **0 High-risk failures** under the checked-in policy. |
| PHP SAST report | **PASS** | Semgrep 1.172.0 report contains **205 scanned files, 0 blocking findings, 0 scan errors**. The local wrapper reached its 10-minute caller timeout after the completed report was written; the report was parsed and validated separately. |
| Dependency audits | **PASS** | npm reported **0 vulnerabilities**; Composer configuration is valid and reported no security advisories. |
| Repository/release-image security | **PASS** | Repository plus app, web, and recovery images each reported **0 fixed High/Critical vulnerabilities, 0 High/Critical misconfigurations, and 0 secrets**. |

## Production evidence still required

1. `SEC-01`: retain successful `php artisan qt:security:verify-identities` output from the deployed production database and rejected-login evidence for historical demo identities.
2. `OPS-01`: retain the production outbox probe/verification JSON, receiver-side idempotency evidence, durable evidence-store hash, and restart lifecycle evidence required by the master audit.
3. Actual Gmail delivery is environment-dependent. Local acceptance uses the disposable preview/test mail channel; production Email OTP is not claimed as delivered until the configured production mail transport records a successful send and the message is received at the registered mailbox.
