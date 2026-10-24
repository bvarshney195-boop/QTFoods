# CI quality and security gates

The `Quality gates` GitHub Actions workflow runs on pull requests, pushes to
`main`, a weekly schedule, and manual dispatch. Configure branch protection to
require the stable `Required quality gate` check; the workflow's final job fails
unless every applicable upstream gate succeeds.

## What is enforced

- Composer and npm install from lockfiles, audit the complete development and
  runtime graphs, run all backend/frontend tests, and produce the frontend build.
- GitHub dependency review rejects pull-request dependency changes with known
  High or Critical vulnerabilities. Dependabot proposes weekly npm, Composer,
  Docker, and GitHub Actions updates.
- CodeQL scans JavaScript/TypeScript and GitHub Actions with the extended
  security query suite. A separate digest-pinned Semgrep 1.172.0 job runs the
  official PHP and security-audit registry policies over Laravel application,
  route, configuration, and database code; scan errors and `ERROR` findings
  fail the required gate, and its JSON evidence is retained for 14 days.
- Digest-pinned Trivy 0.74.0 scans the repository for vulnerable dependencies,
  secrets, and misconfiguration, builds all three production image targets, and
  scans each image for fixed High/Critical vulnerabilities. Unfixed upstream
  operating-system findings remain visible in JSON but do not block a release.
  The release build replaces the stale embedded gateway and recovery-tool
  binaries with Caddy built from its checked-in module lock and MinIO `mc` built
  from an exact source commit plus explicitly upgraded security dependencies;
  Trivy scans the resulting Go binaries as well as their operating systems.
- Digest-pinned k6 1.8.1 authenticates as the disposable ERP administrator and
  sustains 10 read iterations per second for 30 seconds. The gate requires no
  dropped iterations, fewer than 1% failed requests/checks, global p95 below
  750 ms, global p99 below 1,500 ms, and the endpoint thresholds in
  `performance/erp-read-smoke.js`.
- Digest-pinned ZAP 2.17.0 receives an ephemeral authenticated session and CSRF
  token, imports `security/openapi-dast.yaml`, and actively scans the bounded
  identity, context, work, organisation, location, reporting, and help surfaces.
  Scanner/add-on updates are disabled during the run. Tool failures and High
  risk alerts fail CI; lower-risk alerts remain in the uploaded reports.

All third-party Actions are commit-SHA pinned. Scanner reports are retained as
workflow artifacts for 14 days and the ephemeral ZAP credential file is removed
before artifacts are collected.

## Run locally

Node.js 20 or newer and a running Docker engine are required. From `FrontEnd`:

```bash
npm run test:quality:repository
npm run test:quality:php-sast
npm run test:quality:dynamic
```

The repository gate writes Trivy JSON to `quality-results/` and retains built
images in the local Docker cache. The dynamic gate owns only the fixed
`qtfoods-erp-quality` Compose project: it removes that project's disposable
volumes before and after the run, uses an E2E-only private local Laravel disk
instead of object storage, writes k6/ZAP reports to `quality-results/`, and
never resets the normal development or `qtfoods-erp-e2e` projects. On failure it
captures `docker compose ps -a` and the final 500 log lines in
`quality-results/stack-diagnostics.txt` before teardown.

The ZAP contract is deliberately non-destructive and bounded so it can run on
every change. Keep its routes and input constraints aligned with the canonical
`BackEnd/docs/openapi.yaml`; add representative surfaces when new trust
boundaries or input classes are introduced.

These automated checks are a repeatable engineering baseline, not an
independent human penetration test or a production-capacity certification.
Before go-live, commission an authorised test against the approved target
environment, remediate/retest findings, approve residual risk, and derive load
profiles and service-level thresholds from measured production demand.
