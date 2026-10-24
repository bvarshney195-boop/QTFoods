<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id')->nullable();
            $table->enum('kind', ['APPROVAL', 'TASK', 'EXCEPTION']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['URGENT', 'HIGH', 'NORMAL', 'LOW'])->default('NORMAL');
            $table->enum('status', ['OPEN', 'COMPLETED', 'CANCELLED'])->default('OPEN');
            $table->uuid('assigned_user_id')->nullable();
            $table->string('required_permission', 128)->nullable();
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('target_screen_code', 32)->nullable();
            $table->uuid('target_record_id')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->text('completion_note')->nullable();
            $table->uuid('created_by');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();

            $table->index(
                ['company_id', 'plant_id', 'status', 'due_at'],
                'work_items_scope_status_due_idx'
            );
            $table->index(
                ['assigned_user_id', 'status'],
                'work_items_assignee_status_idx'
            );
            $table->index(
                ['required_permission', 'status'],
                'work_items_permission_status_idx'
            );
            $table->unique(['source_type', 'source_id'], 'work_items_source_unique');

            $table->foreign('company_id', 'work_items_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('plant_id', 'work_items_plant_fk')
                ->references('id')->on('plants')->restrictOnDelete();
            $table->foreign('assigned_user_id', 'work_items_assignee_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('completed_by', 'work_items_completed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', 'work_items_created_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        $this->backfillApprovals();
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
    }

    private function backfillApprovals(): void
    {
        DB::table('approval_requests')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $approval): void {
                $createdAt = CarbonImmutable::parse((string) $approval->created_at);
                $decision = $approval->status === 'PENDING'
                    ? null
                    : DB::table('approval_decisions')
                        ->where('approval_request_id', $approval->id)
                        ->latest('created_at')
                        ->first();
                $isPending = $approval->status === 'PENDING';

                DB::table('work_items')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $approval->company_id,
                    'plant_id' => $approval->plant_id,
                    'kind' => 'APPROVAL',
                    'title' => $this->approvalTitle((string) $approval->rule_code),
                    'description' => 'Review the controlled transaction and record an approval decision.',
                    'priority' => 'HIGH',
                    'status' => $isPending ? 'OPEN' : 'COMPLETED',
                    'assigned_user_id' => null,
                    'required_permission' => $this->approvalPermission((string) $approval->rule_code),
                    'source_type' => 'approval_request',
                    'source_id' => $approval->id,
                    'target_screen_code' => $this->approvalScreen((string) $approval->entity_type),
                    'target_record_id' => $approval->entity_id,
                    'due_at' => $createdAt->addHours(24),
                    'completed_at' => $isPending ? null : ($decision->created_at ?? $approval->updated_at),
                    'completed_by' => $decision->reviewer_id ?? null,
                    'completion_note' => $isPending ? null : 'Approval '.$approval->status.'.',
                    'created_by' => $approval->maker_id,
                    'record_version' => 1,
                    'created_at' => $approval->created_at,
                    'updated_at' => $approval->updated_at,
                ]);
            });
    }

    private function approvalTitle(string $ruleCode): string
    {
        return match ($ruleCode) {
            'UNSOLD_RETURN_LOSS_APPROVAL' => 'Review unsold return loss disposition',
            default => 'Review '.Str::lower(Str::headline($ruleCode)),
        };
    }

    private function approvalPermission(string $ruleCode): ?string
    {
        return match ($ruleCode) {
            'UNSOLD_RETURN_LOSS_APPROVAL' => 'ACTION:RET-UNSOLD:APPROVE',
            default => null,
        };
    }

    private function approvalScreen(string $entityType): ?string
    {
        return match ($entityType) {
            'unsold_return_loss' => 'RET-UNSOLD',
            default => null,
        };
    }
};
