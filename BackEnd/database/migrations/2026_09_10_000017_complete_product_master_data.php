<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->uuid('status_changed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code'], 'brands_scope_code_unique');
            $table->unique(['id', 'company_id'], 'brands_id_company_unique');
            $table->index(['company_id', 'status', 'name'], 'brands_scope_status_name_index');
            $table->foreign('company_id', 'brands_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('status_changed_by', 'brands_status_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('brand_id')->nullable();
            $table->string('code', 64);
            $table->string('name');
            $table->string('item_type', 32);
            $table->string('base_uom', 16);
            $table->text('description')->nullable();
            $table->unsignedInteger('shelf_life_days')->nullable();
            $table->boolean('lot_controlled')->default(true);
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->uuid('status_changed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code'], 'catalog_items_scope_code_unique');
            $table->unique(['id', 'company_id'], 'catalog_items_id_company_unique');
            $table->index(['company_id', 'status', 'item_type'], 'catalog_items_scope_status_type_index');
            $table->index(['company_id', 'brand_id'], 'catalog_items_scope_brand_index');
            $table->foreign('company_id', 'catalog_items_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['brand_id', 'company_id'], 'catalog_items_brand_fk')
                ->references(['id', 'company_id'])->on('brands')->restrictOnDelete();
            $table->foreign('base_uom', 'catalog_items_base_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('status_changed_by', 'catalog_items_status_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->uuid('catalog_item_id')->nullable()->after('company_id');
            $table->string('barcode', 80)->nullable()->after('name');
            $table->text('description')->nullable()->after('barcode');
            $table->decimal('pack_quantity', 20, 6)->default(1)->after('base_uom');
            $table->string('pack_uom_code', 16)->nullable()->after('pack_quantity');
            $table->text('status_reason')->nullable()->after('record_version');
            $table->timestampTz('status_changed_at')->nullable()->after('status_reason');
            $table->uuid('status_changed_by')->nullable()->after('status_changed_at');

            $table->unique(['id', 'company_id'], 'items_id_company_unique');
            $table->unique(['company_id', 'barcode'], 'items_scope_barcode_unique');
            $table->index(['company_id', 'catalog_item_id', 'status'], 'items_scope_catalog_status_index');
            $table->foreign('company_id', 'items_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['catalog_item_id', 'company_id'], 'items_catalog_item_fk')
                ->references(['id', 'company_id'])->on('catalog_items')->restrictOnDelete();
            $table->foreign('base_uom', 'items_base_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('pack_uom_code', 'items_pack_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('status_changed_by', 'items_status_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('brand_agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('brand_id');
            $table->uuid('party_id');
            $table->string('agreement_number', 80);
            $table->string('agreement_type', 32);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->char('currency_code', 3)->default('INR');
            $table->decimal('minimum_commitment', 20, 2)->default(0);
            $table->string('status', 32)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'agreement_number'], 'brand_agreements_scope_number_unique');
            $table->index(['brand_id', 'status'], 'brand_agreements_brand_status_index');
            $table->index(['company_id', 'party_id'], 'brand_agreements_scope_party_index');
            $table->foreign('company_id', 'brand_agreements_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['brand_id', 'company_id'], 'brand_agreements_brand_fk')
                ->references(['id', 'company_id'])->on('brands')->cascadeOnDelete();
            $table->foreign(['party_id', 'company_id'], 'brand_agreements_party_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
        });

        Schema::create('item_uom_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('catalog_item_id');
            $table->string('from_uom_code', 16);
            $table->string('to_uom_code', 16);
            $table->decimal('multiplier', 20, 9);
            $table->string('rounding_mode', 16)->default('HALF_UP');
            $table->timestampsTz();

            $table->unique(['catalog_item_id', 'from_uom_code', 'to_uom_code'], 'item_uom_conversion_unique');
            $table->index(['company_id', 'catalog_item_id'], 'item_uom_conversions_scope_item_index');
            $table->foreign('company_id', 'item_uom_conversions_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['catalog_item_id', 'company_id'], 'item_uom_conversions_item_fk')
                ->references(['id', 'company_id'])->on('catalog_items')->cascadeOnDelete();
            $table->foreign('from_uom_code', 'item_uom_conversions_from_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('to_uom_code', 'item_uom_conversions_to_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('sku_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('sku_id');
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('uom_code', 16);
            $table->decimal('quantity', 20, 6);
            $table->string('barcode', 80)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->unique(['company_id', 'code'], 'sku_packs_scope_code_unique');
            $table->unique(['company_id', 'barcode'], 'sku_packs_scope_barcode_unique');
            $table->index(['sku_id', 'is_default'], 'sku_packs_sku_default_index');
            $table->foreign('company_id', 'sku_packs_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['sku_id', 'company_id'], 'sku_packs_sku_fk')
                ->references(['id', 'company_id'])->on('items')->cascadeOnDelete();
            $table->foreign('uom_code', 'sku_packs_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('output_sku_id');
            $table->decimal('output_quantity', 20, 6);
            $table->string('output_uom_code', 16);
            $table->decimal('yield_percent', 7, 3)->default(100);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->uuid('status_changed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code'], 'recipes_scope_code_unique');
            $table->unique(['id', 'company_id'], 'recipes_id_company_unique');
            $table->index(['company_id', 'output_sku_id', 'status'], 'recipes_scope_output_status_index');
            $table->foreign('company_id', 'recipes_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['output_sku_id', 'company_id'], 'recipes_output_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('output_uom_code', 'recipes_output_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('status_changed_by', 'recipes_status_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('recipe_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('recipe_id');
            $table->uuid('component_sku_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->decimal('waste_percent', 7, 3)->default(0);
            $table->timestampsTz();

            $table->unique(['recipe_id', 'sequence_no'], 'recipe_components_sequence_unique');
            $table->unique(['recipe_id', 'component_sku_id'], 'recipe_components_sku_unique');
            $table->index(['company_id', 'component_sku_id'], 'recipe_components_scope_sku_index');
            $table->foreign('company_id', 'recipe_components_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['recipe_id', 'company_id'], 'recipe_components_recipe_fk')
                ->references(['id', 'company_id'])->on('recipes')->cascadeOnDelete();
            $table->foreign(['component_sku_id', 'company_id'], 'recipe_components_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'recipe_components_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('production_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name');
            $table->uuid('catalog_item_id');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->uuid('status_changed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code'], 'production_routes_scope_code_unique');
            $table->unique(['id', 'company_id'], 'production_routes_id_company_unique');
            $table->index(['company_id', 'catalog_item_id', 'status'], 'production_routes_scope_item_status_index');
            $table->foreign('company_id', 'production_routes_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['catalog_item_id', 'company_id'], 'production_routes_item_fk')
                ->references(['id', 'company_id'])->on('catalog_items')->restrictOnDelete();
            $table->foreign('status_changed_by', 'production_routes_status_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('route_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('route_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->string('name', 160);
            $table->string('work_center_code', 64);
            $table->decimal('setup_minutes', 12, 3)->default(0);
            $table->decimal('run_minutes_per_unit', 12, 6)->default(0);
            $table->text('instructions')->nullable();
            $table->timestampsTz();

            $table->unique(['route_id', 'sequence_no'], 'route_operations_sequence_unique');
            $table->foreign('company_id', 'route_operations_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['route_id', 'company_id'], 'route_operations_route_fk')
                ->references(['id', 'company_id'])->on('production_routes')->cascadeOnDelete();
        });

        Schema::create('quality_specifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name');
            $table->string('target_type', 16);
            $table->uuid('catalog_item_id')->nullable();
            $table->uuid('sku_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('sampling_plan', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->uuid('status_changed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code'], 'quality_specs_scope_code_unique');
            $table->unique(['id', 'company_id'], 'quality_specs_id_company_unique');
            $table->index(['company_id', 'target_type', 'status'], 'quality_specs_scope_target_status_index');
            $table->foreign('company_id', 'quality_specs_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['catalog_item_id', 'company_id'], 'quality_specs_catalog_item_fk')
                ->references(['id', 'company_id'])->on('catalog_items')->restrictOnDelete();
            $table->foreign(['sku_id', 'company_id'], 'quality_specs_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('status_changed_by', 'quality_specs_status_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('quality_spec_parameters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('specification_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('value_type', 16);
            $table->string('uom_code', 16)->nullable();
            $table->decimal('minimum_value', 20, 6)->nullable();
            $table->decimal('target_value', 20, 6)->nullable();
            $table->decimal('maximum_value', 20, 6)->nullable();
            $table->string('text_requirement', 255)->nullable();
            $table->string('test_method', 160)->nullable();
            $table->boolean('is_required')->default(true);
            $table->timestampsTz();

            $table->unique(['specification_id', 'sequence_no'], 'quality_spec_parameters_sequence_unique');
            $table->unique(['specification_id', 'code'], 'quality_spec_parameters_code_unique');
            $table->foreign('company_id', 'quality_spec_parameters_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['specification_id', 'company_id'], 'quality_spec_parameters_spec_fk')
                ->references(['id', 'company_id'])->on('quality_specifications')->cascadeOnDelete();
            $table->foreign('uom_code', 'quality_spec_parameters_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        $this->backfillLegacyStockItems();
        $this->addPostgresConstraints();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['items_status_check', 'items_version_check', 'items_type_check', 'items_pack_quantity_check'] as $constraint) {
                DB::statement("ALTER TABLE items DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        Schema::dropIfExists('quality_spec_parameters');
        Schema::dropIfExists('quality_specifications');
        Schema::dropIfExists('route_operations');
        Schema::dropIfExists('production_routes');
        Schema::dropIfExists('recipe_components');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('sku_packs');
        Schema::dropIfExists('item_uom_conversions');
        Schema::dropIfExists('brand_agreements');

        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign('items_status_actor_fk');
            $table->dropForeign('items_pack_uom_fk');
            $table->dropForeign('items_base_uom_fk');
            $table->dropForeign('items_catalog_item_fk');
            $table->dropForeign('items_company_fk');
            $table->dropIndex('items_scope_catalog_status_index');
            $table->dropUnique('items_scope_barcode_unique');
            $table->dropUnique('items_id_company_unique');
            $table->dropColumn([
                'catalog_item_id', 'barcode', 'description', 'pack_quantity', 'pack_uom_code',
                'status_reason', 'status_changed_at', 'status_changed_by',
            ]);
        });

        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('brands');
    }

    private function backfillLegacyStockItems(): void
    {
        $now = now();
        foreach (DB::table('items')->get() as $item) {
            DB::table('catalog_items')->insertOrIgnore([
                'id' => (string) $item->id,
                'company_id' => (string) $item->company_id,
                'brand_id' => null,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'item_type' => (string) $item->item_type,
                'base_uom' => (string) $item->base_uom,
                'description' => 'Migrated from the legacy stock-item master.',
                'shelf_life_days' => null,
                'lot_controlled' => true,
                'status' => (string) $item->status,
                'record_version' => (int) $item->record_version,
                'status_reason' => null,
                'status_changed_at' => null,
                'status_changed_by' => null,
                'created_at' => $item->created_at ?? $now,
                'updated_at' => $item->updated_at ?? $now,
            ]);
            DB::table('items')->where('id', $item->id)->update([
                'catalog_item_id' => (string) $item->id,
                'pack_quantity' => 1,
                'pack_uom_code' => (string) $item->base_uom,
            ]);
        }
    }

    private function addPostgresConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE items ALTER COLUMN catalog_item_id SET NOT NULL');
        foreach (['brands', 'catalog_items', 'items', 'recipes', 'production_routes', 'quality_specifications'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'INACTIVE'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_version_check CHECK (record_version >= 1)");
        }
        DB::statement("ALTER TABLE catalog_items ADD CONSTRAINT catalog_items_type_check CHECK (item_type IN ('RAW_MATERIAL', 'PACKAGING', 'INTERMEDIATE', 'FINISHED_GOOD', 'SERVICE'))");
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_type_check CHECK (item_type IN ('RAW_MATERIAL', 'PACKAGING', 'INTERMEDIATE', 'FINISHED_GOOD', 'SERVICE'))");
        DB::statement('ALTER TABLE items ADD CONSTRAINT items_pack_quantity_check CHECK (pack_quantity > 0)');
        DB::statement("ALTER TABLE brand_agreements ADD CONSTRAINT brand_agreements_type_check CHECK (agreement_type IN ('LICENSE', 'DISTRIBUTION', 'MANUFACTURING', 'SUPPLY'))");
        DB::statement("ALTER TABLE brand_agreements ADD CONSTRAINT brand_agreements_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'EXPIRED', 'TERMINATED'))");
        DB::statement('ALTER TABLE brand_agreements ADD CONSTRAINT brand_agreements_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        DB::statement('ALTER TABLE brand_agreements ADD CONSTRAINT brand_agreements_commitment_check CHECK (minimum_commitment >= 0)');
        DB::statement("ALTER TABLE brand_agreements ADD CONSTRAINT brand_agreements_currency_check CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE item_uom_conversions ADD CONSTRAINT item_uom_conversions_distinct_check CHECK (from_uom_code <> to_uom_code)');
        DB::statement('ALTER TABLE item_uom_conversions ADD CONSTRAINT item_uom_conversions_multiplier_check CHECK (multiplier > 0)');
        DB::statement("ALTER TABLE item_uom_conversions ADD CONSTRAINT item_uom_conversions_rounding_check CHECK (rounding_mode IN ('HALF_UP', 'UP', 'DOWN', 'NONE'))");
        DB::statement('ALTER TABLE sku_packs ADD CONSTRAINT sku_packs_quantity_check CHECK (quantity > 0)');
        DB::statement('CREATE UNIQUE INDEX sku_packs_default_unique ON sku_packs (sku_id) WHERE is_default');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_output_quantity_check CHECK (output_quantity > 0)');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_yield_check CHECK (yield_percent > 0 AND yield_percent <= 100)');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_dates_check CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
        DB::statement('ALTER TABLE recipe_components ADD CONSTRAINT recipe_components_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE recipe_components ADD CONSTRAINT recipe_components_waste_check CHECK (waste_percent >= 0 AND waste_percent <= 100)');
        DB::statement('ALTER TABLE route_operations ADD CONSTRAINT route_operations_time_check CHECK (setup_minutes >= 0 AND run_minutes_per_unit >= 0)');
        DB::statement("ALTER TABLE quality_specifications ADD CONSTRAINT quality_specs_target_check CHECK ((target_type = 'ITEM' AND catalog_item_id IS NOT NULL AND sku_id IS NULL) OR (target_type = 'SKU' AND catalog_item_id IS NULL AND sku_id IS NOT NULL))");
        DB::statement('ALTER TABLE quality_specifications ADD CONSTRAINT quality_specs_dates_check CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
        DB::statement("ALTER TABLE quality_spec_parameters ADD CONSTRAINT quality_spec_parameters_type_check CHECK (value_type IN ('NUMERIC', 'TEXT', 'BOOLEAN'))");
        DB::statement("ALTER TABLE quality_spec_parameters ADD CONSTRAINT quality_spec_parameters_values_check CHECK ((value_type = 'NUMERIC' AND text_requirement IS NULL AND (minimum_value IS NOT NULL OR target_value IS NOT NULL OR maximum_value IS NOT NULL)) OR (value_type = 'TEXT' AND text_requirement IS NOT NULL AND minimum_value IS NULL AND target_value IS NULL AND maximum_value IS NULL) OR (value_type = 'BOOLEAN' AND text_requirement IS NULL AND minimum_value IS NULL AND target_value IS NULL AND maximum_value IS NULL))");
        DB::statement('ALTER TABLE quality_spec_parameters ADD CONSTRAINT quality_spec_parameters_range_check CHECK (minimum_value IS NULL OR maximum_value IS NULL OR maximum_value >= minimum_value)');
    }
};
