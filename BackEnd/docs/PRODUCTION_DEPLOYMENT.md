# Production deployment baseline

This repository includes a separate production Compose stack. It builds a no-development-dependencies PHP-FPM image, runs it as `www-data`, exposes only Caddy on ports 80/443, obtains and persists TLS certificates automatically, and keeps PostgreSQL and Redis on the internal Compose network. Private evidence, finance archive, and partner documents use an externally managed S3-compatible object-storage service; the production graph does not deploy or require MinIO. It also emits JSON application logs, exposes token-protected Prometheus metrics, serves separate liveness/readiness probes, and schedules operational threshold checks. The normal `docker-compose.yml` remains a loopback-only local development stack.

The production stack is a single-host deployment baseline, not a high-availability design. The repository includes a tested backup/restore mechanism and recovery runbook, but do not treat it as launch-ready until the organisation has provisioned real secrets, scheduled encrypted off-host backups, connected monitoring/paging, run a target-environment restore, and formally approved its recovery objectives.

## Prerequisites

- A Linux host with a supported Docker Engine and Docker Compose plugin.
- Public DNS for the API host pointing to that host.
- Inbound TCP 80/443 and UDP 443 allowed for Caddy and ACME; PostgreSQL and Redis must remain private.
- An approved externally managed S3-compatible provider with a pre-created private bucket, an exact HTTPS endpoint, TLS validation, versioning/retention controls where required, and private network or egress access from the application and recovery hosts.
- An HTTPS frontend origin on the same site as the API, such as `https://erp.company.example` with `https://api.erp.company.example`. The session policy is deliberately `SameSite=Lax`.
- SMTP credentials for a TLS-capable delivery service.
- An absolute host backup path on approved encrypted/restricted storage, writable by UID/GID 65532, plus approved retention and recovery objectives before live data is accepted.
- A protected log/metric collection path and an on-call alert destination. The repository emits telemetry but does not deploy the organisation's collector, dashboard, paging service, or incident rota.

## Prepare configuration and secrets

Copy `.env.production.example` to an untracked file outside the repository or to the deployment host's protected configuration directory. Replace every `REPLACE_...` and `example.com` value. On Linux, restrict a file-backed configuration to its owner:

```bash
chmod 600 /secure/path/qtfoods-production.env
```

Generate the Laravel key from 32 cryptographically random bytes. One portable option is:

```bash
docker run --rm php:8.5-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Use independent random values for the Laravel key, PostgreSQL password, object-storage application credentials, SMTP password, metrics bearer token, and any outbox or alert signing secret. Provision the object-storage bucket and least-privilege identity in the managed provider before deployment; Compose does not create provider accounts or buckets. Prefer injecting these values from the target platform's secret manager. The application checks minimum safety properties and known placeholders, but that cannot prove uniqueness, custody, rotation, provider-side retention, or encryption policy.

Important relationships:

- `APP_HOST` is a host name only, without a scheme, port, or path.
- `APP_TIMEZONE=UTC` is mandatory for persisted instants. User-facing date/time rendering uses each plant's explicit IANA timezone, such as `Asia/Kolkata`.
- `QT_CORS_ALLOWED_ORIGINS` is an exact comma-separated HTTPS origin list without trailing slashes.
- `QT_FRONTEND_URL` must match the public frontend used in invitation, verification, and reset links.
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, and `AWS_USE_PATH_STYLE_ENDPOINT` are provider-neutral S3-compatible settings. `AWS_ENDPOINT` must be the provider's exact HTTPS endpoint; do not put credentials, query parameters, or a bucket path in it.
- Both Laravel `private` and `evidence` disks remain S3-backed and use the configured private bucket. `QT_PRIVATE_DOCUMENT_DISK=private`, `QT_EVIDENCE_DISK=evidence`, and `QT_READINESS_OBJECT_STORAGE=true` are enforced in production.
- `MAIL_SCHEME=smtp` with `MAIL_REQUIRE_TLS=true` uses required STARTTLS; use `smtps` for implicit TLS where the provider requires it.
- `QT_METRICS_TOKEN` is a dedicated random value of at least 32 characters. Give it only to the scraper and rotate it independently of user or integration credentials.
- `QT_ALERT_TRANSPORT=log` emits alert-ready JSON to stderr. Set it to `http` only with an approved HTTPS `QT_ALERT_HTTP_ENDPOINT` and an independent `QT_ALERT_SIGNING_SECRET` of at least 32 characters.
- `QT_BACKUP_HOST_PATH` is a protected host path, never a repository directory in production. `QT_BACKUP_RETENTION_DAYS`, `QT_RECOVERY_RPO_MINUTES`, `QT_RECOVERY_RTO_MINUTES`, and `QT_RECOVERY_OBJECT_LIMIT` are bounded and checked at production boot.
- `LOG_CHANNEL=stderr`, `LOG_LEVEL=info`, and the Monolog JSON formatter are enforced because request-completion and healthy operational events are emitted at info level.
- Keep `QT_ALLOW_DEMO_SEEDERS=false`, `QT_ALLOW_DEMO_AUTHENTICATION=false`, `QT_IDENTITY_PREVIEW_LINKS=false`, and `APP_DEBUG=false`.

The real production environment file is ignored by Git and excluded from the production Docker build context.

The production Dockerfile and repository-owned infrastructure services are digest-pinned to the versions verified with this repository. The external object-storage provider is governed and versioned by its operator, so record its service configuration and control changes separately. Review upstream release notes, update repository digests deliberately, rebuild, and rerun the full verification suite as a controlled dependency change.

## Validate before starting

From `BackEnd`, render Compose and build all three release targets:

```bash
docker compose --env-file /secure/path/qtfoods-production.env -f docker-compose.production.yml --profile recovery config --quiet
docker compose --env-file /secure/path/qtfoods-production.env -f docker-compose.production.yml --profile recovery build app gateway recovery
```

Run the application-level policy check from the built image without starting its dependencies:

```bash
docker compose --env-file /secure/path/qtfoods-production.env -f docker-compose.production.yml run --rm --no-deps app php artisan qt:deployment:verify
```

Production boot fails closed if the key, URL, host/proxy/CORS policy, cookies, database/Redis/object-store credentials, SMTP/TLS settings, masking, structured logging, Redis queue/session/metric state, readiness probes, metrics token, alert transport, recovery-policy bounds, preview-link setting, or seeder policy is unsafe. A failed check must be corrected; do not bypass it by changing `APP_ENV`.

## Start and verify

```bash
docker compose --env-file /secure/path/qtfoods-production.env -f docker-compose.production.yml up -d --build
docker compose --env-file /secure/path/qtfoods-production.env -f docker-compose.production.yml ps
curl --fail https://api.erp.company.example/api/health
curl --fail https://api.erp.company.example/api/ready
curl --fail --header "Authorization: Bearer $QT_METRICS_TOKEN" https://api.erp.company.example/api/metrics
```

Startup ordering is deliberate: PostgreSQL and Redis become healthy; the one-shot migration service re-runs the production guard and applies forward migrations; then PHP-FPM, the queue worker, scheduler, and TLS gateway start. The managed object-storage bucket and identity must already exist, and readiness remains false if that external dependency cannot be reached. The production stack never invokes `DatabaseSeeder`.

The migration service also runs `qt:security:verify-identities` after migrations. It fails deployment if a known demo identity is active or unclassified, a shared `prototype` credential still verifies, a demo session remains active, a known training company/plant is unclassified, or a real user has an active assignment into a synthetic context. Retain its JSON output with the release evidence.

## Bootstrap the first production administrator

Run this controlled command once from the application container or managed-service shell after migrations and approved system definitions are present:

```bash
php artisan qt:identity:bootstrap-admin bvarshney195@gmail.com \
  --name="ERP Administrator" \
  --company-code=QTF-LIVE --company-name="Q & T FOODS LTD" \
  --plant-code=HQ --plant-name="Head Office" --timezone=Asia/Kolkata \
  --confirmation=CREATE_PRODUCTION_ADMIN --no-interaction
```

The command creates only non-demo company/plant records, uses the active `ERP_ADMIN` system role, assigns a random unusable initial secret, records an audit event and sends a password-setup link to the registered mailbox. It never enables demo authentication or stores a shared password. Re-running the exact command is a no-op; add `--send-password-reset` only when an already-provisioned owner needs a replacement setup link.

The administrator can begin with Email OTP or the Google Authenticator path. Because `ERP_ADMIN` is an MFA-required role, Email OTP leads to authenticator enrollment and no authenticated session exists until TOTP succeeds. After using the password-setup link, password authentication also stops at the mandatory Email OTP or TOTP second-factor gate.

If the hosting plan blocks SMTP, initialise only the bootstrapped administrator's first password through managed deployment secrets. Set `QT_BOOTSTRAP_ADMIN_EMAIL`, a unique password of at least 16 mixed-case alphanumeric characters in `QT_BOOTSTRAP_ADMIN_PASSWORD`, and `QT_BOOTSTRAP_ADMIN_PASSWORD_CONFIRMATION=INITIALIZE_PRODUCTION_ADMIN_PASSWORD`, then start an initialization deployment. The Render container runs `qt:identity:initialize-bootstrap-password` after migrations; it never prints the password and refuses to reset an account whose first password is already established. This deployment intentionally stops before serving HTTP while the temporary password remains configured. After the log reports `initialized`, remove all three variables and redeploy; the normal production guard then permits the service to start. The administrator can choose Password, select Google Authenticator as the required second factor, scan the QR code, and confirm a valid TOTP without Email OTP.

Retain the command JSON and then rerun `php artisan qt:security:verify-identities`. The deployment is not accepted if the new user, company or plant is classified as demo, if the role assignment is inactive, if email delivery is not `SENT`, or if the identity verifier fails.

## Security and integration release probes

The configured event receiver must verify `X-QT-Signature`, use `Idempotency-Key` as the stable event identity, and return both `X-Acknowledgement-ID` and `X-Acknowledged-Event-ID`. The latter must exactly match `X-QT-Event-ID`; an unbound or mismatched acknowledgement is treated as a failed attempt.

Create a unique probe only after the real receiver and durable object store are connected:

```bash
docker compose --env-file /secure/path/qtfoods-production.env -f docker-compose.production.yml \
  exec app php artisan qt:release:create-outbox-probe
```

Retain the returned event ID, evidence path and SHA-256. Exercise the receiver's approved synthetic failure mode until the event records RETRY and QUARANTINED, replay it from `ADM-INT`, restart the worker, scheduler and application containers, and deliver/replay the same stable event ID. The receiver must report an idempotent replay without repeating its business side effect. Then run:

```bash
docker compose --env-file /secure/path/qtfoods-production.env -f docker-compose.production.yml \
  exec app php artisan qt:release:verify-outbox EVENT_UUID \
  --require-failure-lifecycle --require-idempotent-replay --require-worker-restart \
  --evidence-path=RELEASE_READINESS_PATH --evidence-sha256=EXPECTED_SHA256
```

The command exits non-zero unless retained attempts prove real HTTP delivery, an event-bound receiver ACK, retry, quarantine, idempotent replay, distinct worker instances, active oldest-backlog-age monitoring and unchanged durable evidence after restart. Its JSON output is the release artifact for `OPS-01`; a log-transport acknowledgement cannot pass.

Caddy redirects HTTP to HTTPS and persists ACME state in `caddy_data` and `caddy_config`. The application trusts only the immediate reverse proxy, validates the configured host, rejects insecure protected traffic, and adds HSTS, frame, MIME-sniffing, referrer, and browser-permission headers to secure responses.

## Observability and alert routing

`GET /api/health` is a dependency-free liveness probe. `GET /api/ready` verifies PostgreSQL, Redis, and the private object store and returns HTTP 503 when any required dependency is unavailable. Do not use liveness to decide whether the instance can receive business traffic; route traffic using readiness.

Every HTTP response carries `X-Request-ID`, `X-Correlation-ID`, and a W3C `traceparent`. Valid caller-supplied UUID request/correlation identifiers and valid trace parents are continued; malformed values are replaced. Request IDs, trace IDs, and server span IDs are written onto new audit and outbox records, and outbox HTTP delivery starts a child span and forwards both correlation and trace headers. Search `ADM-AUD` or `ADM-INT` with an identifier from an error response or JSON log to follow the durable evidence chain.

Successful and failed requests are logged as `http_request_completed` without bodies, query strings, cookies, authorisation headers, or metric tokens. Route templates are used instead of record-specific URLs. Queue and integration events use `queue_job_failed`, `outbox_batch_processed`, `outbox_delivery_completed`, and `outbox_delivery_failed`; scheduled monitoring uses `operational_health_checked`, `operational_alert`, and `operational_alert_delivery_failed`. The request and outbox completion records are duration-bearing trace spans. Ship container stderr as JSON without multiline transformation and retain the identifiers as indexed fields.

`GET /api/metrics` uses Prometheus text format and requires the dedicated bearer token. It exposes bounded HTTP method/status/duration series plus dependency latency, queue depth, failed jobs, outbox state/age/failures, audit outcomes/context coverage, and current alert counts. Never put the token in a query string. Scrape over HTTPS, keep the endpoint out of public dashboards, alert on scrape failure, and rotate the token through the same controlled deployment process as other secrets.

The scheduler runs `qt:observability:check` every five minutes. It evaluates dependency availability, failed jobs, quarantined/retrying/late outbox events, monitored queue depth, and recent audit records missing request/correlation/trace context. Thresholds are explicit `QT_ALERT_*` values in `.env.production.example`. The command exits 0 when healthy, 1 for warnings, and 2 for critical conditions; run it directly during commissioning:

```bash
docker compose --env-file /secure/path/qtfoods-production.env -f docker-compose.production.yml exec app php artisan qt:observability:check
```

Alert state is kept in Redis so unchanged alerts are suppressed until `QT_ALERT_RENOTIFY_SECONDS`; a transition back to healthy emits one resolved notification. The `log` transport requires the platform log collector to route `operational_alert` records to the on-call service. The optional HTTP transport sends the same safe snapshot with `X-QT-Alert-Signature: sha256=...`; the receiver must verify that HMAC over the exact request body and return a 2xx response. Test firing, repeat suppression, renotification, recovery, and receiver failure before accepting production traffic.

## Backup and recovery

The opt-in `recovery` Compose profile builds an image with pinned PostgreSQL/Redis clients and a source-built, security-patched S3-compatible `mc` client; the client name does not require a MinIO server. It runs without root privileges or Linux capabilities, connects through the same generic `AWS_*` settings, creates atomic checksummed PostgreSQL-plus-object snapshots, verifies exact inventories, enforces bounded retention, recreates the database during restore, makes the object bucket exact, and invalidates Redis operational state. The application command `qt:recovery:verify` then streams every database-backed evidence/archive/partner object and reconciles migrations, outbox state, and failed jobs.

Follow [`RECOVERY_RUNBOOK.md`](RECOVERY_RUNBOOK.md) for the write-stop boundary, protected-storage attestation, scheduled backup, exact destructive confirmation, exhaustive post-restore checks, queue/outbox decisions, application rollback, and drill evidence. The repeatable disposable drill is `deploy/recovery/verify-recovery.ps1`; its separate test-only overlay uses an isolated object-store test double and must never be deployed or substituted for a restore exercise against the selected managed provider and formal RPO/RTO approval.

## Frontend release

Copy `FrontEnd/.env.production.example` to an untracked `.env.production`, set `VITE_API_BASE_URL` to the exact HTTPS API origin, then build:

```bash
cd ../FrontEnd
npm ci
npm run build
```

Publish `dist/` through an HTTPS static host with SPA fallback to `index.html`. Its origin must exactly match `QT_CORS_ALLOWED_ORIGINS`; because authenticated requests use cookies, do not combine wildcard CORS with credentials.

## Operations and rollback boundary

- Inspect service health, readiness, metrics, scheduled-check output, and JSON logs with `docker compose ... ps` and `docker compose ... logs --since=30m SERVICE`.
- Deploy immutable `IMAGE_TAG` values so the previous application and gateway images remain identifiable.
- Run and monitor the recovery profile on the approved schedule, copy verified snapshots to immutable off-host custody, and back up Caddy state separately through the platform volume facility. Redis is deliberately invalidated rather than restored.
- Run the documented isolated restore and queue/outbox reconciliation at least quarterly and after material persistence changes; retain measured evidence against the approved RPO/RTO.
- Roll back application images only after confirming the migrated schema is backward-compatible. Never improvise a destructive database rollback on the live volumes.

Production scheduling/off-host backup custody, target-environment recovery approval, external telemetry/alert-service onboarding, protected-branch enforcement, independent target-environment penetration/capacity approval, and high availability remain deployment work explicitly tracked in `ERP_IMPLEMENTATION_GAPS.md`.
