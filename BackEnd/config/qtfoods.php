<?php

$csv = static function (mixed $value): array {
    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
};

return [
    'require_idempotency' => env('QT_REQUIRE_IDEMPOTENCY', true),
    'require_maker_checker' => env('QT_REQUIRE_MAKER_CHECKER', true),
    'simulation_isolated' => env('QT_SIMULATION_ISOLATED', true),
    'evidence_disk' => env('QT_EVIDENCE_DISK', 'evidence'),
    'private_document_disk' => env('QT_PRIVATE_DOCUMENT_DISK', 'private'),
    'evidence_retention_years' => (int) env('QT_EVIDENCE_RETENTION_YEARS', 7),
    'evidence_retention_policy' => env('QT_EVIDENCE_RETENTION_POLICY', 'UNSOLD_RETURN_7Y'),
    'evidence_max_upload_kilobytes' => (int) env('QT_EVIDENCE_MAX_UPLOAD_KB', 10240),
    'approval_work_item_due_hours' => (int) env('QT_APPROVAL_WORK_ITEM_DUE_HOURS', 24),
    'outbox' => [
        'transport' => env('QT_OUTBOX_TRANSPORT', 'log'),
        'http_endpoint' => env('QT_OUTBOX_HTTP_ENDPOINT'),
        'signing_secret' => env('QT_OUTBOX_SIGNING_SECRET'),
        'timeout_seconds' => (int) env('QT_OUTBOX_TIMEOUT_SECONDS', 10),
        'require_acknowledgement' => env('QT_OUTBOX_REQUIRE_ACKNOWLEDGEMENT', true),
        'acknowledgement_header' => env('QT_OUTBOX_ACKNOWLEDGEMENT_HEADER', 'X-Acknowledgement-ID'),
        'require_bound_acknowledgement' => env('QT_OUTBOX_REQUIRE_BOUND_ACKNOWLEDGEMENT', true),
        'acknowledged_event_header' => env('QT_OUTBOX_ACKNOWLEDGED_EVENT_HEADER', 'X-Acknowledged-Event-ID'),
        'batch_size' => (int) env('QT_OUTBOX_BATCH_SIZE', 50),
        'max_attempts' => (int) env('QT_OUTBOX_MAX_ATTEMPTS', 8),
        'base_retry_seconds' => (int) env('QT_OUTBOX_BASE_RETRY_SECONDS', 30),
        'max_retry_seconds' => (int) env('QT_OUTBOX_MAX_RETRY_SECONDS', 3600),
        'lock_timeout_seconds' => (int) env('QT_OUTBOX_LOCK_TIMEOUT_SECONDS', 300),
    ],
    'identity' => [
        'frontend_url' => env('QT_FRONTEND_URL', 'http://localhost:5173'),
        'preview_links' => env('QT_IDENTITY_PREVIEW_LINKS', false),
        'invitation_hours' => (int) env('QT_INVITATION_HOURS', 72),
        'password_reset_minutes' => (int) env('QT_PASSWORD_RESET_MINUTES', 60),
        'verification_minutes' => (int) env('QT_EMAIL_VERIFICATION_MINUTES', 60),
        'mfa_setup_minutes' => (int) env('QT_MFA_SETUP_MINUTES', 10),
        'mfa_challenge_minutes' => (int) env('QT_MFA_CHALLENGE_MINUTES', 5),
        'authentication_challenge_minutes' => (int) env('QT_AUTH_CHALLENGE_MINUTES', 10),
        'login_otp_minutes' => (int) env('QT_LOGIN_OTP_MINUTES', 5),
        'mfa_required_roles' => $csv(env('QT_MFA_REQUIRED_ROLES', 'ERP_ADMIN')),
        'session_lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
        // One-time managed-service bootstrap inputs. Remove all three values
        // immediately after the password-initialisation deployment succeeds.
        'bootstrap_admin_email' => env('QT_BOOTSTRAP_ADMIN_EMAIL'),
        'bootstrap_admin_password' => env('QT_BOOTSTRAP_ADMIN_PASSWORD'),
        'bootstrap_admin_password_confirmation' => env('QT_BOOTSTRAP_ADMIN_PASSWORD_CONFIRMATION'),
    ],

    'screen_areas' => [
        'foundation',
        'master_data',
        'procurement',
        'inventory',
        'manufacturing',
        'sales',
        'dispatch',
        'finance',
        'support',
        'scale',
    ],
];
