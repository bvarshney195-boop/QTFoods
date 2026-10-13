<?php

namespace App\Shared\Visibility;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OperationalSummaryService
{
    public function __construct(private readonly OperationalVisibilityScope $visibility) {}

    public function count(Builder $query, string $column = '*'): int
    {
        return (int) (clone $query)->count($column);
    }

    public function sum(Builder $query, string $column, int $scale = 6): string
    {
        return bcadd((string) (clone $query)->sum($column), '0', $scale);
    }

    public function inventoryTraceabilityLimitations(array $scope, array $permissions): int
    {
        if (in_array(TransactionVisibilityPolicy::SHOW_PERMISSION, $permissions, true)) {
            return 0;
        }

        $query = DB::table('stock_positions as position')
            ->where('position.company_id', $scope['company_id'])
            ->where('position.plant_id', $scope['plant_id'])
            ->where('position.quantity_base', '>', 0);
        $this->visibility->applyMixedInventoryAttribution($query, 'position.lot_id');

        return (int) $query->count('position.id');
    }
}
