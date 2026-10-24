<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const GLOBAL_RULE_ID = '00000000-0000-4000-8000-000000001401';
    private const STANDARD_BAND_ID = '00000000-0000-4000-8000-000000001501';
    private const HIGH_BAND_ID = '00000000-0000-4000-8000-000000001502';

    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('plant_id')->nullable();
            $table->string('scope_key', 80);
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('entity_type', 80);
            $table->string('authority_metric', 64);
            $table->string('authority_uom', 32);
            $table->string('status', 32)->default('ACTIVE');
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['scope_key', 'code'], 'approval_rules_scope_code_unique');
            $table->index(
                ['company_id', 'plant_id', 'code', 'status'],
                'approval_rules_resolution_index'
            );
            $table->foreign('company_id', 'approval_rules_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('plant_id', 'approval_rules_plant_fk')
                ->references('id')->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'approval_rules_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('approval_rule_bands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('approval_rule_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('name');
            $table->decimal('minimum_value', 20, 6)->default(0);
            $table->decimal('maximum_value', 20, 6)->nullable();
            $table->string('required_permission', 128);
            $table->string('escalation_permission', 128);
            $table->string('work_priority', 16)->default('HIGH');
            $table->unsignedSmallInteger('due_hours')->default(24);
            $table->unsignedSmallInteger('escalate_after_hours')->default(24);
            $table->timestampsTz();

            $table->unique(['approval_rule_id', 'sequence'], 'approval_rule_bands_sequence_unique');
            $table->index(['approval_rule_id', 'minimum_value'], 'approval_rule_bands_value_index');
            $table->foreign('approval_rule_id', 'approval_rule_bands_rule_fk')
                ->references('id')->on('approval_rules')->cascadeOnDelete();
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('delegator_id');
            $table->uuid('delegate_id');
            $table->string('permission_code', 128);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to');
            $table->text('reason');
            $table->string('status', 32)->default('ACTIVE');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->uuid('created_by');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();

            $table->index(
                ['company_id', 'plant_id', 'delegate_id', 'status', 'effective_from', 'effective_to'],
                'approval_delegations_delegate_window_index'
            );
            $table->index(
                ['company_id', 'plant_id', 'delegator_id', 'permission_code'],
                'approval_delegations_delegator_permission_index'
            );
            $table->foreign('company_id', 'approval_delegations_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('plant_id', 'approval_delegations_plant_fk')
                ->references('id')->on('plants')->restrictOnDelete();
            $table->foreign('delegator_id', 'approval_delegations_delegator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('delegate_id', 'approval_delegations_delegate_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by', 'approval_delegations_revoker_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by', 'approval_delegations_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->uuid('approval_rule_id')->nullable();
            $table->unsignedBigInteger('approval_rule_version')->nullable();
            $table->uuid('approval_rule_band_id')->nullable();
            $table->string('rule_name_snapshot')->nullable();
            $table->string('band_name_snapshot')->nullable();
            $table->decimal('authority_value', 20, 6)->nullable();
            $table->string('authority_uom', 32)->nullable();
            $table->string('required_permission', 128)->nullable();
            $table->string('escalation_permission', 128)->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('escalate_at')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->unsignedSmallInteger('escalation_count')->default(0);
            $table->uuid('resubmission_of_id')->nullable();
            $table->unsignedSmallInteger('submission_number')->default(1);

            $table->index(
                ['company_id', 'plant_id', 'status', 'escalate_at'],
                'approval_requests_escalation_index'
            );
            $table->index('resubmission_of_id', 'approval_requests_resubmission_index');
            $table->foreign('approval_rule_id', 'approval_requests_rule_fk')
                ->references('id')->on('approval_rules')->restrictOnDelete();
            $table->foreign('resubmission_of_id', 'approval_requests_resubmission_fk')
                ->references('id')->on('approval_requests')->restrictOnDelete();
        });

        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->string('authority_source', 32)->nullable();
            $table->string('authority_permission', 128)->nullable();
            $table->uuid('delegation_id')->nullable();
            $table->foreign('delegation_id', 'approval_decisions_delegation_fk')
                ->references('id')->on('approval_delegations')->restrictOnDelete();
        });

        $this->createGlobalRule();
        $this->backfillApprovalSnapshots();
        $this->addChecks();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'approval_rules_status_check' => 'approval_rules',
                'approval_rules_scope_check' => 'approval_rules',
                'approval_rule_bands_values_check' => 'approval_rule_bands',
                'approval_rule_bands_priority_check' => 'approval_rule_bands',
                'approval_rule_bands_hours_check' => 'approval_rule_bands',
                'approval_delegations_status_check' => 'approval_delegations',
                'approval_delegations_people_check' => 'approval_delegations',
                'approval_delegations_window_check' => 'approval_delegations',
            ] as $constraint => $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->dropForeign('approval_decisions_delegation_fk');
            $table->dropColumn(['authority_source', 'authority_permission', 'delegation_id']);
        });
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropForeign('approval_requests_resubmission_fk');
            $table->dropForeign('approval_requests_rule_fk');
            $table->dropIndex('approval_requests_resubmission_index');
            $table->dropIndex('approval_requests_escalation_index');
            $table->dropColumn([
                'approval_rule_id', 'approval_rule_version', 'approval_rule_band_id',
                'rule_name_snapshot', 'band_name_snapshot', 'authority_value', 'authority_uom',
                'required_permission', 'escalation_permission', 'due_at', 'escalate_at',
                'escalated_at', 'escalation_count', 'resubmission_of_id', 'submission_number',
            ]);
        });

        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_rule_bands');
        Schema::dropIfExists('approval_rules');
    }

    private function createGlobalRule(): void
    {
        $now = now();
        DB::table('approval_rules')->insert([
            'id' => self::GLOBAL_RULE_ID,
            'company_id' => null,
            'plant_id' => null,
            'scope_key' => 'GLOBAL',
            'code' => 'UNSOLD_RETURN_LOSS_APPROVAL',
            'name' => 'Unsold return loss approval',
            'description' => 'Protected fallback policy for quantity-based unsold-return loss decisions.',
            'entity_type' => 'unsold_return_loss',
            'authority_metric' => 'DESTROY_QUANTITY',
            'authority_uom' => 'BASE',
            'status' => 'ACTIVE',
            'is_system' => true,
            'record_version' => 1,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('approval_rule_bands')->insert([
            [
                'id' => self::STANDARD_BAND_ID,
                'approval_rule_id' => self::GLOBAL_RULE_ID,
                'sequence' => 1,
                'name' => 'Standard loss authority',
                'minimum_value' => 0,
                'maximum_value' => 100,
                'required_permission' => 'ACTION:RET-UNSOLD:APPROVE',
                'escalation_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
                'work_priority' => 'HIGH',
                'due_hours' => 24,
                'escalate_after_hours' => 24,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::HIGH_BAND_ID,
                'approval_rule_id' => self::GLOBAL_RULE_ID,
                'sequence' => 2,
                'name' => 'High loss authority',
                'minimum_value' => 100,
                'maximum_value' => null,
                'required_permission' => 'ACTION:RET-UNSOLD:APPROVE-HIGH',
                'escalation_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
                'work_priority' => 'URGENT',
                'due_hours' => 12,
                'escalate_after_hours' => 12,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    private function backfillApprovalSnapshots(): void
    {
        $previous = [];
        DB::table('approval_requests')->orderBy('created_at')->orderBy('id')->get()
            ->each(function (object $approval) use (&$previous): void {
                $value = $approval->entity_type === 'unsold_return_loss'
                    ? (string) DB::table('unsold_return_lines')
                        ->where('return_case_id', $approval->entity_id)
                        ->sum('destroy_quantity')
                    : '0';
                $high = bccomp($value, '100', 6) >= 0;
                $createdAt = CarbonImmutable::parse((string) $approval->created_at);
                $lineageKey = $approval->entity_type.'|'.$approval->entity_id.'|'.$approval->rule_code;
                $prior = $previous[$lineageKey] ?? null;
                $submissionNumber = $prior === null ? 1 : $prior['number'] + 1;

                DB::table('approval_requests')->where('id', $approval->id)->update([
                    'approval_rule_id' => self::GLOBAL_RULE_ID,
                    'approval_rule_version' => 1,
                    'approval_rule_band_id' => $high ? self::HIGH_BAND_ID : self::STANDARD_BAND_ID,
                    'rule_name_snapshot' => 'Unsold return loss approval',
                    'band_name_snapshot' => $high ? 'High loss authority' : 'Standard loss authority',
                    'authority_value' => $value,
                    'authority_uom' => 'BASE',
                    'required_permission' => $high
                        ? 'ACTION:RET-UNSOLD:APPROVE-HIGH'
                        : 'ACTION:RET-UNSOLD:APPROVE',
                    'escalation_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
                    'due_at' => $createdAt->addHours($high ? 12 : 24),
                    'escalate_at' => $createdAt->addHours($high ? 12 : 24),
                    'resubmission_of_id' => ($prior['status'] ?? null) === 'REJECTED'
                        ? $prior['id']
                        : null,
                    'submission_number' => $submissionNumber,
                ]);

                DB::table('work_items')
                    ->where('source_type', 'approval_request')
                    ->where('source_id', $approval->id)
                    ->update([
                        'required_permission' => $high
                            ? 'ACTION:RET-UNSOLD:APPROVE-HIGH'
                            : 'ACTION:RET-UNSOLD:APPROVE',
                        'priority' => $high ? 'URGENT' : 'HIGH',
                        'due_at' => $createdAt->addHours($high ? 12 : 24),
                    ]);

                $previous[$lineageKey] = [
                    'id' => (string) $approval->id,
                    'number' => $submissionNumber,
                    'status' => (string) $approval->status,
                ];
            });
    }

    private function addChecks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            "ALTER TABLE approval_rules ADD CONSTRAINT approval_rules_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))",
            "ALTER TABLE approval_rules ADD CONSTRAINT approval_rules_scope_check CHECK ((company_id IS NULL AND plant_id IS NULL AND scope_key = 'GLOBAL' AND is_system) OR (company_id IS NOT NULL AND scope_key <> 'GLOBAL' AND NOT is_system))",
            'ALTER TABLE approval_rule_bands ADD CONSTRAINT approval_rule_bands_values_check CHECK (minimum_value >= 0 AND (maximum_value IS NULL OR maximum_value > minimum_value))',
            "ALTER TABLE approval_rule_bands ADD CONSTRAINT approval_rule_bands_priority_check CHECK (work_priority IN ('URGENT', 'HIGH', 'NORMAL', 'LOW'))",
            'ALTER TABLE approval_rule_bands ADD CONSTRAINT approval_rule_bands_hours_check CHECK (due_hours > 0 AND escalate_after_hours > 0)',
            "ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_status_check CHECK (status IN ('ACTIVE', 'REVOKED'))",
            'ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_people_check CHECK (delegator_id <> delegate_id)',
            'ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_window_check CHECK (effective_to > effective_from)',
        ] as $statement) {
            DB::statement($statement);
        }
    }
};
