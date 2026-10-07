<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Middleware\CaptureRequestObservability;
use App\Http\Middleware\EnsureContextSelected;
use App\Http\Middleware\EnsureActiveDeviceSession;
use App\Http\Middleware\EnsureMetricsAccess;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePermanentPassword;
use App\Http\Middleware\EnsureScreenAccess;
use App\Http\Middleware\EnforceSecureTransport;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(static fn (Request $request): ?string => null);
        $middleware->trustHosts(
            at: static fn (): array => config('deployment.trusted_host_patterns', []),
            subdomains: false,
        );
        $middleware->trustProxies(at: env('QT_TRUSTED_PROXIES'));
        $middleware->prepend(CaptureRequestObservability::class);
        $middleware->append(EnforceSecureTransport::class);
        $middleware->alias([
            'erp.device' => EnsureActiveDeviceSession::class,
            'erp.context' => EnsureContextSelected::class,
            'erp.permission' => EnsurePermission::class,
            'erp.password' => EnsurePermanentPassword::class,
            'erp.screen' => EnsureScreenAccess::class,
            'observability.metrics' => EnsureMetricsAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->respond(function (Response $response) {
            $request = request();
            $requestId = $request->attributes->get('observability.request_id');
            $correlationId = $request->attributes->get('observability.correlation_id');
            $traceparent = $request->attributes->get('observability.traceparent');

            if ($request->is('api/*') && $response instanceof JsonResponse && $response->getStatusCode() >= 400) {
                $payload = $response->getData(true);
                if (! isset($payload['error'])) {
                    $status = $response->getStatusCode();
                    $codes = [
                        401 => 'UNAUTHENTICATED',
                        403 => 'FORBIDDEN',
                        404 => 'NOT_FOUND',
                        409 => 'CONFLICT',
                        422 => 'VALIDATION_FAILED',
                        429 => 'TOO_MANY_REQUESTS',
                    ];
                    $message = $status >= 500
                        ? 'An unexpected server error occurred.'
                        : ($payload['message'] ?? Response::$statusTexts[$status] ?? 'Request failed.');
                    $error = ['code' => $codes[$status] ?? 'HTTP_'.$status, 'message' => $message];
                    if (! empty($payload['errors'])) {
                        $error['fields'] = $payload['errors'];
                    }
                    $payload = ['error' => $error];
                }
                if (is_string($requestId) && is_array($payload['error'] ?? null)) {
                    $payload['error']['request_id'] ??= $requestId;
                }
                $response->setData($payload);
            }

            foreach ([
                'X-Request-ID' => $requestId,
                'X-Correlation-ID' => $correlationId,
                'traceparent' => $traceparent,
            ] as $header => $value) {
                if (is_string($value) && $value !== '') {
                    $response->headers->set($header, $value);
                }
            }
            $keys = $request->attributes->get('observability.log_context_keys');
            if (is_array($keys)) {
                Log::withoutContext($keys);
                Log::flushSharedContext();
            }

            return $response;
        });
    })
    ->create();
