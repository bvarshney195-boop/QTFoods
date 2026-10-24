<?php

namespace App\Shared\Units;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UomConversionService
{
    public function factor(
        string $from,
        string $to,
        ?string $companyId = null,
        ?string $catalogItemId = null,
    ): ?string {
        $from = Str::upper(trim($from));
        $to = Str::upper(trim($to));
        if ($from === $to) {
            return '1.000000000000';
        }

        if ($companyId && $catalogItemId) {
            $direct = DB::table('item_uom_conversions')->where('company_id', $companyId)
                ->where('catalog_item_id', $catalogItemId)->where('from_uom_code', $from)
                ->where('to_uom_code', $to)->first();
            if ($direct) {
                return $this->decimal($direct->multiplier);
            }

            $reverse = DB::table('item_uom_conversions')->where('company_id', $companyId)
                ->where('catalog_item_id', $catalogItemId)->where('from_uom_code', $to)
                ->where('to_uom_code', $from)->first();
            if ($reverse && bccomp((string) $reverse->multiplier, '0', 12) > 0) {
                return bcdiv('1', (string) $reverse->multiplier, 12);
            }
        }

        $units = DB::table('uoms')->whereIn('code', [$from, $to])->get([
            'code', 'dimension_code', 'base_conversion_factor',
        ])->keyBy('code');
        $source = $units->get($from);
        $destination = $units->get($to);
        if (! $source || ! $destination || ! $source->dimension_code
            || $source->dimension_code !== $destination->dimension_code
            || $source->base_conversion_factor === null || $destination->base_conversion_factor === null
            || bccomp((string) $destination->base_conversion_factor, '0', 12) <= 0) {
            return null;
        }

        return bcdiv((string) $source->base_conversion_factor, (string) $destination->base_conversion_factor, 12);
    }

    public function convert(
        mixed $quantity,
        string $from,
        string $to,
        ?string $companyId = null,
        ?string $catalogItemId = null,
    ): ?string {
        $factor = $this->factor($from, $to, $companyId, $catalogItemId);

        return $factor === null ? null : bcmul((string) $quantity, $factor, 12);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 12);
    }
}
