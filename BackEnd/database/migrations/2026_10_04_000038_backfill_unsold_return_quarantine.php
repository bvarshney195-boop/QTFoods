<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $lines = DB::table('unsold_return_lines as line')
            ->join('unsold_return_cases as return_case', 'return_case.id', '=', 'line.return_case_id')
            ->whereNull('line.return_position_id')
            ->whereIn('return_case.status', ['REQUESTED', 'IN_TRANSIT', 'PARTIALLY_RECEIVED'])
            ->get([
                'line.id',
                'line.company_id',
                'line.plant_id',
                'line.sku_id',
                'line.fg_lot_id',
                'line.uom_code',
            ]);

        foreach ($lines as $line) {
            $owner = DB::table('inventory_owners')
                ->where('company_id', $line->company_id)
                ->where('owner_type', 'COMPANY')
                ->where('status', 'ACTIVE')
                ->orderBy('code')
                ->first(['id']);
            if (! $owner) {
                continue;
            }

            $location = DB::table('locations')
                ->where('company_id', $line->company_id)
                ->where('plant_id', $line->plant_id)
                ->where('location_type', 'RETURN_QUARANTINE')
                ->where('status', 'ACTIVE')
                ->orderBy('code')
                ->first(['id']);

            if (! $location) {
                $locationId = (string) Str::uuid();
                DB::table('locations')->insert([
                    'id' => $locationId,
                    'company_id' => $line->company_id,
                    'plant_id' => $line->plant_id,
                    'code' => 'SYS-RET-QUAR',
                    'name' => 'Return Quarantine',
                    'description' => 'System-provisioned quarantine route for unsold-return receipts.',
                    'location_type' => 'RETURN_QUARANTINE',
                    'parent_location_id' => null,
                    'status' => 'ACTIVE',
                    'record_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $location = (object) ['id' => $locationId];
            }

            $position = DB::table('stock_positions')
                ->where('company_id', $line->company_id)
                ->where('plant_id', $line->plant_id)
                ->where('item_id', $line->sku_id)
                ->where('inventory_owner_id', $owner->id)
                ->where('location_id', $location->id)
                ->where('quality_status', 'RETURN_QUARANTINE')
                ->where('uom_code', $line->uom_code)
                ->when(
                    $line->fg_lot_id,
                    fn ($query) => $query->where('lot_id', $line->fg_lot_id),
                    fn ($query) => $query->whereNull('lot_id'),
                )
                ->first(['id']);

            if (! $position) {
                $positionId = (string) Str::uuid();
                DB::table('stock_positions')->insert([
                    'id' => $positionId,
                    'company_id' => $line->company_id,
                    'plant_id' => $line->plant_id,
                    'item_id' => $line->sku_id,
                    'lot_id' => $line->fg_lot_id,
                    'owner_party_id' => null,
                    'inventory_owner_id' => $owner->id,
                    'location_id' => $location->id,
                    'quality_status' => 'RETURN_QUARANTINE',
                    'quantity_base' => 0,
                    'reserved_quantity_base' => 0,
                    'uom_code' => $line->uom_code,
                    'record_version' => 1,
                    'status_reason' => 'Audit remediation: provisioned for pending unsold return.',
                    'status_changed_at' => now(),
                    'status_changed_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $position = (object) ['id' => $positionId];
            }

            DB::table('unsold_return_lines')->where('id', $line->id)->update([
                'return_position_id' => $position->id,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $positionIds = DB::table('stock_positions')
            ->where('status_reason', 'Audit remediation: provisioned for pending unsold return.')
            ->where('quantity_base', 0)
            ->where('reserved_quantity_base', 0)
            ->pluck('id');

        if ($positionIds->isNotEmpty()) {
            DB::table('unsold_return_lines')->whereIn('return_position_id', $positionIds)->update([
                'return_position_id' => null,
                'updated_at' => now(),
            ]);
            DB::table('stock_positions')->whereIn('id', $positionIds)->delete();
        }

        $locations = DB::table('locations')
            ->where('code', 'SYS-RET-QUAR')
            ->where('description', 'System-provisioned quarantine route for unsold-return receipts.')
            ->get(['id']);

        foreach ($locations as $location) {
            $used = DB::table('stock_positions')->where('location_id', $location->id)->exists();
            if (! $used) {
                DB::table('locations')->where('id', $location->id)->delete();
            }
        }
    }
};
