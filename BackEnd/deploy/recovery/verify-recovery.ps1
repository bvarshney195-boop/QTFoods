param()

$ErrorActionPreference = 'Stop'

$backendRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$composeFile = Join-Path $backendRoot 'docker-compose.production.yml'
$drillComposeFile = Join-Path $PSScriptRoot 'docker-compose.recovery-drill.yml'
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 8)
$project = "qtfoods-recovery-drill-$suffix"
$snapshotId = "integration-$suffix"
$backupBase = [IO.Path]::GetFullPath((Join-Path $backendRoot 'backups\recovery-drills'))
$backupPath = [IO.Path]::GetFullPath((Join-Path $backupBase $suffix))
$documentId = '10000000-0000-4000-8000-000000000001'
$outboxId = '20000000-0000-4000-8000-000000000001'
$objectKey = 'finance-archive/recovery-drill/authoritative.txt'
$logicalObjectPath = 'recovery-drill/authoritative.txt'
$originalObject = 'authoritative-before-backup'
$changedObject = 'corrupt-after-backup'
$extraObjectKey = 'finance-archive/recovery-drill/post-backup-extra.txt'

function Invoke-Docker {
    param([Parameter(Mandatory)][string[]] $Arguments)

    & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker command failed with exit code $LASTEXITCODE."
    }
}

function Invoke-Compose {
    param([Parameter(Mandatory)][string[]] $Arguments)

    Invoke-Docker (@('compose', '--project-name', $project, '--file', $composeFile, '--file', $drillComposeFile, '--profile', 'recovery') + $Arguments)
}

function Invoke-ComposeCapture {
    param([Parameter(Mandatory)][string[]] $Arguments)

    $output = & docker @('compose', '--project-name', $project, '--file', $composeFile, '--file', $drillComposeFile, '--profile', 'recovery') @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose command failed with exit code $LASTEXITCODE."
    }

    return (($output | Out-String).Trim())
}

function Invoke-ComposeExpectedFailure {
    param([Parameter(Mandatory)][string[]] $Arguments)

    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & docker @('compose', '--project-name', $project, '--file', $composeFile, '--file', $drillComposeFile, '--profile', 'recovery') @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }

    return [pscustomobject]@{
        ExitCode = $exitCode
        Output = (($output | Out-String).Trim())
    }
}

function Invoke-Postgres {
    param([Parameter(Mandatory)][string] $Sql)

    Invoke-Compose @(
        'exec', '-T', 'postgres',
        'psql', '--set', 'ON_ERROR_STOP=1', '--username', $env:DB_USERNAME, '--dbname', $env:DB_DATABASE,
        '--command', $Sql
    )
}

function Get-PostgresScalar {
    param([Parameter(Mandatory)][string] $Sql)

    return Invoke-ComposeCapture @(
        'exec', '-T', 'postgres',
        'psql', '--set', 'ON_ERROR_STOP=1', '--no-align', '--tuples-only',
        '--username', $env:DB_USERNAME, '--dbname', $env:DB_DATABASE,
        '--command', $Sql
    )
}

function Assert-Equal {
    param(
        [Parameter(Mandatory)][string] $Expected,
        [AllowEmptyString()][string] $Actual,
        [Parameter(Mandatory)][string] $Label
    )

    if ($Actual.Trim() -ne $Expected) {
        throw "$Label mismatch. Expected '$Expected', received '$Actual'."
    }
}

function Get-Sha256 {
    param([Parameter(Mandatory)][string] $Value)

    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes($Value)
        return ([BitConverter]::ToString($algorithm.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $algorithm.Dispose()
    }
}

function Put-Object {
    param(
        [Parameter(Mandatory)][string] $Key,
        [Parameter(Mandatory)][string] $Contents
    )

    $command = 'mc alias set drill "$AWS_ENDPOINT" "$AWS_ACCESS_KEY_ID" "$AWS_SECRET_ACCESS_KEY" --api S3v4 >/dev/null && printf %s "$QT_DRILL_CONTENT" | mc pipe "drill/$AWS_BUCKET/$QT_DRILL_KEY" >/dev/null'
    Invoke-Compose @(
        'run', '--rm', '--no-deps', '--entrypoint', 'sh',
        '-e', "QT_DRILL_KEY=$Key", '-e', "QT_DRILL_CONTENT=$Contents",
        'recovery', '-c', $command
    )
}

function Get-Object {
    param([Parameter(Mandatory)][string] $Key)

    $command = 'mc alias set drill "$AWS_ENDPOINT" "$AWS_ACCESS_KEY_ID" "$AWS_SECRET_ACCESS_KEY" --api S3v4 >/dev/null && mc cat "drill/$AWS_BUCKET/$QT_DRILL_KEY"'
    return Invoke-ComposeCapture @(
        'run', '--rm', '--no-deps', '--entrypoint', 'sh',
        '-e', "QT_DRILL_KEY=$Key",
        'recovery', '-c', $command
    )
}

$cleanupErrors = [Collections.Generic.List[string]]::new()
$drillSucceeded = $false

try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Docker is required.'
    }

    New-Item -ItemType Directory -Force -Path $backupPath | Out-Null
    $expectedPrefix = $backupBase.TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
    if (-not $backupPath.StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The generated backup path escaped the isolated recovery-drill directory.'
    }

    $keyBytes = [Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes("recovery-drill-$suffix"))
    $env:APP_KEY = 'base64:' + [Convert]::ToBase64String($keyBytes)
    $env:APP_HOST = 'api.recovery-drill.invalid'
    $env:ACME_EMAIL = 'platform@recovery-drill.invalid'
    $env:APP_TIMEZONE = 'UTC'
    $env:DB_DATABASE = 'qtfoods_recovery_drill'
    $env:DB_USERNAME = 'qtfoods_recovery'
    $env:DB_PASSWORD = 'D6e9J4m2C8r5V7x1'
    $env:REDIS_PASSWORD = 'R8w2K5z9N4f7T1q6'
    $env:MINIO_ROOT_USER = 'recoveryroot'
    $env:MINIO_ROOT_PASSWORD = 'M4t8R2x7K9q5C1v6'
    $env:AWS_ACCESS_KEY_ID = 'RECOVERYACCESS01'
    $env:AWS_SECRET_ACCESS_KEY = 'v7Z2m9Q4n8K5r1T6x3C0p2L8s4B6d9F1'
    $env:AWS_BUCKET = 'qtfoods-recovery-drill'
    $env:AWS_DEFAULT_REGION = 'us-east-1'
    $env:AWS_ENDPOINT = 'http://minio:9000'
    $env:AWS_USE_PATH_STYLE_ENDPOINT = 'true'
    $env:QT_FRONTEND_URL = 'https://erp.recovery-drill.invalid'
    $env:QT_CORS_ALLOWED_ORIGINS = 'https://erp.recovery-drill.invalid'
    $env:MAIL_MAILER = 'smtp'
    $env:MAIL_SCHEME = 'smtp'
    $env:MAIL_HOST = 'smtp.recovery-drill.invalid'
    $env:MAIL_USERNAME = 'recovery-sender'
    $env:MAIL_PASSWORD = 'S3cureMail7K9q5C1'
    $env:MAIL_FROM_ADDRESS = 'no-reply@recovery-drill.invalid'
    $env:QT_METRICS_TOKEN = 'T7m2R9x4K6p8V1c5N3q0W2z7L4b9S6d8'
    $env:QT_BACKUP_HOST_PATH = $backupPath
    $env:QT_BACKUP_RETENTION_DAYS = '35'
    $env:QT_RECOVERY_RPO_MINUTES = '1440'
    $env:QT_RECOVERY_RTO_MINUTES = '240'
    $env:QT_RECOVERY_OBJECT_LIMIT = '0'
    $env:QT_RECOVERY_WRITERS_STOPPED = 'YES'
    $env:QT_BACKUP_STORAGE_PROTECTED = 'NO'
    $env:QT_RECOVERY_CONFIRM = ''
    $env:IMAGE_TAG = "recovery-drill-$suffix"

    Write-Host "Building and starting isolated project $project..."
    Invoke-Compose @('config', '--quiet')
    Invoke-Compose @('build', 'app', 'recovery')
    Invoke-Compose @('run', '--rm', 'migrate')
    Invoke-Compose @(
        'run', '--rm', '--no-deps',
        '-e', 'APP_ENV=local', '-e', 'QT_ALLOW_DEMO_SEEDERS=true',
        'app', 'php', 'artisan', 'db:seed', '--force'
    )

    Put-Object -Key $objectKey -Contents $originalObject
    $objectHash = Get-Sha256 $originalObject
    $objectBytes = [Text.Encoding]::UTF8.GetByteCount($originalObject)
    $sql = @"
CREATE TABLE recovery_drill_marker (
    id integer PRIMARY KEY,
    marker_value text NOT NULL
);
INSERT INTO recovery_drill_marker (id, marker_value) VALUES (1, 'before-backup');
INSERT INTO partner_documents (
    id, company_id, plant_id, party_id, document_number, direction, document_type,
    title, storage_disk, storage_path, original_name, mime_type, size_bytes,
    sha256_checksum, status, record_version, created_by, available_at, created_at, updated_at
) VALUES (
    '$documentId',
    '00000000-0000-4000-8000-000000000001',
    '00000000-0000-4000-8000-000000000101',
    '00000000-0000-4000-8000-000000000501',
    'RECOVERY-DRILL-001', 'INBOUND', 'GENERAL', 'Recovery drill object',
    'private', '$logicalObjectPath', 'authoritative.txt', 'text/plain', $objectBytes,
    '$objectHash', 'AVAILABLE', 1,
    '00000000-0000-4000-8000-000000000204',
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
);
INSERT INTO outbox_events (
    id, event_type, aggregate_type, aggregate_id, business_key, payload_json,
    company_id, plant_id, status, attempts, record_version, locked_at, locked_by,
    last_attempt_at, created_at, updated_at
) VALUES (
    '$outboxId', 'recovery.drill', 'partner_document', '$documentId',
    'recovery-drill-001', '{}',
    '00000000-0000-4000-8000-000000000001',
    '00000000-0000-4000-8000-000000000101',
    'PROCESSING', 1, 1, CURRENT_TIMESTAMP, 'lost-drill-worker',
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
);
"@
    Invoke-Postgres $sql

    Write-Host 'Confirming that protected-storage attestation fails closed...'
    $denied = Invoke-ComposeExpectedFailure @(
        'run', '--rm', '--no-deps', 'recovery', 'backup', $snapshotId
    )
    if ($denied.ExitCode -eq 0 -or $denied.Output -notmatch 'QT_BACKUP_STORAGE_PROTECTED=YES') {
        throw 'Backup did not fail closed when protected-storage attestation was absent.'
    }

    $env:QT_BACKUP_STORAGE_PROTECTED = 'YES'
    $backupWatch = [Diagnostics.Stopwatch]::StartNew()
    Invoke-Compose @('run', '--rm', '--no-deps', 'recovery', 'backup', $snapshotId)
    Invoke-Compose @('run', '--rm', '--no-deps', 'recovery', 'verify', $snapshotId)
    $backupWatch.Stop()

    Write-Host 'Confirming that destructive restore requires its exact snapshot confirmation...'
    $denied = Invoke-ComposeExpectedFailure @(
        'run', '--rm', '--no-deps', 'recovery', 'restore', $snapshotId
    )
    if ($denied.ExitCode -eq 0 -or $denied.Output -notmatch "QT_RECOVERY_CONFIRM=RESTORE:$snapshotId") {
        throw 'Restore did not fail closed without exact snapshot confirmation.'
    }

    Invoke-Postgres @"
UPDATE recovery_drill_marker SET marker_value = 'after-backup' WHERE id = 1;
CREATE TABLE post_backup_residue (id integer PRIMARY KEY);
UPDATE partner_documents SET title = 'Changed after backup' WHERE id = '$documentId';
"@
    Put-Object -Key $objectKey -Contents $changedObject
    Put-Object -Key $extraObjectKey -Contents 'must-be-removed'
    Invoke-Compose @('exec', '-T', 'redis', 'sh', '-c', 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --no-auth-warning -n 0 SET recovery-drill stale >/dev/null && REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --no-auth-warning -n 1 SET recovery-drill stale >/dev/null')

    $env:QT_RECOVERY_CONFIRM = "RESTORE:$snapshotId"
    $restoreWatch = [Diagnostics.Stopwatch]::StartNew()
    Invoke-Compose @('run', '--rm', '--no-deps', 'recovery', 'restore', $snapshotId)
    $restoreWatch.Stop()

    Assert-Equal 'before-backup' (Get-PostgresScalar 'SELECT marker_value FROM recovery_drill_marker WHERE id = 1;') 'Restored database marker'
    Assert-Equal 't' (Get-PostgresScalar "SELECT to_regclass('public.post_backup_residue') IS NULL;") 'Post-backup schema removal'
    Assert-Equal 'RETRY||2' (Get-PostgresScalar "SELECT status || '|' || COALESCE(locked_by, '') || '|' || record_version FROM outbox_events WHERE id = '$outboxId';") 'Recovered outbox lock'
    Assert-Equal $originalObject (Get-Object $objectKey) 'Restored object bytes'

    $missingExtra = Invoke-ComposeExpectedFailure @(
        'run', '--rm', '--no-deps', '--entrypoint', 'sh',
        '-e', "QT_DRILL_KEY=$extraObjectKey",
        'recovery', '-c',
        'mc alias set drill "$AWS_ENDPOINT" "$AWS_ACCESS_KEY_ID" "$AWS_SECRET_ACCESS_KEY" --api S3v4 >/dev/null && mc stat "drill/$AWS_BUCKET/$QT_DRILL_KEY" >/dev/null'
    )
    if ($missingExtra.ExitCode -eq 0) {
        throw 'Object-store restore retained a post-backup object.'
    }

    Assert-Equal '0' (Invoke-ComposeCapture @('exec', '-T', 'redis', 'sh', '-c', 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --no-auth-warning -n 0 DBSIZE')) 'Redis default database invalidation'
    Assert-Equal '0' (Invoke-ComposeCapture @('exec', '-T', 'redis', 'sh', '-c', 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --no-auth-warning -n 1 DBSIZE')) 'Redis cache database invalidation'

    Invoke-Compose @('run', '--rm', '--no-deps', 'app', 'php', 'artisan', 'qt:recovery:verify', '--object-limit=0')

    $drillSucceeded = $true
    Write-Host ("Recovery drill passed: backup {0:N2}s, restore {1:N2}s, snapshot {2}." -f $backupWatch.Elapsed.TotalSeconds, $restoreWatch.Elapsed.TotalSeconds, $snapshotId)
}
finally {
    try {
        Invoke-Compose @('down', '--volumes', '--remove-orphans')
    }
    catch {
        $cleanupErrors.Add($_.Exception.Message)
    }

    foreach ($image in @("qtfoods-erp-backend:recovery-drill-$suffix", "qtfoods-erp-recovery:recovery-drill-$suffix")) {
        & docker image rm $image 2>$null | Out-Null
    }

    if (Test-Path -LiteralPath $backupPath) {
        $resolvedPath = [IO.Path]::GetFullPath($backupPath)
        $resolvedBase = [IO.Path]::GetFullPath($backupBase)
        $prefix = $resolvedBase.TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
        if ($resolvedPath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $resolvedPath -Recurse -Force
        }
        else {
            $cleanupErrors.Add('Refused to remove a backup path outside the recovery-drill base.')
        }
    }
    if ((Test-Path -LiteralPath $backupBase) -and -not (Get-ChildItem -LiteralPath $backupBase -Force | Select-Object -First 1)) {
        Remove-Item -LiteralPath $backupBase -Force
    }
    $parentBackup = Split-Path -Parent $backupBase
    if ((Test-Path -LiteralPath $parentBackup) -and -not (Get-ChildItem -LiteralPath $parentBackup -Force | Select-Object -First 1)) {
        Remove-Item -LiteralPath $parentBackup -Force
    }

    if ($cleanupErrors.Count -gt 0) {
        Write-Warning ('Cleanup warnings: ' + ($cleanupErrors -join '; '))
    }
    if (-not $drillSucceeded) {
        Write-Warning "Recovery drill $project did not complete successfully."
    }
}
