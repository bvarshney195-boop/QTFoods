# Q & T Foods ERP + CRM

The repository contains the Laravel backend in [`BackEnd`](BackEnd/README.md) and the React frontend in [`FrontEnd`](FrontEnd/README.md).

For onboarding and day-to-day use, see the illustrated [`ERP Roles, Permissions & User Manual`](docs/QT_FOODS_ERP_ROLES_PERMISSIONS_USER_MANUAL.pdf). It covers every standard role and screen permission, with detailed Operations/Finance workflows and side-by-side live screen examples.

All 71 routed screens are implemented through the complete master-data, inventory, procure-to-pay, manufacturing, order-to-cash, dispatch, claims, finance, multi-plant, partner, optimisation, controlled-reporting, and help/support workflows. See [`ERP_IMPLEMENTATION_GAPS.md`](ERP_IMPLEMENTATION_GAPS.md) for the verified functional baseline and remaining production-readiness work.

A fail-closed, TLS-terminating single-host production baseline with structured telemetry, protected metrics, dependency readiness, trace propagation, scheduled operational alerts, and tested PostgreSQL/managed-S3/Redis recovery controls is documented in [`BackEnd/docs/PRODUCTION_DEPLOYMENT.md`](BackEnd/docs/PRODUCTION_DEPLOYMENT.md) and [`BackEnd/docs/RECOVERY_RUNBOOK.md`](BackEnd/docs/RECOVERY_RUNBOOK.md). Production/UAT use a provider-neutral external managed S3-compatible service; MinIO remains only an optional local-development or isolated recovery-test fixture. The baseline intentionally does not replace organisation-specific secret management, external collector/paging onboarding, scheduled encrypted off-host custody, target-infrastructure drills, or disaster-recovery approval.

Repository CI/CD quality controls are documented in [`quality/README.md`](quality/README.md). The SHA- and digest-pinned workflow audits locked dependencies, runs the complete backend/frontend/browser suites, scans supported source and workflow languages, scans the repository and all production image targets, and exercises authenticated k6 load plus ZAP active API security against disposable infrastructure. Configure branch protection to require its stable `Required quality gate` check.
