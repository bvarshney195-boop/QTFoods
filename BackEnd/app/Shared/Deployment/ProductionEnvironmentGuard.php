<?php

namespace App\Shared\Deployment;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Formatter\JsonFormatter;
use RuntimeException;

final class ProductionEnvironmentGuard
{
    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {}

    public function enforce(): void
    {
        $violations = $this->violations();
        if ($violations !== []) {
            throw new RuntimeException(
                "Unsafe production configuration:\n - ".implode("\n - ", $violations),
            );
        }
    }

    public function violations(?string $environment = null): array
    {
        if (($environment ?? $this->app->environment()) !== 'production') {
            return [];
        }

        $violations = [];
        $appUrl = (string) $this->config->get('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $trustedHosts = (array) $this->config->get('deployment.trusted_hosts', []);
        $normalisedTrustedHosts = array_map(
            static fn (mixed $host): string => strtolower((string) $host),
            $trustedHosts,
        );
        $origins = (array) $this->config->get('cors.allowed_origins', []);

        $this->reject($violations, (bool) $this->config->get('app.debug'), 'APP_DEBUG must be false.');
        $this->reject($violations, ! $this->validApplicationKey($this->config->get('app.key')), 'APP_KEY must be a unique, valid 32-byte key and not the development fallback.');
        $this->reject($violations, parse_url($appUrl, PHP_URL_SCHEME) !== 'https' || ! is_string($appHost), 'APP_URL must be an absolute HTTPS URL.');
        $this->reject($violations, $this->placeholder($appUrl), 'APP_URL must not use an example or placeholder host.');
        $this->reject($violations, ! (bool) $this->config->get('deployment.enforce_https'), 'QT_ENFORCE_HTTPS must be true.');
        $this->reject($violations, ! (bool) $this->config->get('deployment.trusted_hosts_explicit'), 'QT_TRUSTED_HOSTS must explicitly list the accepted host names.');
        $this->reject($violations, $this->unsafeHosts($trustedHosts), 'QT_TRUSTED_HOSTS must contain exact DNS host names without schemes, ports, paths, or wildcards.');
        $this->reject($violations, is_string($appHost) && ! in_array(strtolower($appHost), $normalisedTrustedHosts, true), 'QT_TRUSTED_HOSTS must include the APP_URL host.');
        $this->reject($violations, $this->unsafeProxyList($this->config->get('deployment.trusted_proxies')), 'QT_TRUSTED_PROXIES must identify the direct reverse proxy and cannot trust every address.');
        $this->reject($violations, ! (bool) $this->config->get('deployment.cors_origins_explicit'), 'QT_CORS_ALLOWED_ORIGINS must be explicitly configured, including an intentionally empty same-origin policy.');
        $this->reject($violations, $this->unsafeOrigins($origins), 'CORS origins must be exact HTTPS origins without paths, wildcards, or embedded credentials.');
        $this->reject($violations, ! (bool) $this->config->get('cors.supports_credentials'), 'Credentialed CORS support must remain enabled for the session client.');

        $this->reject($violations, ! (bool) $this->config->get('session.secure'), 'SESSION_SECURE_COOKIE must be true.');
        $this->reject($violations, ! (bool) $this->config->get('session.http_only'), 'SESSION_HTTP_ONLY must be true.');
        $this->reject($violations, ! (bool) $this->config->get('session.encrypt'), 'SESSION_ENCRYPT must be true.');
        $this->reject($violations, ! in_array($this->config->get('session.same_site'), ['lax', 'strict'], true), 'SESSION_SAME_SITE must be lax or strict.');
        $this->reject($violations, (bool) $this->config->get('deployment.allow_demo_seeders'), 'QT_ALLOW_DEMO_SEEDERS must be false.');
        $this->reject($violations, (bool) $this->config->get('qtfoods.identity.preview_links'), 'QT_IDENTITY_PREVIEW_LINKS must be false.');
        $this->reject($violations, ! (bool) $this->config->get('qtfoods.identity.enforce_privileged_mfa'), 'QT_ENFORCE_PRIVILEGED_MFA must be true.');
        $requiredMfaRoles = (array) $this->config->get('qtfoods.identity.mfa_required_roles', []);
        $this->reject($violations, ! in_array('ERP_ADMIN', $requiredMfaRoles, true), 'QT_MFA_REQUIRED_ROLES must include ERP_ADMIN.');

        $this->reject($violations, $this->config->get('database.default') !== 'pgsql', 'DB_CONNECTION must be pgsql.');
        $this->reject($violations, $this->unsafeSecret($this->config->get('database.connections.pgsql.password')), 'DB_PASSWORD must be a non-placeholder secret of at least 16 characters.');
        $this->reject($violations, $this->unsafeSecret($this->config->get('database.redis.default.password')), 'REDIS_PASSWORD must be a non-placeholder secret of at least 16 characters.');
        $this->reject($violations, ! (bool) $this->config->get('database.connections.pgsql.mask_bindings_in_exception_messages'), 'DB_MASK_BINDINGS must be true.');

        $evidenceDisk = (string) $this->config->get('qtfoods.evidence_disk', 'evidence');
        $this->reject($violations, $this->config->get("filesystems.disks.{$evidenceDisk}.driver") !== 's3', 'The production evidence disk must use the S3 driver.');
        $this->reject($violations, $this->unsafeSecret($this->config->get("filesystems.disks.{$evidenceDisk}.key"), 12), 'AWS_ACCESS_KEY_ID must be a non-placeholder credential of at least 12 characters.');
        $this->reject($violations, $this->unsafeSecret($this->config->get("filesystems.disks.{$evidenceDisk}.secret"), 24), 'AWS_SECRET_ACCESS_KEY must be a non-placeholder credential of at least 24 characters.');
        $this->reject($violations, trim((string) $this->config->get("filesystems.disks.{$evidenceDisk}.bucket")) === '', 'AWS_BUCKET must be configured.');
        $this->reject($violations, $this->unsafeObjectStorageEndpoint($this->config->get("filesystems.disks.{$evidenceDisk}.endpoint")), 'AWS_ENDPOINT must be an exact HTTPS S3-compatible endpoint.');

        $privateDocumentDisk = (string) $this->config->get('qtfoods.private_document_disk', 'private');
        $this->reject($violations, $this->config->get("filesystems.disks.{$privateDocumentDisk}.driver") !== 's3', 'The production private-document disk must use the S3 driver.');

        $this->reject($violations, $this->config->get('mail.default') !== 'smtp', 'MAIL_MAILER must use the configured SMTP delivery transport.');
        $this->reject($violations, $this->unsafeMailConfiguration(), 'SMTP must use a non-placeholder host, authenticated credentials, and required TLS.');
        $mailFrom = (string) $this->config->get('mail.from.address');
        $this->reject($violations, filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false || $this->placeholder($mailFrom) || str_ends_with(strtolower($mailFrom), '.local'), 'MAIL_FROM_ADDRESS must use a deliverable, non-placeholder production domain.');
        $this->reject($violations, $this->config->get('logging.default') !== 'stderr', 'LOG_CHANNEL must be stderr.');
        $this->reject($violations, $this->config->get('logging.channels.stderr.formatter') !== JsonFormatter::class, 'LOG_STDERR_FORMATTER must use Monolog JSON formatting.');
        $this->reject($violations, strtolower((string) $this->config->get('deployment.log_level')) !== 'info', 'LOG_LEVEL must be info so successful request and operational events are retained.');
        $this->reject($violations, $this->config->get('cache.default') !== 'redis', 'CACHE_STORE must be redis for durable low-cardinality metrics and alert state.');
        $this->reject($violations, $this->config->get('queue.default') !== 'redis', 'QUEUE_CONNECTION must be redis.');
        $this->reject($violations, $this->config->get('session.driver') !== 'redis', 'SESSION_DRIVER must be redis.');
        $this->reject($violations, $this->config->get('queue.failed.driver') !== 'database-uuids', 'QUEUE_FAILED_DRIVER must persist failed jobs with UUIDs.');

        $outboxTransport = strtolower((string) $this->config->get('qtfoods.outbox.transport'));
        $outboxEndpoint = trim((string) $this->config->get('qtfoods.outbox.http_endpoint'));
        $outboxEndpointParts = parse_url($outboxEndpoint);
        $this->reject($violations, $outboxTransport !== 'http', 'QT_OUTBOX_TRANSPORT must be http in production.');
        $this->reject($violations,
            ! is_array($outboxEndpointParts) || ($outboxEndpointParts['scheme'] ?? null) !== 'https' || empty($outboxEndpointParts['host']),
            'QT_OUTBOX_HTTP_ENDPOINT must be an absolute HTTPS receiver endpoint.');
        $this->reject($violations, $this->unsafeSecret($this->config->get('qtfoods.outbox.signing_secret'), 32), 'QT_OUTBOX_SIGNING_SECRET must be a non-placeholder secret of at least 32 characters.');
        $this->reject($violations, ! (bool) $this->config->get('qtfoods.outbox.require_acknowledgement'), 'QT_OUTBOX_REQUIRE_ACKNOWLEDGEMENT must be true.');

        $this->reject($violations, $this->unsafeSecret($this->config->get('observability.metrics.token'), 32), 'QT_METRICS_TOKEN must be a non-placeholder secret of at least 32 characters.');
        foreach (['database', 'redis', 'object_storage'] as $dependency) {
            $this->reject($violations, ! (bool) $this->config->get("observability.readiness.{$dependency}"), "Production readiness must probe {$dependency}.");
        }
        $slowRequest = (int) $this->config->get('observability.slow_request_milliseconds');
        $this->reject($violations, $slowRequest < 100 || $slowRequest > 60000, 'QT_SLOW_REQUEST_MS must be between 100 and 60000 milliseconds.');
        $this->reject($violations, $this->unsafeAlertConfiguration(), 'Operational alerts must use structured logs or a valid HTTPS endpoint with a strong signing secret.');

        $retentionDays = (int) $this->config->get('recovery.retention_days');
        $rpoMinutes = (int) $this->config->get('recovery.rpo_minutes');
        $rtoMinutes = (int) $this->config->get('recovery.rto_minutes');
        $objectLimit = (int) $this->config->get('recovery.object_verification_limit');
        $this->reject($violations, $retentionDays < 1 || $retentionDays > 3650, 'QT_BACKUP_RETENTION_DAYS must be between 1 and 3650 days.');
        $this->reject($violations, $rpoMinutes < 1 || $rpoMinutes > 10080, 'QT_RECOVERY_RPO_MINUTES must be between 1 and 10080 minutes.');
        $this->reject($violations, $rtoMinutes < 1 || $rtoMinutes > 10080, 'QT_RECOVERY_RTO_MINUTES must be between 1 and 10080 minutes.');
        $this->reject($violations, $objectLimit < 0 || $objectLimit > 1_000_000, 'QT_RECOVERY_OBJECT_LIMIT must be between 0 and 1000000; zero verifies every object.');

        return $violations;
    }

    private function reject(array &$violations, bool $condition, string $message): void
    {
        if ($condition) {
            $violations[] = $message;
        }
    }

    private function validApplicationKey(mixed $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        $decoded = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : $key;
        if (! is_string($decoded) || strlen($decoded) !== 32) {
            return false;
        }

        return ! in_array($decoded, [
            '0123456789abcdef0123456789abcdef',
            str_repeat("\0", 32),
            str_repeat('A', 32),
        ], true);
    }

    private function unsafeSecret(mixed $value, int $minimumLength = 16): bool
    {
        if (! is_string($value) || strlen($value) < $minimumLength) {
            return true;
        }

        return $this->placeholder($value);
    }

    private function placeholder(string $value): bool
    {
        $normalised = strtolower($value);

        foreach (['change-me', 'changeme', 'replace_', 'replace-', 'prototype', 'password', 'qtfoods-secret', '.example.com'] as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }

        return preg_match('/(?:^|[^a-z0-9-])example\.(?:com|org|net)(?=$|[^a-z0-9-])/', $normalised) === 1;
    }

    private function unsafeProxyList(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return true;
        }

        $proxies = array_values(array_filter(array_map('trim', explode(',', $value))));

        return $proxies === [] || array_intersect($proxies, ['*', '**', '0.0.0.0/0', '::/0']) !== [];
    }

    private function unsafeObjectStorageEndpoint(mixed $value): bool
    {
        if (! is_string($value) || trim($value) !== $value || $value === '' || $this->placeholder($value)) {
            return true;
        }

        $parts = parse_url($value);

        return ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment']);
    }

    private function unsafeHosts(array $hosts): bool
    {
        if ($hosts === []) {
            return true;
        }

        foreach ($hosts as $host) {
            if (! is_string($host)
                || $host === ''
                || $host !== trim($host)
                || str_contains($host, '*')
                || str_contains($host, '://')
                || str_contains($host, '/')
                || str_contains($host, ':')
                || $this->placeholder($host)
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                return true;
            }
        }

        return false;
    }

    private function unsafeOrigins(array $origins): bool
    {
        foreach ($origins as $origin) {
            if (! is_string($origin) || $origin === '*' || str_contains($origin, '*')) {
                return true;
            }
            $parts = parse_url($origin);
            if (! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || empty($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['path'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || $this->placeholder($origin)) {
                return true;
            }
        }

        return false;
    }

    private function unsafeMailConfiguration(): bool
    {
        $smtp = (array) $this->config->get('mail.mailers.smtp', []);
        $host = (string) ($smtp['host'] ?? '');
        $scheme = strtolower((string) ($smtp['scheme'] ?? ''));

        return $host === ''
            || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
            || $this->placeholder($host)
            || ! in_array($scheme, ['smtp', 'smtps'], true)
            || ($scheme === 'smtp' && ! (bool) ($smtp['require_tls'] ?? false))
            || $this->unsafeSecret($smtp['username'] ?? null, 4)
            || $this->unsafeSecret($smtp['password'] ?? null);
    }

    private function unsafeAlertConfiguration(): bool
    {
        $transport = strtolower((string) $this->config->get('observability.alerts.transport', ''));
        if ($transport === 'log') {
            return false;
        }
        if ($transport !== 'http') {
            return true;
        }

        $endpoint = trim((string) $this->config->get('observability.alerts.http_endpoint'));
        $parts = parse_url($endpoint);

        return ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || $this->placeholder($endpoint)
            || $this->unsafeSecret($this->config->get('observability.alerts.signing_secret'), 32);
    }
}
