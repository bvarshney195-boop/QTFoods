<?php

namespace App\Shared\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DependencyHealth
{
    public function check(): array
    {
        $checks = [];
        if ((bool) config('observability.readiness.database', true)) {
            $checks['database'] = $this->measure(static function (): void {
                DB::select('SELECT 1');
            });
        }
        if ((bool) config('observability.readiness.redis', false)) {
            $checks['redis'] = $this->measure(static function (): void {
                Redis::connection()->command('ping');
            });
        }
        if ((bool) config('observability.readiness.object_storage', false)) {
            $checks['object_storage'] = $this->measure(static function (): void {
                $disk = (string) config('qtfoods.evidence_disk', 'evidence');
                Storage::disk($disk)->exists((string) config(
                    'observability.readiness.object_storage_probe',
                    '.qtfoods-readiness-probe',
                ));
            });
        }

        $ready = collect($checks)->every(
            static fn (array $check): bool => $check['status'] === 'up',
        );

        return [
            'status' => $ready ? 'ready' : 'unavailable',
            'service' => (string) config('observability.service', 'qt-foods-erp-crm'),
            'checked_at' => now()->utc()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    private function measure(callable $probe): array
    {
        $startedAt = hrtime(true);
        try {
            $probe();

            return [
                'status' => 'up',
                'latency_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'down',
                'latency_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
                'error_type' => class_basename($exception),
            ];
        }
    }
}
