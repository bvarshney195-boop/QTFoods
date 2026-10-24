<?php

namespace App\Shared\Idempotency;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IdempotencyService
{
    public function begin(string $namespace, string $key, array $payload): ?array
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $existing = DB::table('idempotency_keys')
            ->where('namespace', $namespace)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if ($existing->payload_hash !== $hash) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['The same key was reused with different content.'],
                ]);
            }

            return $existing->result_json
                ? json_decode($existing->result_json, true, 512, JSON_THROW_ON_ERROR)
                : null;
        }

        DB::table('idempotency_keys')->insert([
            'namespace' => $namespace,
            'key' => $key,
            'payload_hash' => $hash,
            'status' => 'IN_PROGRESS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return null;
    }

    public function complete(string $namespace, string $key, array $result): void
    {
        DB::table('idempotency_keys')
            ->where('namespace', $namespace)
            ->where('key', $key)
            ->update([
                'status' => 'COMPLETED',
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
}
