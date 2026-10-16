<?php

namespace App\Modules\Manufacturing\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calculates batch material standards on the actual approved ingredient basis.
 *
 * Original-plan quantities remain immutable. Scalable and conditional lines are
 * normalised by the ratio of actual basis-component mass to planned basis mass;
 * fixed lines remain fixed; per-unit lines follow final count output once it is
 * available. Approved closed-batch effects are overlays and never rewrite the
 * underlying production postings.
 */
final class ManufacturingMaterialReconciliationService
{
    /** @return array<string, mixed> */
    public function forOrder(string $orderId, bool $includeApprovedAdjustments = true): array
    {
        $order = DB::table('production_orders')->where('id', $orderId)->first([
            'id', 'planned_basis_quantity', 'planned_quantity', 'recipe_standard_snapshot',
        ]);
        if (! $order) {
            return ['basis' => $this->emptyBasis('ORDER_NOT_FOUND'), 'materials' => []];
        }

        $materials = DB::table('production_order_materials as material')
            ->join('mrp_material_requirements as requirement', 'requirement.id', '=', 'material.mrp_material_requirement_id')
            ->where('material.production_order_id', $orderId)->orderBy('material.line_number')
            ->get(['material.*', 'requirement.recipe_component_id']);
        $snapshot = $this->snapshot($order->recipe_standard_snapshot);
        $components = collect($snapshot['components'] ?? [])->keyBy(fn (array $line): string => (string) ($line['id'] ?? ''));
        $adjustments = $includeApprovedAdjustments
            ? DB::table('production_batch_adjustment_effects')->where('production_order_id', $orderId)
                ->where('metric_code', 'MATERIAL_NET_CONSUMPTION')
                ->selectRaw('subject_id, SUM(signed_quantity) AS signed_quantity')->groupBy('subject_id')
                ->pluck('signed_quantity', 'subject_id')
            : collect();

        $actualByMaterial = $materials->mapWithKeys(function (object $material) use ($adjustments): array {
            $recorded = $this->decimal($material->net_consumed_quantity ?? $material->issued_quantity);
            $approved = $this->decimal($adjustments->get($material->id, 0));

            return [(string) $material->id => [
                'recorded' => $recorded,
                'approved_adjustment' => $approved,
                'adjusted' => bcadd($recorded, $approved, 6),
            ]];
        });

        [$actualBasis, $basisComponentCount, $basisException] = $this->actualBasis(
            $materials,
            $components,
            $actualByMaterial,
            $snapshot,
        );
        $plannedBasis = $this->decimal($order->planned_basis_quantity);
        $factor = $basisException === null && bccomp($plannedBasis, '0', 6) > 0
            ? bcdiv($actualBasis, $plannedBasis, 12)
            : '1.000000000000';
        if ($basisException === null && bccomp($plannedBasis, '0', 6) <= 0) {
            $basisException = 'ZERO_PLANNED_BASIS';
        }
        $actualGood = $this->actualGood($orderId, $includeApprovedAdjustments);

        $lines = $materials->map(function (object $material) use (
            $components,
            $actualByMaterial,
            $factor,
            $actualGood,
            $basisException,
        ): array {
            $component = $components->get((string) $material->recipe_component_id, []);
            $method = strtoupper((string) ($component['scaling_method'] ?? $material->scaling_method_snapshot ?? 'SCALABLE'));
            $original = $this->decimal($material->original_plan_quantity ?? $material->required_quantity);
            $actual = $actualByMaterial->get((string) $material->id, [
                'recorded' => $this->decimal($material->net_consumed_quantity ?? $material->issued_quantity),
                'approved_adjustment' => '0.000000',
                'adjusted' => $this->decimal($material->net_consumed_quantity ?? $material->issued_quantity),
            ]);
            $normalised = $original;

            if ($method === 'PER_UNIT' && bccomp($actualGood, '0', 6) > 0
                && isset($component['quantity_per_unit']) && $component['quantity_per_unit'] !== null) {
                $normalised = bcmul($this->decimal($component['quantity_per_unit'], 9), $actualGood, 12);
                if (! (bool) ($component['loss_included_in_standard'] ?? false)
                    && bccomp($this->decimal($component['waste_percent'] ?? 0), '0', 6) > 0) {
                    $normalised = bcmul($normalised, bcadd('1', bcdiv(
                        $this->decimal($component['waste_percent'] ?? 0),
                        '100',
                        12,
                    ), 12), 12);
                }
            } elseif ($method !== 'FIXED' && $basisException === null) {
                $normalised = bcmul($original, $factor, 12);
            }
            $normalised = $this->decimal($normalised);

            return [
                'id' => (string) $material->id,
                'recipe_component_id' => (string) $material->recipe_component_id,
                'scaling_method' => $method,
                'original_plan_quantity' => $original,
                'recorded_net_consumed_quantity' => $actual['recorded'],
                'approved_adjustment_quantity' => $actual['approved_adjustment'],
                'adjusted_net_consumed_quantity' => $actual['adjusted'],
                'normalised_standard_quantity' => $normalised,
                'original_plan_variance' => bcsub($actual['adjusted'], $original, 6),
                'efficiency_variance' => bcsub($actual['adjusted'], $normalised, 6),
            ];
        })->all();

        return [
            'basis' => [
                'planned_quantity' => $plannedBasis,
                'actual_quantity' => $actualBasis,
                'normalisation_factor' => $this->decimal($factor, 9),
                'basis_component_count' => $basisComponentCount,
                'exception_code' => $basisException,
            ],
            'actual_good_units' => $actualGood,
            'materials' => $lines,
        ];
    }

    public function persistRecorded(string $orderId): void
    {
        foreach ($this->forOrder($orderId, false)['materials'] as $line) {
            DB::table('production_order_materials')->where('id', $line['id'])->update([
                'normalised_standard_quantity' => $line['normalised_standard_quantity'],
                'original_plan_variance' => $line['original_plan_variance'],
                'efficiency_variance' => $line['efficiency_variance'],
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param Collection<int, object> $materials
     * @param Collection<string, array<string, mixed>> $components
     * @param Collection<string, array<string, string>> $actualByMaterial
     * @param array<string, mixed> $snapshot
     * @return array{string, int, ?string}
     */
    private function actualBasis(Collection $materials, Collection $components, Collection $actualByMaterial, array $snapshot): array
    {
        $actualBasis = '0.000000000000';
        $count = 0;
        foreach ($materials as $material) {
            $component = $components->get((string) $material->recipe_component_id);
            if (! $component || ! (bool) ($component['is_basis_component'] ?? false)) {
                continue;
            }
            $count++;
            $factor = $this->decimal($component['mass_conversion_factor'] ?? 0, 12);
            $recipeUom = strtoupper((string) ($snapshot['recipe']['basis_uom_code'] ?? ''));
            $componentUom = strtoupper((string) ($component['uom_code'] ?? $material->uom_code));
            if (bccomp($factor, '0', 12) <= 0 && $recipeUom !== '' && $recipeUom === $componentUom) {
                $factor = '1.000000000000';
            }
            if (bccomp($factor, '0', 12) <= 0) {
                return ['0.000000000000', $count, 'MISSING_BASIS_CONVERSION'];
            }
            $actual = $actualByMaterial->get((string) $material->id, ['adjusted' => '0.000000'])['adjusted'];
            $actualBasis = bcadd($actualBasis, bcmul($actual, $factor, 12), 12);
        }

        return $count === 0
            ? ['0.000000000000', 0, 'NO_BASIS_COMPONENT']
            : [$actualBasis, $count, null];
    }

    private function actualGood(string $orderId, bool $includeApprovedAdjustments): string
    {
        $good = $this->decimal(DB::table('production_output_events')->where('production_order_id', $orderId)
            ->where('quantity_dimension', 'COUNT')->where('event_type', 'GOOD')->sum('quantity'));
        $recovered = $this->decimal(DB::table('production_output_events')->where('production_order_id', $orderId)
            ->where('quantity_dimension', 'COUNT')->where('event_type', 'REWORK')
            ->where('rework_status', 'RECOVERED')->sum('quantity'));
        $result = bcadd($good, $recovered, 6);
        if ($includeApprovedAdjustments) {
            $result = bcadd($result, $this->decimal(DB::table('production_batch_adjustment_effects')
                ->where('production_order_id', $orderId)->where('metric_code', 'GOOD_OUTPUT')->sum('signed_quantity')), 6);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function snapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function emptyBasis(string $exception): array
    {
        return ['planned_quantity' => '0.000000', 'actual_quantity' => '0.000000',
            'normalisation_factor' => '1.000000000', 'basis_component_count' => 0,
            'exception_code' => $exception];
    }

    private function decimal(mixed $value, int $scale = 6): string
    {
        return bcround((string) ($value ?? 0), $scale, \RoundingMode::HalfAwayFromZero);
    }
}
