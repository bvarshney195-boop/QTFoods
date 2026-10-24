<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_quality_statuses', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->string('name', 96);
            $table->string('availability_bucket', 16);
            $table->boolean('is_reservable')->default(false);
            $table->boolean('is_system')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        $this->seedQualityStatuses();

        Schema::create('inventory_owners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('party_id')->nullable();
            $table->string('code', 64);
            $table->string('name');
            $table->string('owner_type', 16);
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->uuid('status_changed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code'], 'inventory_owners_scope_code_unique');
            $table->unique(['company_id', 'party_id'], 'inventory_owners_scope_party_unique');
            $table->unique(['id', 'company_id'], 'inventory_owners_id_company_unique');
            $table->index(['company_id', 'status', 'owner_type'], 'inventory_owners_scope_status_type_index');
            $table->foreign('company_id', 'inventory_owners_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'inventory_owners_party_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('status_changed_by', 'inventory_owners_status_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
        $this->backfillInventoryOwners();

        Schema::table('plants', function (Blueprint $table) {
            $table->unique(['id', 'company_id'], 'plants_id_company_unique');
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->unique(['id', 'company_id', 'plant_id'], 'locations_id_scope_unique');
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->uuid('supplier_party_id')->nullable()->after('item_id');
            $table->string('origin_type', 24)->default('OPENING')->after('supplier_lot_code');
            $table->string('status', 16)->default('ACTIVE')->after('expiry_date');
            $table->text('notes')->nullable()->after('status');
            $table->unsignedBigInteger('record_version')->default(1)->after('notes');
            $table->text('status_reason')->nullable()->after('record_version');
            $table->timestampTz('status_changed_at')->nullable()->after('status_reason');
            $table->uuid('status_changed_by')->nullable()->after('status_changed_at');

            $table->unique(['id', 'company_id'], 'lots_id_company_unique');
            $table->index(['company_id', 'item_id', 'status', 'expiry_date'], 'lots_scope_item_status_expiry_index');
            $table->foreign('company_id', 'lots_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'], 'lots_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['supplier_party_id', 'company_id'], 'lots_supplier_party_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('status_changed_by', 'lots_status_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('stock_positions', function (Blueprint $table) {
            $table->uuid('inventory_owner_id')->nullable()->after('owner_party_id');
            $table->decimal('reserved_quantity_base', 20, 6)->default(0)->after('quantity_base');
            $table->text('status_reason')->nullable()->after('record_version');
            $table->timestampTz('status_changed_at')->nullable()->after('status_reason');
            $table->uuid('status_changed_by')->nullable()->after('status_changed_at');
        });
        $this->backfillPositionOwners();
        Schema::table('stock_positions', function (Blueprint $table) {
            $table->unique(['id', 'company_id', 'plant_id'], 'stock_positions_id_scope_unique');
            $table->unique(
                ['company_id', 'plant_id', 'item_id', 'lot_id', 'inventory_owner_id', 'location_id', 'quality_status', 'uom_code'],
                'stock_positions_coordinate_unique',
            );
            $table->index(['company_id', 'plant_id', 'quality_status', 'item_id'], 'stock_positions_scope_quality_item_index');
            $table->foreign('company_id', 'stock_positions_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'stock_positions_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'], 'stock_positions_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['lot_id', 'company_id'], 'stock_positions_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['inventory_owner_id', 'company_id'], 'stock_positions_owner_fk')
                ->references(['id', 'company_id'])->on('inventory_owners')->restrictOnDelete();
            $table->foreign(['owner_party_id', 'company_id'], 'stock_positions_owner_party_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign(['location_id', 'company_id', 'plant_id'], 'stock_positions_location_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('locations')->restrictOnDelete();
            $table->foreign('quality_status', 'stock_positions_quality_status_fk')
                ->references('code')->on('inventory_quality_statuses')->restrictOnDelete();
            $table->foreign('uom_code', 'stock_positions_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('status_changed_by', 'stock_positions_status_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('stock_position_id');
            $table->string('reservation_number', 80);
            $table->decimal('quantity_base', 20, 6);
            $table->string('status', 16)->default('ACTIVE');
            $table->string('purpose', 255);
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'reservation_number'], 'stock_reservations_scope_number_unique');
            $table->index(['stock_position_id', 'status'], 'stock_reservations_position_status_index');
            $table->index(['company_id', 'plant_id', 'status'], 'stock_reservations_scope_status_index');
            $table->foreign('company_id', 'stock_reservations_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'stock_reservations_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['stock_position_id', 'company_id', 'plant_id'], 'stock_reservations_position_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('created_by', 'stock_reservations_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by', 'stock_reservations_releaser_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('company_id', 'stock_movements_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'stock_movements_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['from_position_id', 'company_id', 'plant_id'], 'stock_movements_from_position_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign(['to_position_id', 'company_id', 'plant_id'], 'stock_movements_to_position_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('uom_code', 'stock_movements_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('actor_id', 'stock_movements_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        $this->addPostgresConstraints();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'inventory_quality_statuses_availability_check' => 'inventory_quality_statuses',
                'inventory_owners_type_check' => 'inventory_owners',
                'inventory_owners_status_check' => 'inventory_owners',
                'inventory_owners_party_check' => 'inventory_owners',
                'inventory_owners_version_check' => 'inventory_owners',
                'lots_origin_check' => 'lots',
                'lots_status_check' => 'lots',
                'lots_dates_check' => 'lots',
                'lots_version_check' => 'lots',
                'stock_positions_quantity_check' => 'stock_positions',
                'stock_positions_version_check' => 'stock_positions',
                'stock_reservations_quantity_check' => 'stock_reservations',
                'stock_reservations_status_check' => 'stock_reservations',
                'stock_reservations_lifecycle_check' => 'stock_reservations',
                'stock_reservations_version_check' => 'stock_reservations',
                'stock_movements_quantity_check' => 'stock_movements',
            ] as $constraint => $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            }
            DB::statement('ALTER TABLE stock_positions ALTER COLUMN inventory_owner_id DROP NOT NULL');
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign('stock_movements_actor_fk');
            $table->dropForeign('stock_movements_uom_fk');
            $table->dropForeign('stock_movements_to_position_fk');
            $table->dropForeign('stock_movements_from_position_fk');
            $table->dropForeign('stock_movements_plant_fk');
            $table->dropForeign('stock_movements_company_fk');
        });

        Schema::dropIfExists('stock_reservations');

        Schema::table('stock_positions', function (Blueprint $table) {
            $table->dropForeign('stock_positions_status_actor_fk');
            $table->dropForeign('stock_positions_uom_fk');
            $table->dropForeign('stock_positions_quality_status_fk');
            $table->dropForeign('stock_positions_location_fk');
            $table->dropForeign('stock_positions_owner_party_fk');
            $table->dropForeign('stock_positions_owner_fk');
            $table->dropForeign('stock_positions_lot_fk');
            $table->dropForeign('stock_positions_item_fk');
            $table->dropForeign('stock_positions_plant_fk');
            $table->dropForeign('stock_positions_company_fk');
            $table->dropIndex('stock_positions_scope_quality_item_index');
            $table->dropUnique('stock_positions_coordinate_unique');
            $table->dropUnique('stock_positions_id_scope_unique');
            $table->dropColumn([
                'inventory_owner_id', 'reserved_quantity_base', 'status_reason',
                'status_changed_at', 'status_changed_by',
            ]);
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->dropForeign('lots_status_actor_fk');
            $table->dropForeign('lots_supplier_party_fk');
            $table->dropForeign('lots_item_fk');
            $table->dropForeign('lots_company_fk');
            $table->dropIndex('lots_scope_item_status_expiry_index');
            $table->dropUnique('lots_id_company_unique');
            $table->dropColumn([
                'supplier_party_id', 'origin_type', 'status', 'notes', 'record_version',
                'status_reason', 'status_changed_at', 'status_changed_by',
            ]);
        });

        Schema::table('locations', fn (Blueprint $table) => $table->dropUnique('locations_id_scope_unique'));
        Schema::table('plants', fn (Blueprint $table) => $table->dropUnique('plants_id_company_unique'));
        Schema::dropIfExists('inventory_owners');
        Schema::dropIfExists('inventory_quality_statuses');
    }

    private function seedQualityStatuses(): void
    {
        $now = now();
        foreach ([
            ['RELEASED', 'Released', 'AVAILABLE', true, 10],
            ['QUALITY_HOLD', 'Quality hold', 'BLOCKED', false, 20],
            ['BLOCKED', 'Blocked', 'BLOCKED', false, 30],
            ['RETURN_QUARANTINE', 'Return quarantine', 'BLOCKED', false, 40],
            ['REPACK_HOLD', 'Repack hold', 'BLOCKED', false, 50],
            ['REWORK_HOLD', 'Rework hold', 'BLOCKED', false, 60],
            ['REJECTED', 'Rejected', 'BLOCKED', false, 70],
            ['EXPIRED', 'Expired', 'BLOCKED', false, 80],
        ] as [$code, $name, $bucket, $reservable, $sort]) {
            DB::table('inventory_quality_statuses')->insert([
                'code' => $code,
                'name' => $name,
                'availability_bucket' => $bucket,
                'is_reservable' => $reservable,
                'is_system' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function backfillInventoryOwners(): void
    {
        $now = now();
        $companyIds = DB::table('stock_positions')->select('company_id')->distinct()->pluck('company_id');
        foreach ($companyIds as $companyId) {
            $company = DB::table('companies')->where('id', $companyId)->first(['display_name']);
            DB::table('inventory_owners')->insertOrIgnore([
                'id' => (string) $companyId,
                'company_id' => (string) $companyId,
                'party_id' => null,
                'code' => 'OWN',
                'name' => ($company?->display_name ?? 'Company').' owned stock',
                'owner_type' => 'COMPANY',
                'status' => 'ACTIVE',
                'record_version' => 1,
                'status_reason' => 'Created while migrating existing stock.',
                'status_changed_at' => $now,
                'status_changed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $partyOwners = DB::table('stock_positions')->whereNotNull('owner_party_id')
            ->select(['company_id', 'owner_party_id'])->distinct()->get();
        foreach ($partyOwners as $owner) {
            $party = DB::table('parties')->where('id', $owner->owner_party_id)
                ->where('company_id', $owner->company_id)->first(['code', 'display_name']);
            if (! $party) {
                continue;
            }
            DB::table('inventory_owners')->insertOrIgnore([
                'id' => (string) $owner->owner_party_id,
                'company_id' => (string) $owner->company_id,
                'party_id' => (string) $owner->owner_party_id,
                'code' => 'PTY-'.substr(str_replace('-', '', (string) $owner->owner_party_id), 0, 16),
                'name' => $party->display_name,
                'owner_type' => 'PARTY',
                'status' => 'ACTIVE',
                'record_version' => 1,
                'status_reason' => 'Created while migrating existing party-owned stock.',
                'status_changed_at' => $now,
                'status_changed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function backfillPositionOwners(): void
    {
        foreach (DB::table('stock_positions')->get(['id', 'company_id', 'owner_party_id']) as $position) {
            DB::table('stock_positions')->where('id', $position->id)->update([
                'inventory_owner_id' => (string) ($position->owner_party_id ?: $position->company_id),
            ]);
        }
    }

    private function addPostgresConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stock_positions ALTER COLUMN inventory_owner_id SET NOT NULL');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS inventory_owners_single_company_owner_unique ON inventory_owners (company_id) WHERE owner_type = 'COMPANY'");
        foreach ([
            "ALTER TABLE inventory_quality_statuses ADD CONSTRAINT inventory_quality_statuses_availability_check CHECK (availability_bucket IN ('AVAILABLE', 'BLOCKED'))",
            "ALTER TABLE inventory_owners ADD CONSTRAINT inventory_owners_type_check CHECK (owner_type IN ('COMPANY', 'PARTY'))",
            "ALTER TABLE inventory_owners ADD CONSTRAINT inventory_owners_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'INACTIVE'))",
            "ALTER TABLE inventory_owners ADD CONSTRAINT inventory_owners_party_check CHECK ((owner_type = 'COMPANY' AND party_id IS NULL) OR (owner_type = 'PARTY' AND party_id IS NOT NULL))",
            'ALTER TABLE inventory_owners ADD CONSTRAINT inventory_owners_version_check CHECK (record_version >= 1)',
            "ALTER TABLE lots ADD CONSTRAINT lots_origin_check CHECK (origin_type IN ('PURCHASE', 'PRODUCTION', 'RETURN', 'OPENING'))",
            "ALTER TABLE lots ADD CONSTRAINT lots_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'CLOSED', 'RECALLED'))",
            'ALTER TABLE lots ADD CONSTRAINT lots_dates_check CHECK (expiry_date IS NULL OR manufacture_date IS NULL OR expiry_date >= manufacture_date)',
            'ALTER TABLE lots ADD CONSTRAINT lots_version_check CHECK (record_version >= 1)',
            'ALTER TABLE stock_positions ADD CONSTRAINT stock_positions_quantity_check CHECK (quantity_base >= 0 AND reserved_quantity_base >= 0 AND reserved_quantity_base <= quantity_base)',
            'ALTER TABLE stock_positions ADD CONSTRAINT stock_positions_version_check CHECK (record_version >= 1)',
            'ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_check CHECK (quantity_base > 0)',
            "ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_check CHECK (status IN ('ACTIVE', 'RELEASED'))",
            "ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_lifecycle_check CHECK ((status = 'ACTIVE' AND released_at IS NULL AND released_by IS NULL AND release_reason IS NULL) OR (status = 'RELEASED' AND released_at IS NOT NULL AND released_by IS NOT NULL AND release_reason IS NOT NULL))",
            'ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_version_check CHECK (record_version >= 1)',
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check CHECK (quantity_base > 0)',
        ] as $statement) {
            DB::statement($statement);
        }
    }
};
