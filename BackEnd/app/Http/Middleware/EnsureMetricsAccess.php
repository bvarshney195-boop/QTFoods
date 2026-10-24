<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMetricsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('observability.metrics.token');
        if ($expected === '' && ! app()->environment('production')) {
            return $next($request);
        }

        $provided = (string) $request->bearerToken();
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'METRICS_UNAUTHENTICATED',
                    'message' => 'Valid monitoring credentials are required.',
                ],
            ], 401, [
                'Cache-Control' => 'no-store',
                'WWW-Authenticate' => 'Bearer realm="qt-foods-metrics"',
            ]);
        }

        return $next($request);
    }
}
