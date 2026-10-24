<?php

namespace Tests\Feature;

use App\Modules\Manufacturing\Application\RecipeYieldCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecipeYieldCalculatorTest extends TestCase
{
    private RecipeYieldCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RecipeYieldCalculator();
    }

    public function test_non_one_hundred_basis_multi_flour_and_all_scaling_methods(): void
    {
        $result = $this->calculator->calculate([
            'basis_quantity' => '40', 'calculation_model' => 'UNIT_CONVERSION',
            'unit_conversion_rate' => '2', 'handling_loss_percent' => '0',
            'rejection_allowance_percent' => '0', 'output_rounding_mode' => 'DOWN',
        ], [
            ['id' => 'flour-a', 'quantity' => '24', 'bakers_percentage' => '60', 'scaling_method' => 'SCALABLE'],
            ['id' => 'flour-b', 'quantity' => '16', 'bakers_percentage' => '40', 'scaling_method' => 'SCALABLE'],
            ['id' => 'water', 'quantity' => '20', 'scaling_method' => 'SCALABLE'],
            ['id' => 'salt', 'quantity' => '2', 'scaling_method' => 'FIXED'],
            ['id' => 'label', 'quantity' => '1', 'quantity_per_unit' => '0.01', 'scaling_method' => 'PER_UNIT',
                'is_packaging' => true, 'contributes_to_input_mass' => false],
            ['id' => 'enzyme', 'quantity' => '0.4', 'scaling_method' => 'CONDITIONAL', 'condition_code' => 'PREMIUM'],
            ['id' => 'inactive', 'quantity' => '1', 'scaling_method' => 'CONDITIONAL', 'condition_code' => 'SEASONAL'],
        ], '75', '150', ['premium'], [['quantity' => '4.75', 'contributes_to_input_mass' => true]]);

        self::assertSame('40.000000', $result['recipe_basis_quantity']);
        self::assertSame('75.000000', $result['batch_basis_quantity']);
        self::assertSame('1.875000000', $result['scaling_factor']);
        self::assertSame(['45.000000', '30.000000', '37.500000', '2.000000', '1.500000', '0.750000', '0.000000'],
            array_column($result['requirements'], 'calculated_quantity'));
        self::assertSame('4.750000', $result['measured_additions_mass']);
        self::assertSame('120.000000', $result['theoretical_input_mass']);
        self::assertSame('150.000000', $result['expected_good_units']);
        self::assertTrue($result['requirements'][5]['condition_applied']);
        self::assertFalse($result['requirements'][6]['condition_applied']);
    }

    public function test_weight_piece_yield_has_floor_residual_handling_rejection_and_no_transformation_double_deduction(): void
    {
        $result = $this->calculator->calculate([
            'basis_quantity' => '100', 'calculation_model' => 'WEIGHT_PIECE',
            'standard_piece_weight' => '0.105', 'handling_loss_percent' => '2',
            'handling_loss_in_standard' => false, 'rejection_allowance_percent' => '1.5',
            'transformation_loss_percent' => '8', 'transformation_loss_in_standard' => false,
            'output_rounding_mode' => 'DOWN',
        ], [['quantity' => '100', 'scaling_method' => 'SCALABLE']], '100', null, [], [
            ['quantity' => '2.8', 'contributes_to_input_mass' => true],
        ]);

        self::assertSame('102.800000', $result['theoretical_input_mass']);
        self::assertSame('979.000000', $result['theoretical_complete_units']);
        self::assertSame('0.005000', $result['theoretical_residual_quantity']);
        self::assertSame('2.056000', $result['approved_handling_loss_quantity']);
        self::assertSame('959.000000', $result['expected_formed_units']);
        self::assertSame('944.000000', $result['expected_good_units']);
        self::assertSame('8.224000', $result['expected_transformation_loss_quantity']);
        self::assertFalse($result['transformation_loss_deducted_from_piece_count']);

        $variance = $this->calculator->outputVariance('950', $result['expected_good_units']);
        self::assertSame('6.000000', $variance['output_variance']);
        self::assertSame('0.635593', $variance['output_variance_percent']);
    }

    public function test_handling_loss_already_in_standard_is_not_deducted_again(): void
    {
        $result = $this->calculator->calculate([
            'basis_quantity' => '100', 'calculation_model' => 'WEIGHT_PIECE',
            'standard_piece_weight' => '0.1', 'handling_loss_percent' => '10',
            'handling_loss_in_standard' => true, 'rejection_allowance_percent' => '0',
            'transformation_loss_percent' => '12', 'transformation_loss_in_standard' => true,
            'output_rounding_mode' => 'DOWN',
        ], [['quantity' => '100', 'scaling_method' => 'SCALABLE']], '100');

        self::assertFalse($result['handling_loss_applied']);
        self::assertSame('0.000000', $result['approved_handling_loss_quantity']);
        self::assertSame('1000.000000', $result['theoretical_complete_units']);
        self::assertSame('1000.000000', $result['expected_formed_units']);
        self::assertSame('1000.000000', $result['expected_good_units']);
    }

    #[DataProvider('roundingCases')]
    public function test_approved_output_rounding_policies(string $mode, string $expected): void
    {
        $result = $this->calculator->calculate([
            'basis_quantity' => '7', 'calculation_model' => 'UNIT_CONVERSION',
            'unit_conversion_rate' => '1.5', 'handling_loss_percent' => '0',
            'rejection_allowance_percent' => '10', 'output_rounding_mode' => $mode,
        ], [], '7');

        self::assertSame('10.500000', $result['expected_formed_units']);
        self::assertSame('9.450000', $result['expected_good_units_before_rounding']);
        self::assertSame($expected, $result['expected_good_units']);
    }

    /** @return array<string, array{string, string}> */
    public static function roundingCases(): array
    {
        return ['down' => ['DOWN', '9.000000'], 'up' => ['UP', '10.000000'],
            'half-up' => ['HALF_UP', '9.000000'], 'none' => ['NONE', '9.450000']];
    }

    public function test_zero_expected_output_and_original_plan_vs_efficiency_variances_are_explicit(): void
    {
        $zero = $this->calculator->outputVariance('4', '0');
        self::assertNull($zero['output_variance_percent']);
        self::assertSame('ZERO_EXPECTED_OUTPUT', $zero['output_variance_exception']);

        $variance = $this->calculator->materialVariance('118', '100', '120');
        self::assertSame('18.000000', $variance['original_plan_variance']);
        self::assertSame('-2.000000', $variance['efficiency_variance']);
    }
}
