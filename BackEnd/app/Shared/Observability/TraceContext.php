<?php

namespace App\Shared\Observability;

final class TraceContext
{
    public static function fromHeader(?string $header): array
    {
        $header = strtolower(trim((string) $header));
        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', $header, $matches)
            && $matches[1] !== str_repeat('0', 32)
            && $matches[2] !== str_repeat('0', 16)) {
            $traceId = $matches[1];
            $parentSpanId = $matches[2];
            $flags = $matches[3];
        } else {
            $traceId = self::identifier(16);
            $parentSpanId = null;
            $flags = '01';
        }

        $spanId = self::identifier(8);

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'trace_flags' => $flags,
            'traceparent' => "00-{$traceId}-{$spanId}-{$flags}",
        ];
    }

    public static function child(?string $traceId, ?string $parentSpanId = null): array
    {
        if (! is_string($traceId)
            || preg_match('/^[0-9a-f]{32}$/', $traceId) !== 1
            || $traceId === str_repeat('0', 32)) {
            $traceId = self::identifier(16);
        }

        $spanId = self::identifier(8);

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'trace_flags' => '01',
            'traceparent' => "00-{$traceId}-{$spanId}-01",
        ];
    }

    private static function identifier(int $bytes): string
    {
        do {
            $identifier = bin2hex(random_bytes($bytes));
        } while ($identifier === str_repeat('0', $bytes * 2));

        return $identifier;
    }
}
