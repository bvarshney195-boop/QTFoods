<?php

namespace Tests\Feature;

use App\Shared\Deployment\ProductionEnvironmentGuard;
use Database\Seeders\DatabaseSeeder;
use LogicException;
use Tests\TestCase;

final class ProductionConfigurationTest extends TestCase
{
    public function test_development_defaults_fail_the_production_guard(): void
    {
        $violations = app(ProductionEnvironmentGuard::class)->violations('production');

        self::assertContains('APP_DEBUG must be false.', $violations);
        self::assertContains('APP_KEY must be a unique, valid 32-byte key and not the development fallback.', $violations);
        self::assertContains('APP_URL must be an absolute HTTPS URL.', $violations);
        self::assertContains('QT_ALLOW_DEMO_SEEDERS must be false.', $violations);
        self::assertContains('QT_ALLOW_DEMO_AUTHENTICATION must be false.', $violations);
        self::assertContains('QT_OUTBOX_TRANSPORT must be http in production.', $violations);
        self::assertContains('MAIL_MAILER must use the configured SMTP delivery transport.', $violations);
    }

    public function test_hardened_configuration_passes_the_production_guard_and_command(): void
    {
        $this->configureSafeProductionEnvironment();

        self::assertSame([], app(ProductionEnvironmentGuard::class)->violations('production'));
        $this->artisan('qt:deployment:verify')
            ->expectsOutput('Production configuration passed all fail-closed checks.')
            ->assertExitCode(0);
    }

    public function test_host_origin_and_mail_values_must_be_exact_and_non_placeholder(): void
    {
        $this->configureSafeProductionEnvironment();
        config()->set([
            'deployment.trusted_hosts' => ['api.erp.secure.test', '*.secure.test'],
            'cors.allowed_origins' => ['https://erp.secure.test/application'],
            'mail.from.address' => 'no-reply@example.com',
        ]);

        $violations = app(ProductionEnvironmentGuard::class)->violations('production');

        self::assertContains('QT_TRUSTED_HOSTS must contain exact DNS host names without schemes, ports, paths, or wildcards.', $violations);
        self::assertContains('CORS origins must be exact HTTPS origins without paths, wildcards, or embedded credentials.', $violations);
        self::assertContains('MAIL_FROM_ADDRESS must use a deliverable, non-placeholder production domain.', $violations);
    }

    public function test_https_is_required_for_application_routes_but_not_health_checks(): void
    {
        config()->set([
            'deployment.enforce_https' => true,
            'deployment.hsts_max_age' => 31536000,
        ]);

        $insecure = $this->getJson('/api/v1/auth/csrf')
            ->assertStatus(426)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson([
                'error' => [
                    'code' => 'HTTPS_REQUIRED',
                    'message' => 'This service accepts protected traffic only over HTTPS.',
                ],
            ]);
        self::assertTrue(\Illuminate\Support\Str::isUuid((string) $insecure->json('error.request_id')));
        $insecure->assertHeader('X-Request-ID', $insecure->json('error.request_id'));

        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://api.erp.secure.test/api/v1/auth/csrf')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
    }

    public function test_cors_never_authorizes_an_untrusted_origin(): void
    {
        config()->set([
            'deployment.enforce_https' => false,
            'cors.allowed_origins' => ['https://erp.secure.test'],
        ]);

        $this->call('OPTIONS', '/api/v1/auth/csrf', server: [
            'HTTP_ORIGIN' => 'https://erp.secure.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://erp.secure.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $this->call('OPTIONS', '/api/v1/auth/csrf', server: [
            'HTTP_ORIGIN' => 'https://untrusted.secure.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])
            ->assertNoContent()
            // A single-origin policy emits a fixed value; its mismatch makes the browser deny this origin.
            ->assertHeader('Access-Control-Allow-Origin', 'https://erp.secure.test');
    }

    public function test_demo_database_seeder_is_disabled_by_production_policy(): void
    {
        config()->set('deployment.allow_demo_seeders', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DatabaseSeeder contains development/UAT fixtures and is disabled in this environment.');

        (new DatabaseSeeder())->run();
    }

    public function test_observability_configuration_fails_closed_and_accepts_signed_https_alerts(): void
    {
        $this->configureSafeProductionEnvironment();
        config()->set([
            'logging.default' => 'stack',
            'logging.channels.stderr.formatter' => null,
            'deployment.log_level' => 'warning',
            'cache.default' => 'file',
            'queue.default' => 'sync',
            'queue.failed.driver' => 'null',
            'session.driver' => 'array',
            'observability.metrics.token' => 'short',
            'observability.readiness.redis' => false,
            'observability.slow_request_milliseconds' => 99,
            'observability.alerts.transport' => 'http',
            'observability.alerts.http_endpoint' => 'http://alerts.example.com/notify',
            'observability.alerts.signing_secret' => 'short',
        ]);

        $violations = app(ProductionEnvironmentGuard::class)->violations('production');
        self::assertContains('LOG_CHANNEL must be stderr.', $violations);
        self::assertContains('LOG_STDERR_FORMATTER must use Monolog JSON formatting.', $violations);
        self::assertContains('LOG_LEVEL must be info so successful request and operational events are retained.', $violations);
        self::assertContains('CACHE_STORE must be redis for durable low-cardinality metrics and alert state.', $violations);
        self::assertContains('QUEUE_CONNECTION must be redis.', $violations);
        self::assertContains('SESSION_DRIVER must be redis.', $violations);
        self::assertContains('QUEUE_FAILED_DRIVER must persist failed jobs with UUIDs.', $violations);
        self::assertContains('QT_METRICS_TOKEN must be a non-placeholder secret of at least 32 characters.', $violations);
        self::assertContains('Production readiness must probe redis.', $violations);
        self::assertContains('QT_SLOW_REQUEST_MS must be between 100 and 60000 milliseconds.', $violations);
        self::assertContains('Operational alerts must use structured logs or a valid HTTPS endpoint with a strong signing secret.', $violations);

        $this->configureSafeProductionEnvironment();
        config()->set([
            'observability.alerts.transport' => 'http',
            'observability.alerts.http_endpoint' => 'https://alerts.erp.secure.test/notify',
            'observability.alerts.signing_secret' => 'S8n4Q1v7M2x9K5p3R6z0L4c8W1b7D9f2',
        ]);
        self::assertSame([], app(ProductionEnvironmentGuard::class)->violations('production'));
    }

    public function test_outbox_acknowledgements_must_be_event_bound_and_backlog_age_must_be_monitored(): void
    {
        $this->configureSafeProductionEnvironment();
        config()->set([
            'qtfoods.outbox.require_bound_acknowledgement' => false,
            'observability.monitoring.thresholds.outbox_oldest_due_seconds' => 0,
        ]);

        $violations = app(ProductionEnvironmentGuard::class)->violations('production');
        self::assertContains('QT_OUTBOX_REQUIRE_BOUND_ACKNOWLEDGEMENT must be true.', $violations);
        self::assertContains('QT_ALERT_OUTBOX_OLDEST_DUE_SECONDS must be between 1 and 86400.', $violations);
    }

    public function test_production_persistence_timezone_must_be_utc(): void
    {
        $this->configureSafeProductionEnvironment();
        config()->set('app.timezone', 'Asia/Kolkata');

        self::assertContains(
            'APP_TIMEZONE must be UTC; plant-local display must use the explicit plant timezone.',
            app(ProductionEnvironmentGuard::class)->violations('production'),
        );
    }

    public function test_recovery_policy_requires_bounded_retention_and_objectives(): void
    {
        $this->configureSafeProductionEnvironment();
        config()->set([
            'recovery.retention_days' => 0,
            'recovery.rpo_minutes' => 0,
            'recovery.rto_minutes' => 10081,
            'recovery.object_verification_limit' => 1000001,
        ]);

        $violations = app(ProductionEnvironmentGuard::class)->violations('production');
        self::assertContains('QT_BACKUP_RETENTION_DAYS must be between 1 and 3650 days.', $violations);
        self::assertContains('QT_RECOVERY_RPO_MINUTES must be between 1 and 10080 minutes.', $violations);
        self::assertContains('QT_RECOVERY_RTO_MINUTES must be between 1 and 10080 minutes.', $violations);
        self::assertContains('QT_RECOVERY_OBJECT_LIMIT must be between 0 and 1000000; zero verifies every object.', $violations);
    }

    public function test_production_private_storage_requires_s3_and_an_exact_https_endpoint(): void
    {
        $this->configureSafeProductionEnvironment();
        config()->set([
            'filesystems.disks.evidence.endpoint' => 'http://object-storage.internal',
            'qtfoods.private_document_disk' => 'evidence_test',
            'filesystems.disks.evidence_test.driver' => 'local',
        ]);

        $violations = app(ProductionEnvironmentGuard::class)->violations('production');

        self::assertContains('AWS_ENDPOINT must be an exact HTTPS S3-compatible endpoint.', $violations);
        self::assertContains('The production private-document disk must use the S3 driver.', $violations);
    }

    private function configureSafeProductionEnvironment(): void
    {
        config()->set([
            'app.debug' => false,
            'app.timezone' => 'UTC',
            'app.key' => 'base64:'.base64_encode(hash('sha256', 'production-configuration-test', true)),
            'app.url' => 'https://api.erp.secure.test',
            'deployment.enforce_https' => true,
            'deployment.trusted_hosts_explicit' => true,
            'deployment.trusted_hosts' => ['api.erp.secure.test'],
            'deployment.trusted_proxies' => 'REMOTE_ADDR',
            'deployment.cors_origins_explicit' => true,
            'deployment.allow_demo_seeders' => false,
            'deployment.allow_demo_authentication' => false,
            'deployment.log_level' => 'info',
            'logging.default' => 'stderr',
            'logging.channels.stderr.formatter' => \Monolog\Formatter\JsonFormatter::class,
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'queue.failed.driver' => 'database-uuids',
            'cors.allowed_origins' => ['https://erp.secure.test'],
            'cors.supports_credentials' => true,
            'session.secure' => true,
            'session.http_only' => true,
            'session.encrypt' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'redis',
            'qtfoods.identity.preview_links' => false,
            'qtfoods.identity.mfa_required_roles' => ['ERP_ADMIN'],
            'qtfoods.outbox.transport' => 'http',
            'qtfoods.outbox.require_acknowledgement' => true,
            'qtfoods.outbox.http_endpoint' => 'https://events.erp.secure.test/acknowledged-events',
            'qtfoods.outbox.signing_secret' => 'Q7m2R9x4K6p8V1c5N3q0W2z7L4b9S6d8',
            'database.default' => 'pgsql',
            'database.connections.pgsql.password' => 'D6e9J4m2C8r5V7x1',
            'database.connections.pgsql.mask_bindings_in_exception_messages' => true,
            'database.redis.default.password' => 'R8w2K5z9N4f7T1q6',
            'qtfoods.evidence_disk' => 'evidence',
            'qtfoods.private_document_disk' => 'private',
            'filesystems.disks.evidence.driver' => 's3',
            'filesystems.disks.evidence.key' => 'AKIA7X9C4N2Q8Z5M',
            'filesystems.disks.evidence.secret' => 'v7Z2m9Q4n8K5r1T6x3C0p2L8s4B6d9F1',
            'filesystems.disks.evidence.bucket' => 'qtfoods-evidence-production',
            'filesystems.disks.evidence.endpoint' => 'https://objects.erp.secure.test',
            'filesystems.disks.private.driver' => 's3',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.secure.test',
            'mail.mailers.smtp.username' => 'erp-sender',
            'mail.mailers.smtp.password' => 'M4t8R2x7K9q5C1v6',
            'mail.mailers.smtp.require_tls' => true,
            'mail.from.address' => 'no-reply@erp.secure.test',
            'observability.metrics.token' => 'T7m2R9x4K6p8V1c5N3q0W2z7L4b9S6d8',
            'observability.readiness.database' => true,
            'observability.readiness.redis' => true,
            'observability.readiness.object_storage' => true,
            'observability.slow_request_milliseconds' => 1000,
            'observability.alerts.transport' => 'log',
        ]);
    }
}
