<?php

namespace App\Http\Middleware;

use App\Shared\Observability\HttpMetricStore;
use App\Shared\Observability\RequestContext;
use App\Shared\Observability\TraceContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CaptureRequestObservability
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly HttpMetricStore $metrics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $requestId = $this->uuidHeader($request, (string) config('observability.request_id_header', 'X-Request-ID'));
        $correlationId = $this->uuidHeader($request, (string) config('observability.correlation_id_header', 'X-Correlation-ID'), $requestId);
        $trace = TraceContext::fromHeader($request->header((string) config('observability.traceparent_header', 'traceparent')));

        $request->headers->set('X-Request-ID', $requestId);
        $request->headers->set('X-Correlation-ID', $correlationId);
        foreach ([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'trace_id' => $trace['trace_id'],
            'span_id' => $trace['span_id'],
            'parent_span_id' => $trace['parent_span_id'],
            'traceparent' => $trace['traceparent'],
        ] as $key => $value) {
            $request->attributes->set('observability.'.$key, $value);
        }
        $this->context->start($requestId, $correlationId, $trace['trace_id'], $trace['span_id']);

        $sharedContext = [
            'service' => (string) config('observability.service', 'qt-foods-erp-crm'),
            'environment' => app()->environment(),
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'trace_id' => $trace['trace_id'],
            'span_id' => $trace['span_id'],
        ];
        Log::shareContext($sharedContext);

        try {
            $response = $next($request);
            $this->complete($request, $response->getStatusCode(), $startedAt);
            $this->decorate($response, $requestId, $correlationId, $trace['traceparent']);
            $this->clearLogContext(array_keys($sharedContext));

            return $response;
        } catch (Throwable $exception) {
            $this->complete($request, $this->exceptionStatus($exception), $startedAt, $exception);
            $request->attributes->set('observability.log_context_keys', array_keys($sharedContext));

            throw $exception;
        } finally {
            $this->context->clear();
        }
    }

    private function complete(
        Request $request,
        int $status,
        int $startedAt,
        ?Throwable $exception = null,
    ): void {
        $duration = max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
        $route = $request->route();
        $routeTemplate = is_object($route) && method_exists($route, 'uri') ? $route->uri() : $request->path();
        $ignored = in_array($request->path(), (array) config('observability.ignored_http_paths', []), true);
        if (! $ignored) {
            try {
                $this->metrics->record($request->method(), $status, $duration);
            } catch (Throwable $metricException) {
                Log::warning('http_metrics_record_failed', [
                    'event' => 'http_metrics_record_failed',
                    'error_type' => class_basename($metricException),
                ]);
            }
        }

        if ($ignored && $status < 400) {
            return;
        }

        $erpContext = $request->attributes->get('erp.context');
        $logContext = [
            'event' => 'http_request_completed',
            'http_method' => $request->method(),
            'http_route' => '/'.ltrim((string) $routeTemplate, '/'),
            'http_status' => $status,
            'http_status_class' => intdiv(max(100, $status), 100).'xx',
            'duration_ms' => $duration,
            'slow_request' => $duration >= max(1, (int) config('observability.slow_request_milliseconds', 1000)),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'company_id' => is_array($erpContext) ? ($erpContext['company_id'] ?? null) : null,
            'plant_id' => is_array($erpContext) ? ($erpContext['plant_id'] ?? null) : null,
        ];
        if ($exception !== null) {
            $logContext['error_type'] = class_basename($exception);
        }

        if ($status >= 500) {
            Log::error('http_request_completed', $logContext);
        } elseif ($status >= 400 || $logContext['slow_request']) {
            Log::warning('http_request_completed', $logContext);
        } else {
            Log::info('http_request_completed', $logContext);
        }
    }

    private function decorate(Response $response, string $requestId, string $correlationId, string $traceparent): void
    {
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('traceparent', $traceparent);
        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            $payload = $response->getData(true);
            if (is_array($payload['error'] ?? null)) {
                $payload['error']['request_id'] ??= $requestId;
                $response->setData($payload);
            }
        }
    }

    private function uuidHeader(Request $request, string $header, ?string $fallback = null): string
    {
        $value = trim((string) $request->header($header));

        return $value !== '' && Str::isUuid($value) ? strtolower($value) : ($fallback ?? (string) Str::uuid());
    }

    private function exceptionStatus(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof ValidationException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof ModelNotFoundException => 404,
            default => 500,
        };
    }

    private function clearLogContext(array $keys): void
    {
        Log::withoutContext($keys);
        Log::flushSharedContext();
    }
}
