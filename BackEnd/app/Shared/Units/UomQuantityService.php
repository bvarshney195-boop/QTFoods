<?php

namespace App\Shared\Units;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UomQuantityService
{
    /** @var array<string, int> */
    private array $precisions = [];

    public function assertPrecision(
        mixed $quantity,
        string $uomCode,
        string $field,
        string $label = 'Quantity',
    ): string {
        $value = $this->decimal($quantity);
        $precision = $this->precision($uomCode, $field);
        $atPrecision = bcadd($value, '0', $precision);

        if (bccomp($value, $atPrecision, 6) !== 0) {
            $requirement = $precision === 0
                ? 'must use whole units'
                : "must have at most {$precision} decimal places";
            throw ValidationException::withMessages([
                $field => ["{$label} for ".strtoupper(trim($uomCode))." {$requirement}."],
            ]);
        }

        return $value;
    }

    public function roundUp(mixed $quantity, string $uomCode, string $field = 'quantity'): string
    {
        $value = $this->nonNegative($quantity, $field);
        $precision = $this->precision($uomCode, $field);
        $truncated = bcadd($value, '0', $precision);
        if (bccomp($value, $truncated, 12) > 0) {
            $increment = $precision === 0 ? '1' : '0.'.str_repeat('0', $precision - 1).'1';
            $truncated = bcadd($truncated, $increment, $precision);
        }

        return bcadd($truncated, '0', 6);
    }

    public function roundDown(mixed $quantity, string $uomCode, string $field = 'quantity'): string
    {
        $value = $this->nonNegative($quantity, $field);

        return bcadd(bcadd($value, '0', $this->precision($uomCode, $field)), '0', 6);
    }

    private function precision(string $uomCode, string $field): int
    {
        $code = strtoupper(trim($uomCode));
        if (array_key_exists($code, $this->precisions)) return $this->precisions[$code];

        $precision = DB::table('uoms')->where('code', $code)->value('precision');
        if ($precision === null) {
            throw ValidationException::withMessages([
                $field => ["Unit of measure {$code} is not configured."],
            ]);
        }

        return $this->precisions[$code] = max(0, min(6, (int) $precision));
    }

    private function nonNegative(mixed $quantity, string $field): string
    {
        $value = bcadd((string) ($quantity ?? 0), '0', 12);
        if (bccomp($value, '0', 12) < 0) {
            throw ValidationException::withMessages([$field => ['Quantity must not be negative.']]);
        }

        return $value;
    }

    private function decimal(mixed $quantity): string
    {
        return bcadd((string) ($quantity ?? 0), '0', 6);
    }
}
