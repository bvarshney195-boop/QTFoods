<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSecureTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        $enforce = (bool) config('deployment.enforce_https', false);
        $healthCheck = $request->is('up') || $request->is('api/health') || $request->is('api/ready');

        if ($enforce && ! $healthCheck && ! $request->isSecure()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'HTTPS_REQUIRED',
                    'message' => 'This service accepts protected traffic only over HTTPS.',
                ],
            ], 426, ['Cache-Control' => 'no-store']);
        }

        $response = $next($request);
        if ($enforce && $request->isSecure()) {
            $maxAge = max(0, (int) config('deployment.hsts_max_age', 31536000));
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
        }

        return $response;
    }
}
