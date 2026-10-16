<?php

namespace App\Modules\Manufacturing\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RecipeYieldService
{
    public function __construct(private readonly RecipeYieldCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function preview(string $recipeId, array $input, array $scope): array
    {
        $recipe = $this->recipe($recipeId, $scope);
        $components = $this->components($recipe, $scope);
        $additions = collect($input['measured_additions'] ?? [])->map(function (array $addition, int $index) use ($recipe): array {
            if (strtoupper((string) $addition['uom_code']) !== strtoupper((string) $recipe->basis_uom_code)) {
                throw ValidationException::withMessages([
                    "measured_additions.{$index}.uom_code" => ['Preview additions must be normalised to the recipe-basis UOM.'],
                ]);
            }
            return ['quantity' => $addition['quantity'], 'contributes_to_input_mass' => true];
        })->all();
        $result = $this->calculator->calculate(
            $this->standard($recipe),
            $components,
            $input['batch_basis_quantity'],
            $input['planned_units'] ?? null,
            $input['active_conditions'] ?? [],
            $additions,
        );

        $componentMap = collect($components)->keyBy('id');
        $result['requirements'] = collect($result['requirements'])->map(function (array $line) use ($componentMap): array {
            $component = $componentMap->get($line['component_id']);
            return $line + [
                'component_sku_id' => $component['component_sku_id'],
                'component_code' => $component['component_code'],
                'component_name' => $component['component_name'],
                'uom_code' => $component['uom_code'],
            ];
        })->all();
        $result['recipe'] = [
            'id' => (string) $recipe->id,
            'code' => (string) $recipe->code,
            'name' => (string) $recipe->name,
            'revision' => (int) $recipe->revision,
            'basis_uom_code' => (string) $recipe->basis_uom_code,
            'output_uom_code' => (string) $recipe->output_uom_code,
        ];

        return $result;
    }

    /** @return array<string, mixed> */
    public function forPlannedOutput(string $recipeId, mixed $plannedUnits, array $scope): array
    {
        $recipe = $this->recipe($recipeId, $scope);
        $planned = $this->decimal($plannedUnits, 12);
        if (bccomp($planned, '0', 12) < 0 || bccomp((string) $recipe->output_quantity, '0', 12) <= 0) {
            throw ValidationException::withMessages(['planned_units' => ['Planned output and recipe output quantity must be positive.']]);
        }
        $factor = bcdiv($planned, (string) $recipe->output_quantity, 12);
        $basis = bcmul((string) $recipe->basis_quantity, $factor, 12);

        return $this->preview($recipeId, [
            'batch_basis_quantity' => $basis,
            'planned_units' => $planned,
            'active_conditions' => [],
            'measured_additions' => [],
        ], $scope);
    }

    /** @return array<string, mixed> */
    public function standardSnapshot(string $recipeId, array $scope): array
    {
        $recipe = $this->recipe($recipeId, $scope);
        return [
            'recipe' => $this->standard($recipe) + [
                'id' => (string) $recipe->id,
                'code' => (string) $recipe->code,
                'revision' => (int) $recipe->revision,
                'basis_uom_code' => (string) $recipe->basis_uom_code,
                'output_quantity' => $this->decimal($recipe->output_quantity),
                'output_uom_code' => (string) $recipe->output_uom_code,
            ],
            'components' => $this->components($recipe, $scope),
        ];
    }

    private function recipe(string $recipeId, array $scope): object
    {
        $recipe = DB::table('recipes')->where('id', $recipeId)->where('company_id', $scope['company_id'])->first();
        if (! $recipe) {
            throw new NotFoundHttpException('Recipe not found.');
        }
        return $recipe;
    }

    /** @return array<int, array<string, mixed>> */
    private function components(object $recipe, array $scope): array
    {
        return DB::table('recipe_components as component')
            ->join('items as item', 'item.id', '=', 'component.component_sku_id')
            ->where('component.recipe_id', $recipe->id)->where('component.company_id', $scope['company_id'])
            ->orderBy('component.sequence_no')
            ->get(['component.*', 'item.code as component_code', 'item.name as component_name',
                'item.item_type', 'item.catalog_item_id', 'item.base_uom'])
            ->map(function (object $component, int $index) use ($recipe, $scope): array {
                $packaging = (bool) $component->is_packaging || $component->item_type === 'PACKAGING';
                $factor = '0.000000000000';
                $contributes = ! $packaging;
                if ($contributes) {
                    $factor = $this->massConversionFactor(
                        (string) $component->uom_code,
                        (string) $recipe->basis_uom_code,
                        (string) $component->catalog_item_id,
                        $scope,
                    );
                    if ($factor === null) {
                        if ($recipe->calculation_model === 'WEIGHT_PIECE') {
                            throw ValidationException::withMessages([
                                "components.{$index}.uom_code" => ["Configure a {$component->uom_code}-to-{$recipe->basis_uom_code} conversion for weight-piece mass balance."],
                            ]);
                        }
                        $contributes = false;
                        $factor = '0.000000000000';
                    }
                }
                return [
                    'id' => (string) $component->id,
                    'component_sku_id' => (string) $component->component_sku_id,
                    'component_code' => (string) $component->component_code,
                    'component_name' => (string) $component->component_name,
                    'quantity' => $this->decimal($component->quantity),
                    'uom_code' => (string) $component->uom_code,
                    'waste_percent' => $this->decimal($component->waste_percent),
                    'scaling_method' => (string) $component->scaling_method,
                    'bakers_percentage' => $component->bakers_percentage === null ? null : $this->decimal($component->bakers_percentage),
                    'is_basis_component' => (bool) $component->is_basis_component,
                    'condition_code' => $component->condition_code,
                    'quantity_per_unit' => $component->quantity_per_unit === null ? null : $this->decimal($component->quantity_per_unit, 9),
                    'loss_included_in_standard' => (bool) $component->loss_included_in_standard,
                    'is_packaging' => $packaging,
                    'contributes_to_input_mass' => $contributes,
                    'mass_conversion_factor' => $factor,
                ];
            })->all();
    }

    /** @return array<string, mixed> */
    private function standard(object $recipe): array
    {
        return [
            'basis_quantity' => $this->decimal($recipe->basis_quantity),
            'calculation_model' => (string) $recipe->calculation_model,
            'output_quantity' => $this->decimal($recipe->output_quantity),
            'yield_percent' => $this->decimal($recipe->yield_percent),
            'standard_piece_weight' => $recipe->standard_piece_weight,
            'target_finished_weight' => $recipe->target_finished_weight,
            'target_weight_uom_code' => $recipe->target_weight_uom_code,
            'pieces_per_pack' => (int) $recipe->pieces_per_pack,
            'handling_loss_percent' => $this->decimal($recipe->handling_loss_percent),
            'handling_loss_in_standard' => (bool) $recipe->handling_loss_in_standard,
            'transformation_loss_percent' => $this->decimal($recipe->transformation_loss_percent),
            'transformation_loss_in_standard' => (bool) $recipe->transformation_loss_in_standard,
            'rejection_allowance_percent' => $this->decimal($recipe->rejection_allowance_percent),
            'output_rounding_mode' => (string) $recipe->output_rounding_mode,
            'unit_conversion_rate' => $recipe->unit_conversion_rate,
        ];
    }

    private function massConversionFactor(string $from, string $to, string $catalogItemId, array $scope): ?string
    {
        if ($from === $to) {
            return '1.000000000000';
        }
        $direct = DB::table('item_uom_conversions')->where('company_id', $scope['company_id'])
            ->where('catalog_item_id', $catalogItemId)->where('from_uom_code', $from)->where('to_uom_code', $to)->first();
        if ($direct) {
            return $this->decimal($direct->multiplier, 12);
        }
        $reverse = DB::table('item_uom_conversions')->where('company_id', $scope['company_id'])
            ->where('catalog_item_id', $catalogItemId)->where('from_uom_code', $to)->where('to_uom_code', $from)->first();
        if ($reverse && bccomp((string) $reverse->multiplier, '0', 12) > 0) {
            return bcdiv('1', (string) $reverse->multiplier, 12);
        }
        return null;
    }

    private function decimal(mixed $value, int $scale = 6): string
    {
        return bcadd((string) ($value ?? 0), '0', $scale);
    }
}
