<?php

namespace App\Http\Controllers;

use App\Shared\Observability\DependencyHealth;
use App\Shared\Observability\PrometheusExporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ObservabilityController
{
    public function readiness(DependencyHealth $health): JsonResponse
    {
        $result = $health->check();

        return response()->json(
            $result,
            $result['status'] === 'ready' ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }

    public function metrics(PrometheusExporter $exporter): Response
    {
        return response($exporter->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
