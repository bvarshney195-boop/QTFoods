# Temporary QA access — Q & T Foods UAT only

This provisioning is **not** production activation or test execution.

## Purpose

Use the existing role, permission and effective-dated assignment model. The `TEMP_QA_AUDITOR` role can navigate all 69 permission-governed business screens. The other two entries in the 71-screen registry are public login and authenticated company/plant selection, which are not permissions to grant.

This role is **read-only** and grants no `ACTION:*` permissions. It cannot create, approve, post, delete, export or adjust transactional records. In particular, it does not grant Finance, HR or Quality decision authority. Record visibility remains restricted to the chosen **synthetic** QTF training company and assigned plant.

## Safe provisioning (UAT infrastructure operator)

1. Confirm a physically/logically isolated non-production application and database, with synthetic fixtures only. The target must not contain real production transactions, genuine tax documents, customer communications, bank or payroll postings.
2. Verify `APP_ENV` is **not** `production` and `QT_ALLOW_DEMO_SEEDERS=true`. Never relax these flags on production.
3. Ensure the existing training database has been seeded with the synthetic QTF training company and its 69 screen permissions.
4. Run from the UAT application environment:

   ```bash
   php artisan db:seed --class='Database\\Seeders\\TemporaryQaRoleSeeder'
   ```

5. In **Administration → Users**, invite a named tester with the `TEMP_QA_AUDITOR` role, limited to the synthetic QTF company / training plant. Set **effective_from** to the invitation date and **effective_to** to 14 days later. Complete email verification and configured MFA normally; don't share passwords or OTP codes.
6. Confirm screen navigation works; test that direct write, approval, export and cross-company requests fail with the configured denial response.
7. For actual demo *writes*, invite independent, short-lived users with the **existing** Operations, Sales, Finance and ERP Administrator roles. Test Partner and BI Analyst visibility with their own scoped users. Do not grant all actions to one QA role: doing so prevents meaningful RBAC and maker-checker testing.
8. Mark all sample data `DEMO` or `UAT`, avoid real PAN/GSTIN/email/payment recipients, and verify P&L, AR/AP, stock and payroll in production remain unchanged.

## Retire access

Deactivate each temporary role assignment and revoke sessions using Administration → Users. The `effective_to` date automatically limits the validity of assignments, but it does not delete old sessions or remove the role definition. After all active assignments have been deactivated, set the custom role to `INACTIVE` in Administration → Roles. Retain audit evidence. Do not use the development seeder against a real company context.

## Audit gates

Functional UAT still requires independent evidence: all 71 screens, role-negative actions, demo transaction chain, imported legacy bills in archive-only mode, object-store/outbox probe, demo identity quarantine, service recovery, and signed release decision. This temporary role provides no bypass of those gates.
