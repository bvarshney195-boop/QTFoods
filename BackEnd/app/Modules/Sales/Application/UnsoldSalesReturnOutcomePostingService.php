<?php

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\StockPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class UnsoldSalesReturnOutcomePostingService
{
    private const OUTCOMES = [
        'restock_quantity' => [
            'movement_type' => 'UNSOLD_RETURN_RESTOCK',
            'quality_status' => 'RELEASED',
            'location_type' => 'FINISHED_GOODS',
        ],
        'repack_quantity' => [
            'movement_type' => 'UNSOLD_RETURN_REPACK',
            'quality_status' => 'REPACK_HOLD',
            'location_type' => 'REPACK',
        ],
        'rework_quantity' => [
            'movement_type' => 'UNSOLD_RETURN_REWORK',
            'quality_status' => 'REWORK_HOLD',
            'location_type' => 'REWORK',
        ],
    ];

    public function __construct(private readonly StockPostingService $stock) {}

    /**
     * Move approved non-destroyed quantities out of return quarantine.
     *
     * @return list<string>
     */
    public function postApproved(string $approvalId, ?string $correlationId = null): array
    {
        $approval = DB::table('approval_requests')
            ->where('id', $approvalId)
            ->where('entity_type', 'unsold_return_loss')
            ->where('rule_code', 'UNSOLD_RETURN_LOSS_APPROVAL')
            ->first();

        if (! $approval || $approval->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'approval' => ['An approved return disposition is required before outcome stock can be posted.'],
            ]);
        }

        $case = DB::table('unsold_return_cases')
            ->where('id', $approval->entity_id)
            ->where('company_id', $approval->company_id)
            ->where('plant_id', $approval->plant_id)
            ->first();

        if (
            ! $case
            || $case->status !== 'DISPOSITION_REVIEW'
            || (int) $case->record_version !== (int) $approval->entity_version
        ) {
            throw new ConflictHttpException(
                'The return case no longer matches the approved disposition version.'
            );
        }

        $reviewerId = DB::table('approval_decisions')
            ->where('approval_request_id', $approvalId)
            ->where('decision', 'APPROVE')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('reviewer_id');

        if (! $reviewerId) {
            throw ValidationException::withMessages([
                'approval' => ['The approved disposition has no reviewer decision record.'],
            ]);
        }

        $lines = DB::table('unsold_return_lines')
            ->where('return_case_id', $case->id)
            ->orderBy('id')
            ->get([
                'id',
                'sku_id',
                'fg_lot_id',
                'return_position_id',
                'restock_quantity',
                'repack_quantity',
                'rework_quantity',
                'uom_code',
                'quality_reason_code',
            ]);

        $movementIds = [];
        foreach ($lines as $line) {
            $this->assertReturnPosition($line, $case);

            foreach (self::OUTCOMES as $quantityField => $route) {
                $quantity = (string) $line->{$quantityField};
                if (bccomp($quantity, '0', 6) <= 0) {
                    continue;
                }

                $targetId = $this->destinationPosition($line, $case, $route);
                $movementKey = 'return-outcome:'.hash(
                    'sha256',
                    $approvalId.'|'.$line->id.'|'.$quantityField
                );

                $movement = $this->stock->move([
                    'company_id' => (string) $case->company_id,
                    'plant_id' => (string) $case->plant_id,
                    'source_position_id' => (string) $line->return_position_id,
                    'target_position_id' => $targetId,
                    'expected_item_id' => (string) $line->sku_id,
                    'expected_lot_id' => (string) $line->fg_lot_id,
                    'expected_source_quality_status' => 'RETURN_QUARANTINE',
                    'expected_target_quality_status' => $route['quality_status'],
                    'quantity_base' => $quantity,
                    'uom_code' => $line->uom_code,
                    'movement_type' => $route['movement_type'],
                    'source_type' => 'UNSOLD_RETURN',
                    'source_id' => (string) $case->id,
                    'source_version' => (int) $approval->entity_version,
                    'actor_id' => (string) $reviewerId,
                    'reason_code' => $line->quality_reason_code ?: 'APPROVED_RETURN_DISPOSITION',
                    'idempotency_key' => $movementKey,
                    'correlation_id' => $correlationId,
                ]);

                $movementIds[] = $movement['movement_id'];
            }
        }

        return $movementIds;
    }

    private function assertReturnPosition(object $line, object $case): void
    {
        $position = DB::table('stock_positions as position')
            ->join('locations as location', function ($join) {
                $join
                    ->on('location.id', '=', 'position.location_id')
                    ->on('location.company_id', '=', 'position.company_id')
                    ->on('location.plant_id', '=', 'position.plant_id');
            })
            ->where('position.id', $line->return_position_id)
            ->where('position.company_id', $case->company_id)
            ->where('position.plant_id', $case->plant_id)
            ->where('position.item_id', $line->sku_id)
            ->where('position.lot_id', $line->fg_lot_id)
            ->where('position.uom_code', $line->uom_code)
            ->where('position.quality_status', 'RETURN_QUARANTINE')
            ->where('location.location_type', 'RETURN_QUARANTINE')
            ->where('location.status', 'ACTIVE')
            ->exists();

        if (! $position) {
            throw ValidationException::withMessages([
                'return_position' => [
                    'Every approved line must retain an active quarantine position matching its company, plant, SKU, lot, and UOM.',
                ],
            ]);
        }
    }

    private function destinationPosition(object $line, object $case, array $route): string
    {
        $positions = DB::table('stock_positions as position')
            ->join('locations as location', function ($join) {
                $join
                    ->on('location.id', '=', 'position.location_id')
                    ->on('location.company_id', '=', 'position.company_id')
                    ->on('location.plant_id', '=', 'position.plant_id');
            })
            ->where('position.company_id', $case->company_id)
            ->where('position.plant_id', $case->plant_id)
            ->where('position.item_id', $line->sku_id)
            ->where('position.lot_id', $line->fg_lot_id)
            ->where('position.uom_code', $line->uom_code)
            ->where('position.quality_status', $route['quality_status'])
            ->where('location.location_type', $route['location_type'])
            ->where('location.status', 'ACTIVE')
            ->orderBy('position.id')
            ->limit(2)
            ->pluck('position.id');

        if ($positions->count() !== 1) {
            $outcome = strtolower(str_replace('UNSOLD_RETURN_', '', $route['movement_type']));
            throw ValidationException::withMessages([
                'outcome_position' => [
                    "Configure exactly one active {$outcome} stock position for each approved SKU, lot, and UOM in this plant.",
                ],
            ]);
        }

        return (string) $positions->first();
    }
}
