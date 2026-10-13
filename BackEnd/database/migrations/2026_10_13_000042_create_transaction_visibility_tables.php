<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const PERMISSIONS = [
        'DidntShowCheckbox' => [
            'id' => 'd2f799ec-b024-4c3e-8bd4-72ed217d5d11',
            'name' => 'Restrict transaction entries',
            'description' => 'Allows an authorised transaction editor to change operational visibility.',
        ],
        'ShowEntries' => [
            'id' => '424c68b8-e693-4857-b396-d167755c8e5f',
            'name' => 'View restricted transaction entries',
            'description' => 'Includes restricted entries in authorised operational views, searches, and exports.',
        ],
    ];

    public function up(): void
    {
        Schema::create('transaction_visibilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_type', 80);
            $table->uuid('transaction_id');
            $table->string('module', 64);
            $table->uuid('company_id');
            $table->uuid('plant_id')->nullable();
            $table->string('visibility_status', 20)->default('NORMAL');
            $table->uuid('restricted_by')->nullable();
            $table->timestampTz('restricted_at')->nullable();
            $table->text('restriction_reason')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();

            $table->unique(['transaction_type', 'transaction_id'], 'transaction_visibility_identity_unique');
            $table->index(
                ['company_id', 'plant_id', 'visibility_status', 'transaction_type'],
                'transaction_visibility_scope_status_index',
            );
            $table->index('transaction_id', 'transaction_visibility_transaction_index');
            $table->foreign('company_id', 'transaction_visibility_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'transaction_visibility_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('restricted_by', 'transaction_visibility_restrictor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('transaction_visibility_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('visibility_id');
            $table->string('transaction_type', 80);
            $table->uuid('transaction_id');
            $table->string('module', 64);
            $table->string('previous_visibility', 20);
            $table->string('new_visibility', 20);
            $table->uuid('actor_id');
            $table->uuid('company_id');
            $table->uuid('plant_id')->nullable();
            $table->string('change_source', 64);
            $table->text('reason')->nullable();
            $table->uuid('request_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('changed_at');
            $table->timestampTz('created_at');

            $table->index(
                ['transaction_type', 'transaction_id', 'changed_at'],
                'transaction_visibility_events_transaction_index',
            );
            $table->index(
                ['company_id', 'plant_id', 'changed_at'],
                'transaction_visibility_events_scope_index',
            );
            $table->foreign('visibility_id', 'transaction_visibility_events_visibility_fk')
                ->references('id')->on('transaction_visibilities')->restrictOnDelete();
            $table->foreign('actor_id', 'transaction_visibility_events_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('company_id', 'transaction_visibility_events_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'transaction_visibility_events_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
        });

        Schema::table('report_runs', function (Blueprint $table) {
            $table->string('view_classification', 40)->default('AUTHORITATIVE')->after('report_title');
            $table->boolean('includes_restricted_entries')->default(true)->after('view_classification');
            $table->index(
                ['company_id', 'plant_id', 'view_classification'],
                'report_runs_visibility_classification_index',
            );
        });

        $this->addIntegrityControls();
        $this->seedPermissions();
    }

    public function down(): void
    {
        $this->dropIntegrityControls();
        Schema::table('report_runs', function (Blueprint $table) {
            $table->dropIndex('report_runs_visibility_classification_index');
            $table->dropColumn(['view_classification', 'includes_restricted_entries']);
        });
        Schema::dropIfExists('transaction_visibility_events');
        Schema::dropIfExists('transaction_visibilities');

        $permissionIds = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    private function seedPermissions(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $code => $definition) {
            $existingId = DB::table('permissions')->where('code', $code)->value('id');
            DB::table('permissions')->updateOrInsert(['code' => $code], [
                'id' => $existingId ?: $definition['id'],
                'name' => $definition['name'],
                'company_id' => null,
                'description' => $definition['description'],
                'status' => 'ACTIVE',
                'is_system' => true,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function addIntegrityControls(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE transaction_visibilities ADD CONSTRAINT transaction_visibilities_status_check CHECK (visibility_status IN ('NORMAL', 'RESTRICTED'))");
            DB::statement("ALTER TABLE transaction_visibilities ADD CONSTRAINT transaction_visibilities_metadata_check CHECK ((visibility_status = 'NORMAL' AND restricted_by IS NULL AND restricted_at IS NULL AND restriction_reason IS NULL) OR (visibility_status = 'RESTRICTED' AND restricted_by IS NOT NULL AND restricted_at IS NOT NULL))");
            DB::statement('ALTER TABLE transaction_visibilities ADD CONSTRAINT transaction_visibilities_version_check CHECK (record_version >= 1)');
            DB::statement("ALTER TABLE transaction_visibility_events ADD CONSTRAINT transaction_visibility_events_status_check CHECK (previous_visibility IN ('NORMAL', 'RESTRICTED') AND new_visibility IN ('NORMAL', 'RESTRICTED') AND previous_visibility <> new_visibility)");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_transaction_visibility_event_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'transaction_visibility_events are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER transaction_visibility_events_no_update
                    BEFORE UPDATE OR DELETE ON transaction_visibility_events
                    FOR EACH ROW EXECUTE FUNCTION prevent_transaction_visibility_event_mutation();
                SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER transaction_visibility_events_no_update
                BEFORE UPDATE ON transaction_visibility_events
                BEGIN
                    SELECT RAISE(ABORT, 'transaction_visibility_events are immutable');
                END;
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER transaction_visibility_events_no_delete
                BEFORE DELETE ON transaction_visibility_events
                BEGIN
                    SELECT RAISE(ABORT, 'transaction_visibility_events are immutable');
                END;
                SQL);
        }
    }

    private function dropIntegrityControls(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS transaction_visibility_events_no_update ON transaction_visibility_events');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_transaction_visibility_event_mutation()');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS transaction_visibility_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS transaction_visibility_events_no_delete');
        }
    }
};
