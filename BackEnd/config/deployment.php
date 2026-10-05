<?php

$environment = (string) env('APP_ENV', 'production');
$production = $environment === 'production';
$csv = static function (mixed $value): array {
    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
};
$configuredHosts = env('QT_TRUSTED_HOSTS');
$appHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
$trustedHosts = $csv($configuredHosts ?? ($production ? '' : ($appHost ?: 'localhost')));
$configuredOrigins = env('QT_CORS_ALLOWED_ORIGINS');

return [
    'enforce_https' => (bool) env('QT_ENFORCE_HTTPS', $production),
    'hsts_max_age' => (int) env('QT_HSTS_MAX_AGE', 31536000),
    'trusted_hosts' => $trustedHosts,
    'trusted_host_patterns' => array_map(
        static fn (string $host): string => '^'.preg_quote($host, '/').'$',
        $trustedHosts,
    ),
    'trusted_hosts_explicit' => is_string($configuredHosts) && trim($configuredHosts) !== '',
    'trusted_proxies' => env('QT_TRUSTED_PROXIES'),
    'cors_allowed_origins' => $csv(
        $configuredOrigins ?? ($production ? '' : env('QT_FRONTEND_URL', 'http://localhost:5173')),
    ),
    'cors_origins_explicit' => $configuredOrigins !== null,
    'allow_demo_seeders' => (bool) env('QT_ALLOW_DEMO_SEEDERS', ! $production),
    'allow_demo_login' => (bool) env('QT_ALLOW_DEMO_LOGIN', ! $production),
    'log_level' => (string) env('LOG_LEVEL', $production ? 'warning' : 'debug'),
];
