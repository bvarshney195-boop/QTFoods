# Backup, restore, and disaster-recovery runbook

This runbook covers the repository-owned single-host recovery baseline. PostgreSQL and the complete private bucket in the selected externally managed S3-compatible provider are the authoritative business record. Redis contains rebuildable sessions, queues, metrics, and alert state; it must not be restored beside an older database snapshot.

The defaults declare a 24-hour recovery point objective (RPO), a four-hour recovery time objective (RTO), and 35-day local retention. They are policy inputs recorded in every snapshot, not guarantees. The service owner must approve them, schedule backups frequently enough to meet the RPO, measure drills against the RTO, and maintain an encrypted immutable off-host copy.

## Recovery boundary

| State | Recovery treatment | Reason |
| --- | --- | --- |
| PostgreSQL | Custom-format logical dump; database is dropped, recreated, and restored | Authoritative relational business, audit, outbox, failed-job, and configuration state |
| Entire managed S3-compatible application bucket | Byte-for-byte local mirror with SHA-256 inventory; restore removes extra target objects | Authoritative evidence, finance archive, and partner documents |
| Redis databases REDIS_DB and REDIS_CACHE_DB | Flush after restore | Queue payloads, sessions, caches, metrics, and alert suppression can refer to state newer than the restored database |
| Caddy caddy_data and caddy_config | Protect with the host/platform volume-backup facility | Certificate/account state is operationally useful but independent of the ERP snapshot |
| Application/gateway images and protected environment | Retain immutable image tags and versioned secret/config custody outside the snapshot | A database snapshot must be paired with compatible release artifacts |

The recovery tool refuses backup or restore unless an operator attests that gateway, app, worker, and scheduler are stopped. This short write outage creates one consistency boundary across PostgreSQL and managed object storage. A no-downtime design requires target-platform PostgreSQL point-in-time recovery and versioned/replicated object storage, which are outside this single-host baseline.

## One-time preparation

Set the policy values in the protected production environment file:

~~~dotenv
QT_BACKUP_HOST_PATH=/srv/qtfoods/backups
QT_BACKUP_RETENTION_DAYS=35
QT_RECOVERY_RPO_MINUTES=1440
QT_RECOVERY_RTO_MINUTES=240
QT_RECOVERY_OBJECT_LIMIT=100000
REDIS_DB=0
REDIS_CACHE_DB=1
~~~

Create the bind-mounted directory for the fixed unprivileged recovery identity:

~~~bash
sudo install -d -m 0700 -o 65532 -g 65532 /srv/qtfoods/backups
~~~

The path must be on approved encrypted, access-restricted storage. Replicate completed snapshot directories off-host under a separate backup identity, preferably to immutable/versioned storage. Preserve all files exactly and run verify against the copied snapshot before accepting it. Monitor backup age and job failure externally; setting QT_BACKUP_STORAGE_PROTECTED=YES is a deliberate operator attestation, not an encryption mechanism.

Provision the private object-storage bucket and least-privilege credentials through the managed provider. The recovery identity must have the list/read/write/delete permissions needed to create an exact snapshot and exact restore; restrict its custody and activation as a break-glass control. Configure the provider-neutral `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, and `AWS_USE_PATH_STYLE_ENDPOINT` values in the protected environment file. Provider-side encryption, versioning, immutability, lifecycle, audit logging, replication, and recovery access must be independently commissioned and evidenced.

Build the recovery target with the release:

~~~bash
docker compose --env-file /secure/path/qtfoods-production.env \
  -f docker-compose.production.yml --profile recovery build app gateway recovery
~~~

The recovery image runs as UID/GID 65532 with a read-only root filesystem, no Linux capabilities, and a temporary no-execute /tmp.

## Create and verify a snapshot

Use a deployment lock so another operator or automation cannot start writers during the operation. From BackEnd:

~~~bash
ENV_FILE=/secure/path/qtfoods-production.env
SNAPSHOT_ID="$(date -u +%Y%m%dT%H%M%SZ)-scheduled"

docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  stop gateway scheduler worker app

docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml ps
~~~

Confirm the four writer services are stopped and no migration, administrative import, or one-off application container is running. Keep PostgreSQL and Redis available, and verify that the recovery host can reach the managed S3-compatible endpoint and private bucket. Then create the snapshot:

~~~bash
QT_RECOVERY_WRITERS_STOPPED=YES \
QT_BACKUP_STORAGE_PROTECTED=YES \
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  --profile recovery run --rm --no-deps recovery backup "$SNAPSHOT_ID"

docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  --profile recovery run --rm --no-deps recovery verify "$SNAPSHOT_ID"
~~~

A completed directory contains:

- database.dump, a readable PostgreSQL custom archive;
- objects/, the complete application-bucket mirror;
- manifest.json, including release tag, migration/object/outbox inventory, creation time, retention, RPO, and RTO;
- SHA256SUMS, covering every archive, manifest, and object byte.

Creation occurs in a restricted .partial-* directory, verifies the archive and exact checksum inventory, then renames it atomically. A failed operation is not a usable snapshot. Resume in dependency order after both commands succeed:

~~~bash
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  start app worker scheduler gateway
curl --fail "https://api.company.example/api/ready"
~~~

A scheduled wrapper must use a trap/finally handler to restart services and alert on every failure. Schedule at an interval no greater than QT_RECOVERY_RPO_MINUTES; the default requires at least daily completion.

List available manifests:

~~~bash
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  --profile recovery run --rm --no-deps recovery list
~~~

Pruning only removes valid snapshot directories older than the configured retention and requires exact confirmation:

~~~bash
QT_RECOVERY_CONFIRM="PRUNE:35" \
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  --profile recovery run --rm --no-deps recovery prune
~~~

Apply the approved retention independently to off-host copies. Never treat local pruning as proof that an immutable provider enforced its retention policy.

## Restore procedure

Restore into an isolated project or host first. In-place production restore is a break-glass action requiring the incident commander, database owner, application owner, and a recorded snapshot choice.

1. Start the RTO timer and preserve incident, alert, and current-state evidence.
2. Confirm the target PostgreSQL database, managed S3-compatible bucket, Redis instance, backup mount, environment file, and IMAGE_TAG. Read manifest.json and select an application image compatible with its recorded migration.
3. Stop and lock out gateway, app, worker, and scheduler. Confirm no one-off application or migration containers exist.
4. Verify the chosen snapshot before any destructive command.
5. If the current state is readable and policy permits, take a separately labelled safety snapshot.
6. Set the exact one-use confirmation and restore:

~~~bash
ENV_FILE=/secure/path/qtfoods-production.env
SNAPSHOT_ID=20260914T010000Z-scheduled

docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  stop gateway scheduler worker app

docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  --profile recovery run --rm --no-deps recovery verify "$SNAPSHOT_ID"

QT_RECOVERY_WRITERS_STOPPED=YES \
QT_RECOVERY_CONFIRM="RESTORE:$SNAPSHOT_ID" \
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  --profile recovery run --rm --no-deps recovery restore "$SNAPSHOT_ID"
~~~

Restore drops and recreates only the configured non-reserved PostgreSQL database, restores the archive with error-on-first-failure, makes the configured managed object bucket match the snapshot by removing extra objects, flushes both configured Redis logical databases, and changes restored PROCESSING outbox rows to RETRY while preserving their attempt counters and immutable delivery-attempt rows. A partial failure leaves traffic stopped and requires incident review; do not continue by hand-editing business tables.

Keep writers and public traffic stopped. Run the exhaustive application-level reconciliation with the compatible app image:

~~~bash
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  run --rm --no-deps app php artisan qt:recovery:verify --object-limit=0
~~~

It verifies required schema/migration state, streams every database-backed evidence/archive/partner object, recomputes SHA-256 and byte size, and reports outbox and failed-job anomalies as safe JSON. Exit codes are 0 for healthy, 1 for warnings requiring disposition, and 2 for critical failure. Any incomplete object scan is critical.

For a database restored by another platform, stale outbox locks can be released only with explicit confirmation:

~~~bash
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  run --rm --no-deps app php artisan qt:recovery:verify \
  --object-limit=0 --release-stale-outbox \
  --confirmation=RELEASE_STALE_OUTBOX
~~~

Do not release a genuinely active lock; this command belongs inside the same write-stop boundary.

## Queue and outbox reconciliation

Redis restoration is intentionally prohibited. All users must sign in again, cache/metric history restarts, scheduler state is rebuilt, and only jobs reconstructed or explicitly retried from PostgreSQL may re-enter Redis.

With workers stopped:

~~~bash
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  run --rm --no-deps app php artisan queue:failed
~~~

Export each failed-job UUID and safe failure metadata into the incident record. Retry only jobs whose source business state and idempotency behavior were reviewed:

~~~bash
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  run --rm --no-deps app php artisan queue:retry FAILED_JOB_UUID
~~~

Never use queue:retry all during recovery. If a job is deliberately discarded, preserve the evidence first and use queue:forget FAILED_JOB_UUID under the incident approval.

Apply these outbox rules:

- PENDING and RETRY: eligible for a controlled delivery canary.
- Restored PROCESSING: must be RETRY with cleared locks; the restore tool performs this without incrementing attempts.
- QUARANTINED: inspect payload, immutable attempts, receiver status, and current record version in ADM-INT; use its versioned/idempotent single-event retry only after approval.
- DELIVERED: never bulk reset or clone. Reconcile by event ID and acknowledgement ID with the receiver. If compensation is required, issue a governed new business command/event rather than editing the outbox row.
- A receiver must treat the stable outbox event ID/Idempotency-Key as idempotent. If it cannot, do not resume delivery.

Process a small synchronous canary and inspect every acknowledgement before starting the worker:

~~~bash
docker compose --env-file "$ENV_FILE" -f docker-compose.production.yml \
  run --rm --no-deps app php artisan qt:outbox:process --limit=10
~~~

Re-run qt:recovery:verify --object-limit=0. Resolve or formally accept every warning, then start app, verify internal behavior, start worker and scheduler, and start gateway last. Confirm public readiness, metrics, representative authenticated reads/downloads, queue depth, outbox age, JSON logs, and alert delivery.

## Application rollback

An application-only rollback selects the previous immutable backend/gateway/recovery IMAGE_TAG and is allowed only when that release is compatible with the current migrated schema. Do not run destructive migrate:rollback against live data.

If schema compatibility is uncertain, restore the chosen snapshot and its recorded image tag into new isolated volumes, run the complete recovery verification, and switch traffic only after approval. Retain the former environment until reconciliation and the rollback window close.

Caddy state is backed up and restored through the host/platform volume facility, separately from ERP business snapshots. Validate certificate/account-state recovery in the infrastructure drill and account for ACME rate limits.

## Drill and evidence requirements

Run an isolated restore at least quarterly and after material database, storage, queue, or recovery-tool changes. The repeatable Windows/Docker Desktop drill is:

~~~powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File .\deploy\recovery\verify-recovery.ps1
~~~

It creates a unique Compose project and backup directory, applies all migrations, seeds only the disposable database, stores a database-backed object and in-flight outbox record, proves both confirmation gates, snapshots, introduces database/object/Redis residue, restores, runs the exhaustive verifier, and removes its containers, volumes, images, and backup directory. The script explicitly adds `docker-compose.recovery-drill.yml`, whose isolated MinIO container is only a local test double. That overlay is not part of `docker-compose.production.yml`, is never a UAT/production dependency, and does not replace a managed-provider restore drill.

Retain the following outside the recovered system:

- snapshot ID, manifest, checksum-verification output, and off-host copy/version identifier;
- backup start/end time and observed age versus RPO;
- restore start/end time and observed duration versus RTO;
- database migration/tag match, restored object count, and exhaustive verifier JSON;
- queue/outbox reconciliation decisions and receiver acknowledgements;
- named operators/approvers, defects, corrective actions, and next drill date.

The repository drill demonstrates the mechanism. Production scheduling, encrypted off-host custody, alerting, capacity, geographic failure coverage, and formal RPO/RTO approval remain target-platform responsibilities.
