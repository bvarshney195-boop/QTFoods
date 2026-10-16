<?php

namespace App\Modules\Manufacturing\Application;

use Illuminate\Validation\ValidationException;

/**
 * Pure deterministic recipe and yield arithmetic.
 *
 * Callers must normalise mass quantities into the recipe basis UOM before
 * invoking this class. Database and HTTP concerns deliberately stay outside
 * this calculator so the same rules can be reused by preview, MRP, execution,
 * costing and tests.
 */
final class RecipeYieldCalculator
{
    public const SCALE = 6;
    public const INTERNAL_SCALE = 12;
    public const MODELS = ['LEGACY_YIELD', 'WEIGHT_PIECE', 'UNIT_CONVERSION', 'PERCENT_YIELD'];
    public const SCALING_METHODS = ['SCALABLE', 'FIXED', 'PER_UNIT', 'CONDITIONAL'];
    public const ROUNDING_MODES = ['DOWN', 'UP', 'HALF_UP', 'NONE'];

    /**
     * @param array<string, mixed> $standard
     * @param array<int, array<string, mixed>> $components
     * @param array<int, string> $activeConditions
     * @param array<int, array{quantity:mixed, contributes_to_input_mass?:bool}> $measuredAdditions
     * @return array<string, mixed>
     */
    public function calculate(
        array $standard,
        array $components,
        mixed $batchBasisQuantity,
        mixed $plannedUnits = null,
        array $activeConditions = [],
        array $measuredAdditions = [],
    ): array {
        $recipeBasis = $this->positive($standard['basis_quantity'] ?? null, 'basis_quantity');
        $batchBasis = $this->nonNegative($batchBasisQuantity, 'batch_basis_quantity');
        $planned = $plannedUnits === null ? null : $this->nonNegative($plannedUnits, 'planned_units');
        $factor = bcdiv($batchBasis, $recipeBasis, self::INTERNAL_SCALE);
        $conditions = array_fill_keys(array_map(static fn (mixed $value): string => strtoupper(trim((string) $value)), $activeConditions), true);

        $requirements = [];
        $inputMass = '0.000000000000';
        foreach ($components as $index => $component) {
            $method = strtoupper((string) ($component['scaling_method'] ?? 'SCALABLE'));
            if (! in_array($method, self::SCALING_METHODS, true)) {
                throw ValidationException::withMessages(["components.{$index}.scaling_method" => ['Unsupported ingredient scaling method.']]);
            }
            $standardQuantity = $this->nonNegative($component['quantity'] ?? 0, "components.{$index}.quantity");
            $conditionCode = strtoupper(trim((string) ($component['condition_code'] ?? '')));
            $isIncluded = $method !== 'CONDITIONAL' || ($conditionCode !== '' && isset($conditions[$conditionCode]));

            if (! $isIncluded) {
                $calculated = '0.000000000000';
            } elseif ($method === 'FIXED') {
                $calculated = $standardQuantity;
            } elseif ($method === 'PER_UNIT') {
                if ($planned === null) {
                    throw ValidationException::withMessages(["components.{$index}.quantity_per_unit" => ['Planned units are required for a per-unit ingredient.']]);
                }
                $perUnit = $this->nonNegative($component['quantity_per_unit'] ?? $standardQuantity, "components.{$index}.quantity_per_unit");
                $calculated = bcmul($perUnit, $planned, self::INTERNAL_SCALE);
            } elseif (($component['bakers_percentage'] ?? null) !== null && $component['bakers_percentage'] !== '') {
                $percentage = $this->nonNegative($component['bakers_percentage'], "components.{$index}.bakers_percentage");
                $calculated = bcmul($batchBasis, bcdiv($percentage, '100', self::INTERNAL_SCALE), self::INTERNAL_SCALE);
            } else {
                $calculated = bcmul($standardQuantity, $factor, self::INTERNAL_SCALE);
            }

            $wastePercent = $this->nonNegative($component['waste_percent'] ?? 0, "components.{$index}.waste_percent");
            $lossIncluded = (bool) ($component['loss_included_in_standard'] ?? false);
            $grossUp = $isIncluded && ! $lossIncluded && bccomp($wastePercent, '0', self::INTERNAL_SCALE) > 0
                ? bcmul($calculated, bcadd('1', bcdiv($wastePercent, '100', self::INTERNAL_SCALE), self::INTERNAL_SCALE), self::INTERNAL_SCALE)
                : $calculated;
            $contributesToMass = (bool) ($component['contributes_to_input_mass'] ?? ! ($component['is_packaging'] ?? false));
            $massConversionFactor = $this->nonNegative($component['mass_conversion_factor'] ?? 1, "components.{$index}.mass_conversion_factor");
            $normalisedInputMass = $contributesToMass
                ? bcmul($grossUp, $massConversionFactor, self::INTERNAL_SCALE)
                : '0.000000000000';
            if ($contributesToMass) {
                $inputMass = bcadd($inputMass, $normalisedInputMass, self::INTERNAL_SCALE);
            }
            $requirements[] = [
                'component_id' => isset($component['id']) ? (string) $component['id'] : null,
                'scaling_method' => $method,
                'condition_code' => $conditionCode !== '' ? $conditionCode : null,
                'condition_applied' => $isIncluded,
                'is_basis_component' => (bool) ($component['is_basis_component'] ?? false),
                'is_packaging' => (bool) ($component['is_packaging'] ?? false),
                'standard_quantity' => $this->decimal($standardQuantity),
                'calculated_quantity' => $this->decimal($grossUp),
                'normalised_input_mass' => $this->decimal($normalisedInputMass),
                'standard_loss_percent' => $this->decimal($wastePercent),
                'loss_included_in_standard' => $lossIncluded,
                'loss_gross_up_applied' => $isIncluded && ! $lossIncluded && bccomp($wastePercent, '0', self::INTERNAL_SCALE) > 0,
            ];
        }

        $additionMass = '0.000000000000';
        foreach ($measuredAdditions as $index => $addition) {
            $quantity = $this->nonNegative($addition['quantity'] ?? 0, "measured_additions.{$index}.quantity");
            if ((bool) ($addition['contributes_to_input_mass'] ?? true)) {
                $additionMass = bcadd($additionMass, $quantity, self::INTERNAL_SCALE);
            }
        }
        $inputMass = bcadd($inputMass, $additionMass, self::INTERNAL_SCALE);

        $output = $this->output($standard, $inputMass, $batchBasis, $factor, $planned);

        return [
            'precision_scale' => self::SCALE,
            'rounding_policy' => strtoupper((string) ($standard['output_rounding_mode'] ?? 'DOWN')),
            'recipe_basis_quantity' => $this->decimal($recipeBasis),
            'batch_basis_quantity' => $this->decimal($batchBasis),
            'scaling_factor' => $this->decimal($factor, 9),
            'requirements' => $requirements,
            'measured_additions_mass' => $this->decimal($additionMass),
            'theoretical_input_mass' => $this->decimal($inputMass),
        ] + $output;
    }

    /** @return array<string, string|null> */
    public function outputVariance(mixed $actualGoodUnits, mixed $expectedGoodUnits): array
    {
        $actual = $this->nonNegative($actualGoodUnits, 'actual_good_units');
        $expected = $this->nonNegative($expectedGoodUnits, 'expected_good_units');
        $variance = bcsub($actual, $expected, self::INTERNAL_SCALE);

        return [
            'actual_good_units' => $this->decimal($actual),
            'expected_good_units' => $this->decimal($expected),
            'output_variance' => $this->decimal($variance),
            'output_variance_percent' => bccomp($expected, '0', self::INTERNAL_SCALE) === 0
                ? null
                : $this->decimal(bcmul(bcdiv($variance, $expected, self::INTERNAL_SCALE), '100', self::INTERNAL_SCALE)),
            'output_variance_exception' => bccomp($expected, '0', self::INTERNAL_SCALE) === 0 ? 'ZERO_EXPECTED_OUTPUT' : null,
        ];
    }

    /** @return array<string, string> */
    public function materialVariance(mixed $netConsumed, mixed $originalPlan, mixed $normalisedStandard): array
    {
        $actual = $this->nonNegative($netConsumed, 'net_consumed_quantity');
        $original = $this->nonNegative($originalPlan, 'original_plan_quantity');
        $normalised = $this->nonNegative($normalisedStandard, 'normalised_standard_quantity');

        return [
            'net_consumed_quantity' => $this->decimal($actual),
            'original_plan_quantity' => $this->decimal($original),
            'normalised_standard_quantity' => $this->decimal($normalised),
            'original_plan_variance' => $this->decimal(bcsub($actual, $original, self::INTERNAL_SCALE)),
            'efficiency_variance' => $this->decimal(bcsub($actual, $normalised, self::INTERNAL_SCALE)),
        ];
    }

    /** @return array<string, mixed> */
    private function output(array $standard, string $inputMass, string $batchBasis, string $factor, ?string $planned): array
    {
        $model = strtoupper((string) ($standard['calculation_model'] ?? 'LEGACY_YIELD'));
        if (! in_array($model, self::MODELS, true)) {
            throw ValidationException::withMessages(['calculation_model' => ['Unsupported recipe calculation model.']]);
        }
        $rounding = strtoupper((string) ($standard['output_rounding_mode'] ?? 'DOWN'));
        if (! in_array($rounding, self::ROUNDING_MODES, true)) {
            throw ValidationException::withMessages(['output_rounding_mode' => ['Unsupported output rounding policy.']]);
        }
        $handlingPercent = $this->percent($standard['handling_loss_percent'] ?? 0, 'handling_loss_percent');
        $rejectionPercent = $this->percent($standard['rejection_allowance_percent'] ?? 0, 'rejection_allowance_percent');
        $transformationPercent = $this->percent($standard['transformation_loss_percent'] ?? 0, 'transformation_loss_percent');
        $handlingApplied = ! (bool) ($standard['handling_loss_in_standard'] ?? false);
        $handlingLoss = $handlingApplied
            ? bcmul($inputMass, bcdiv($handlingPercent, '100', self::INTERNAL_SCALE), self::INTERNAL_SCALE)
            : '0.000000000000';
        $usableAfterHandling = bcsub($inputMass, $handlingLoss, self::INTERNAL_SCALE);
        $transformationLoss = bcmul($inputMass, bcdiv($transformationPercent, '100', self::INTERNAL_SCALE), self::INTERNAL_SCALE);
        $theoretical = $formed = $residual = '0.000000000000';

        if ($model === 'LEGACY_YIELD') {
            $theoretical = $planned ?? bcmul($this->nonNegative($standard['output_quantity'] ?? 0, 'output_quantity'), $factor, self::INTERNAL_SCALE);
            $formed = $theoretical;
        } elseif ($model === 'WEIGHT_PIECE') {
            $pieceWeight = $this->positive($standard['standard_piece_weight'] ?? null, 'standard_piece_weight');
            $theoretical = $this->floor(bcdiv($inputMass, $pieceWeight, self::INTERNAL_SCALE));
            $residual = bcsub($inputMass, bcmul($theoretical, $pieceWeight, self::INTERNAL_SCALE), self::INTERNAL_SCALE);
            $formed = $this->floor(bcdiv($usableAfterHandling, $pieceWeight, self::INTERNAL_SCALE));
        } elseif ($model === 'UNIT_CONVERSION') {
            $rate = $this->positive($standard['unit_conversion_rate'] ?? null, 'unit_conversion_rate');
            $theoretical = bcmul($batchBasis, $rate, self::INTERNAL_SCALE);
            $formed = $handlingApplied
                ? bcmul($theoretical, bcsub('1', bcdiv($handlingPercent, '100', self::INTERNAL_SCALE), self::INTERNAL_SCALE), self::INTERNAL_SCALE)
                : $theoretical;
        } else {
            $yieldPercent = $this->percent($standard['yield_percent'] ?? 0, 'yield_percent');
            $rate = ($standard['unit_conversion_rate'] ?? null) === null
                ? '1.000000000000'
                : $this->positive($standard['unit_conversion_rate'], 'unit_conversion_rate');
            $theoretical = bcmul($inputMass, $rate, self::INTERNAL_SCALE);
            $formed = bcmul($theoretical, bcdiv($yieldPercent, '100', self::INTERNAL_SCALE), self::INTERNAL_SCALE);
        }

        $rawGood = bcmul($formed, bcsub('1', bcdiv($rejectionPercent, '100', self::INTERNAL_SCALE), self::INTERNAL_SCALE), self::INTERNAL_SCALE);
        $expectedGood = $this->roundUnits($rawGood, $rounding);

        return [
            'calculation_model' => $model,
            'theoretical_complete_units' => $this->decimal($theoretical),
            'theoretical_residual_quantity' => $this->decimal($residual),
            'approved_handling_loss_quantity' => $this->decimal($handlingLoss),
            'handling_loss_applied' => $handlingApplied,
            'usable_mass_after_handling_loss' => $this->decimal($usableAfterHandling),
            'expected_formed_units' => $this->decimal($formed),
            'approved_rejection_quantity' => $this->decimal(bcsub($formed, $rawGood, self::INTERNAL_SCALE)),
            'expected_good_units_before_rounding' => $this->decimal($rawGood),
            'expected_good_units' => $this->decimal($expectedGood),
            'expected_transformation_loss_quantity' => $this->decimal($transformationLoss),
            'transformation_loss_in_standard' => (bool) ($standard['transformation_loss_in_standard'] ?? true),
            'transformation_loss_deducted_from_piece_count' => false,
            'exception_code' => bccomp($expectedGood, '0', self::INTERNAL_SCALE) === 0 ? 'ZERO_EXPECTED_OUTPUT' : null,
        ];
    }

    private function roundUnits(string $value, string $mode): string
    {
        if ($mode === 'NONE') {
            return $value;
        }
        if ($mode === 'HALF_UP') {
            return bcround($value, 0, \RoundingMode::HalfAwayFromZero);
        }
        $floor = $this->floor($value);
        if ($mode === 'UP' && bccomp($value, $floor, self::INTERNAL_SCALE) > 0) {
            return bcadd($floor, '1', 0);
        }

        return $floor;
    }

    private function floor(string $value): string
    {
        return bcdiv($value, '1', 0);
    }

    private function percent(mixed $value, string $field): string
    {
        $number = $this->nonNegative($value, $field);
        if (bccomp($number, '100', self::INTERNAL_SCALE) > 0) {
            throw ValidationException::withMessages([$field => ['Percentage must be between zero and 100.']]);
        }

        return $number;
    }

    private function positive(mixed $value, string $field): string
    {
        $number = $this->number($value, $field);
        if (bccomp($number, '0', self::INTERNAL_SCALE) <= 0) {
            throw ValidationException::withMessages([$field => ['Value must be greater than zero.']]);
        }

        return $number;
    }

    private function nonNegative(mixed $value, string $field): string
    {
        $number = $this->number($value, $field);
        if (bccomp($number, '0', self::INTERNAL_SCALE) < 0) {
            throw ValidationException::withMessages([$field => ['Value cannot be negative.']]);
        }

        return $number;
    }

    private function number(mixed $value, string $field): string
    {
        $text = trim((string) ($value ?? ''));
        if (! preg_match('/^\d{1,20}(?:\.\d{1,12})?$/', $text)) {
            throw ValidationException::withMessages([$field => ['Enter a non-negative number with at most 12 decimal places.']]);
        }

        return bcadd($text, '0', self::INTERNAL_SCALE);
    }

    private function decimal(mixed $value, int $scale = self::SCALE): string
    {
        return bcadd((string) ($value ?? 0), '0', $scale);
    }
}
