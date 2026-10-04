<?php

namespace App\Modules\Sales\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class UnsoldSalesReturnLookupQuery
{
    public function get(array $scope, array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? 50);

        return [
            'parties' => $this->parties($scope, $filters, $limit),
            'shipments' => $this->shipments($scope, $filters, $limit),
            'invoices' => $this->invoices($scope, $filters, $limit),
            'shipment_lines' => $this->shipmentLines($scope, $filters, $limit),
            'skus' => $this->skus($scope, $filters, $limit),
            'lots' => $this->lots($scope, $filters, $limit),
            'return_positions' => $this->returnPositions($scope, $filters, $limit),
        ];
    }

    private function parties(array $scope, array $filters, int $limit): array
    {
        return DB::table('parties')
            ->where('company_id', $scope['company_id'])
            ->where('status', 'ACTIVE')
            ->whereExists(fn (Builder $role) => $role->from('party_roles as party_role')
                ->whereColumn('party_role.party_id', 'parties.id')
                ->where('party_role.role_code', 'CUSTOMER'))
            ->when($filters['q'] ?? null, function (Builder $query, string $search) {
                $pattern = '%'.strtolower(trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(code) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(display_name) LIKE ?', [$pattern]));
            })
            ->orderBy('display_name')
            ->limit($limit)
            ->get(['id', 'code', 'display_name'])
            ->map(fn (object $party) => [
                'id' => (string) $party->id,
                'code' => $party->code,
                'name' => $party->display_name,
            ])
            ->values()
            ->all();
    }

    private function shipments(array $scope, array $filters, int $limit): array
    {
        return $this->scopedShipments($scope)
            ->leftJoin('parties as party', function (JoinClause $join) {
                $join
                    ->on('party.id', '=', 'shipment.party_id')
                    ->on('party.company_id', '=', 'shipment.company_id');
            })
            ->whereNotNull('shipment.shipment_number')
            ->whereNotIn('shipment.status', ['DRAFT', 'CANCELLED'])
            ->where('party.status', 'ACTIVE')
            ->whereExists(fn (Builder $role) => $role->from('party_roles as party_role')
                ->whereColumn('party_role.party_id', 'party.id')
                ->where('party_role.role_code', 'CUSTOMER'))
            ->when($filters['party_id'] ?? null, fn (Builder $query, string $id) => $query->where('shipment.party_id', $id))
            ->when($filters['q'] ?? null, function (Builder $query, string $search) {
                $pattern = '%'.strtolower(trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(shipment.shipment_number) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(party.code) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(party.display_name) LIKE ?', [$pattern]));
            })
            ->orderByDesc('shipment.dispatched_at')
            ->orderBy('shipment.shipment_number')
            ->limit($limit)
            ->get([
                'shipment.id',
                'shipment.shipment_number',
                'shipment.party_id',
                'party.code as party_code',
                'party.display_name as party_name',
                'shipment.status',
                'shipment.dispatched_at',
            ])
            ->map(fn (object $shipment) => [
                'id' => (string) $shipment->id,
                'number' => $shipment->shipment_number,
                'party' => [
                    'id' => (string) $shipment->party_id,
                    'code' => $shipment->party_code,
                    'name' => $shipment->party_name,
                ],
                'status' => $shipment->status,
                'dispatched_at' => $this->timestamp($shipment->dispatched_at),
            ])
            ->values()
            ->all();
    }

    private function shipmentLines(array $scope, array $filters, int $limit): array
    {
        $shipmentId = $filters['shipment_id'] ?? null;
        if (! $shipmentId) {
            return [];
        }

        return DB::table('shipment_lines as line')
            ->join('shipments as shipment', 'shipment.id', '=', 'line.shipment_id')
            ->leftJoin('items as sku', function (JoinClause $join) {
                $join
                    ->on('sku.id', '=', 'line.item_id')
                    ->on('sku.company_id', '=', 'line.company_id');
            })
            ->leftJoin('lots as lot', function (JoinClause $join) {
                $join
                    ->on('lot.id', '=', 'line.fg_lot_id')
                    ->on('lot.company_id', '=', 'line.company_id');
            })
            ->where('shipment.id', $shipmentId)
            ->where('shipment.company_id', $scope['company_id'])
            ->whereNotIn('shipment.status', ['DRAFT', 'CANCELLED'])
            ->where('line.company_id', $scope['company_id'])
            ->where('sku.item_type', 'FINISHED_GOOD')
            ->where('sku.status', 'ACTIVE')
            ->when($scope['plant_id'] ?? null, function (Builder $query, string $plantId) {
                $query->where('shipment.plant_id', $plantId)->where('line.plant_id', $plantId);
            })
            ->orderBy('sku.code')
            ->orderBy('line.id')
            ->limit($limit)
            ->get([
                'line.id',
                'line.item_id',
                'sku.code as sku_code',
                'sku.name as sku_name',
                'line.fg_lot_id',
                'lot.internal_lot_code as lot_code',
                'lot.expiry_date',
                'line.shipped_quantity',
                'line.returned_quantity',
                'line.uom_code',
            ])
            ->map(function (object $line) {
                $available = bcsub((string) $line->shipped_quantity, (string) $line->returned_quantity, 6);

                return [
                    'id' => (string) $line->id,
                    'sku' => [
                        'id' => (string) $line->item_id,
                        'code' => $line->sku_code,
                        'name' => $line->sku_name,
                    ],
                    'fg_lot' => $line->fg_lot_id ? [
                        'id' => (string) $line->fg_lot_id,
                        'code' => $line->lot_code,
                        'expiry_date' => $line->expiry_date,
                    ] : null,
                    'shipped_quantity' => (string) $line->shipped_quantity,
                    'returned_quantity' => (string) $line->returned_quantity,
                    'available_to_return' => bccomp($available, '0', 6) > 0 ? $available : '0.000000',
                    'uom_code' => $line->uom_code,
                ];
            })
            ->values()
            ->all();
    }

    private function invoices(array $scope, array $filters, int $limit): array
    {
        $shipmentId = $filters['shipment_id'] ?? null;
        if (! $shipmentId) {
            return [];
        }

        return DB::table('sales_invoice_financials as financial')
            ->join('invoices as invoice', function (JoinClause $join) {
                $join
                    ->on('invoice.id', '=', 'financial.invoice_id')
                    ->on('invoice.company_id', '=', 'financial.company_id')
                    ->on('invoice.plant_id', '=', 'financial.plant_id');
            })
            ->where('financial.company_id', $scope['company_id'])
            ->when(
                $scope['plant_id'] ?? null,
                fn (Builder $query, string $plantId) => $query->where('financial.plant_id', $plantId)
            )
            ->where('financial.shipment_id', $shipmentId)
            ->when(
                $filters['party_id'] ?? null,
                fn (Builder $query, string $partyId) => $query->where('financial.party_id', $partyId)
            )
            ->whereIn('invoice.status', ['POSTED', 'PAID'])
            ->orderByDesc('financial.issued_at')
            ->orderBy('financial.invoice_number')
            ->limit($limit)
            ->get([
                'financial.invoice_id',
                'financial.invoice_number',
                'invoice.status',
                'financial.currency',
                'financial.net_amount',
                'financial.tax_amount',
                'financial.gross_amount',
                'financial.outstanding_amount',
                'financial.issued_at',
                'financial.record_version',
            ])
            ->map(fn (object $invoice) => [
                'id' => (string) $invoice->invoice_id,
                'number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'net_amount' => (string) $invoice->net_amount,
                'tax_amount' => (string) $invoice->tax_amount,
                'gross_amount' => (string) $invoice->gross_amount,
                'outstanding_amount' => (string) $invoice->outstanding_amount,
                'issued_at' => $this->timestamp($invoice->issued_at),
                'record_version' => (int) $invoice->record_version,
            ])
            ->values()
            ->all();
    }

    private function skus(array $scope, array $filters, int $limit): array
    {
        return DB::table('items')
            ->where('company_id', $scope['company_id'])
            ->where('item_type', 'FINISHED_GOOD')
            ->where('status', 'ACTIVE')
            ->when($filters['q'] ?? null, function (Builder $query, string $search) {
                $pattern = '%'.strtolower(trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(code) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$pattern]));
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'base_uom'])
            ->map(fn (object $item) => [
                'id' => (string) $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'uom_code' => $item->base_uom,
            ])
            ->values()
            ->all();
    }

    private function lots(array $scope, array $filters, int $limit): array
    {
        $skuId = $filters['sku_id'] ?? null;
        if (! $skuId) {
            return [];
        }

        return DB::table('lots')
            ->where('company_id', $scope['company_id'])
            ->where('item_id', $skuId)
            ->when($filters['lot_id'] ?? null, fn (Builder $query, string $id) => $query->where('id', $id))
            ->orderByDesc('expiry_date')
            ->orderBy('internal_lot_code')
            ->limit($limit)
            ->get(['id', 'item_id', 'internal_lot_code', 'manufacture_date', 'expiry_date'])
            ->map(fn (object $lot) => [
                'id' => (string) $lot->id,
                'sku_id' => (string) $lot->item_id,
                'code' => $lot->internal_lot_code,
                'manufacture_date' => $lot->manufacture_date,
                'expiry_date' => $lot->expiry_date,
            ])
            ->values()
            ->all();
    }

    private function returnPositions(array $scope, array $filters, int $limit): array
    {
        $skuId = $filters['sku_id'] ?? null;
        if (! $skuId) {
            return [];
        }

        $lotId = $filters['lot_id'] ?? null;
        $positions = DB::table('stock_positions as position')
            ->leftJoin('locations as location', function (JoinClause $join) {
                $join
                    ->on('location.id', '=', 'position.location_id')
                    ->on('location.company_id', '=', 'position.company_id')
                    ->on('location.plant_id', '=', 'position.plant_id');
            })
            ->leftJoin('lots as lot', function (JoinClause $join) {
                $join
                    ->on('lot.id', '=', 'position.lot_id')
                    ->on('lot.company_id', '=', 'position.company_id');
            })
            ->where('position.company_id', $scope['company_id'])
            ->when($scope['plant_id'] ?? null, fn (Builder $query, string $plantId) => $query->where('position.plant_id', $plantId))
            ->where('position.item_id', $skuId)
            ->where('position.quality_status', 'RETURN_QUARANTINE')
            ->where('location.location_type', 'RETURN_QUARANTINE')
            ->where('location.status', 'ACTIVE')
            ->when($lotId, fn (Builder $query, string $id) => $query->where('position.lot_id', $id))
            ->orderBy('location.code')
            ->orderBy('lot.internal_lot_code')
            ->limit($limit)
            ->get([
                'position.id',
                'position.item_id',
                'position.lot_id',
                'lot.internal_lot_code as lot_code',
                'position.location_id',
                'location.code as location_code',
                'location.name as location_name',
                'position.quality_status',
                'position.quantity_base',
                'position.uom_code',
            ])
            ->map(fn (object $position) => [
                'id' => (string) $position->id,
                'sku_id' => (string) $position->item_id,
                'lot' => ['id' => (string) $position->lot_id, 'code' => $position->lot_code],
                'location' => ['id' => (string) $position->location_id, 'code' => $position->location_code, 'name' => $position->location_name],
                'quality_status' => $position->quality_status,
                'quantity' => (string) $position->quantity_base,
                'uom_code' => $position->uom_code,
                'provision_on_receipt' => false,
            ])
            ->values();

        if ($positions->isNotEmpty() || ! $lotId) {
            return $positions->all();
        }

        $lot = DB::table('lots')->where('company_id', $scope['company_id'])->where('id', $lotId)
            ->where('item_id', $skuId)->first(['id', 'internal_lot_code']);
        $item = DB::table('items')->where('company_id', $scope['company_id'])->where('id', $skuId)
            ->where('status', 'ACTIVE')->first(['id', 'base_uom']);
        if (! $lot || ! $item) {
            return [];
        }

        return DB::table('locations')
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])
            ->where('location_type', 'RETURN_QUARANTINE')
            ->where('status', 'ACTIVE')
            ->orderBy('code')
            ->limit($limit)
            ->get(['id', 'code', 'name'])
            ->map(fn (object $location) => [
                // A location UUID is accepted as a provisionable destination. The receipt command
                // creates/reuses the exact zero-balance position atomically before posting movement.
                'id' => (string) $location->id,
                'sku_id' => (string) $skuId,
                'lot' => ['id' => (string) $lot->id, 'code' => $lot->internal_lot_code],
                'location' => ['id' => (string) $location->id, 'code' => $location->code, 'name' => $location->name],
                'quality_status' => 'RETURN_QUARANTINE',
                'quantity' => '0.000000',
                'uom_code' => $item->base_uom,
                'provision_on_receipt' => true,
            ])
            ->all();
    }

    private function scopedShipments(array $scope): Builder
    {
        return DB::table('shipments as shipment')
            ->where('shipment.company_id', $scope['company_id'])
            ->when($scope['plant_id'] ?? null, fn (Builder $query, string $plantId) => $query->where('shipment.plant_id', $plantId));
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
