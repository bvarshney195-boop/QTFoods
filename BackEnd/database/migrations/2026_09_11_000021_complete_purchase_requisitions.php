<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('requisition_number', 80)->nullable()->after('plant_id');
            $table->uuid('requested_by')->nullable()->after('created_by');
            $table->string('department', 120)->nullable()->after('requested_by');
            $table->text('purpose')->nullable()->after('department');
            $table->date('requested_date')->nullable()->after('purpose');
            $table->date('required_by_date')->nullable()->after('requested_date');
            $table->string('currency', 3)->nullable()->after('required_by_date');
            $table->decimal('estimated_total', 20, 6)->default(0)->after('currency');
            $table->uuid('approval_request_id')->nullable()->after('estimated_total');
            $table->timestampTz('submitted_at')->nullable()->after('approval_request_id');
            $table->uuid('submitted_by')->nullable()->after('submitted_at');
            $table->timestampTz('approved_at')->nullable()->after('submitted_by');
            $table->uuid('approved_by')->nullable()->after('approved_at');
            $table->timestampTz('rejected_at')->nullable()->after('approved_by');
            $table->uuid('rejected_by')->nullable()->after('rejected_at');
            $table->text('rejection_reason')->nullable()->after('rejected_by');
            $table->timestampTz('cancelled_at')->nullable()->after('rejection_reason');
            $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->unique(
                ['company_id', 'plant_id', 'requisition_number'],
                'requisitions_scope_number_unique'
            );
            $table->unique(['id', 'company_id', 'plant_id'], 'requisitions_id_scope_unique');
            $table->unique('approval_request_id', 'requisitions_approval_unique');
            $table->index(
                ['company_id', 'plant_id', 'status', 'required_by_date'],
                'requisitions_scope_status_required_index'
            );
            $table->index(
                ['company_id', 'plant_id', 'requested_by', 'requested_date'],
                'requisitions_scope_requester_date_index'
            );
        });

        Schema::create('requisition_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('requisition_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->string('description');
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->decimal('estimated_unit_cost', 20, 6);
            $table->decimal('estimated_line_total', 20, 6);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['requisition_id', 'line_number'], 'requisition_lines_number_unique');
            $table->unique(['requisition_id', 'item_id'], 'requisition_lines_item_unique');
            $table->index(['company_id', 'plant_id', 'item_id'], 'requisition_lines_scope_item_index');
            $table->foreign(['requisition_id', 'company_id', 'plant_id'], 'requisition_lines_requisition_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('requisitions')->cascadeOnDelete();
            $table->foreign(['item_id', 'company_id'], 'requisition_lines_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'requisition_lines_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
        });

        $this->backfillLegacyShellRows();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'ALTER TABLE requisitions ALTER COLUMN plant_id SET NOT NULL',
            'ALTER TABLE requisitions ALTER COLUMN requisition_number SET NOT NULL',
            'ALTER TABLE requisitions ALTER COLUMN requested_by SET NOT NULL',
            'ALTER TABLE requisitions ALTER COLUMN department SET NOT NULL',
            'ALTER TABLE requisitions ALTER COLUMN purpose SET NOT NULL',
            'ALTER TABLE requisitions ALTER COLUMN requested_date SET NOT NULL',
            'ALTER TABLE requisitions ALTER COLUMN required_by_date SET NOT NULL',
            'ALTER TABLE requisitions ALTER COLUMN currency SET NOT NULL',
            'ALTER TABLE requisitions ALTER COLUMN estimated_total SET NOT NULL',
        ] as $statement) {
            DB::statement($statement);
        }

        foreach ($this->constraints() as [$table, $name, $definition]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }

        $this->createConstraintTriggers();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->dropConstraintTriggers();
            foreach (array_reverse($this->constraints()) as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        $this->removeApprovalArtifacts();
        Schema::dropIfExists('requisition_lines');

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropIndex('requisitions_scope_requester_date_index');
            $table->dropIndex('requisitions_scope_status_required_index');
            $table->dropUnique('requisitions_approval_unique');
            $table->dropUnique('requisitions_id_scope_unique');
            $table->dropUnique('requisitions_scope_number_unique');
            $table->dropColumn([
                'requisition_number', 'requested_by', 'department', 'purpose',
                'requested_date', 'required_by_date', 'currency', 'estimated_total',
                'approval_request_id', 'submitted_at', 'submitted_by', 'approved_at',
                'approved_by', 'rejected_at', 'rejected_by', 'rejection_reason',
                'cancelled_at', 'cancelled_by', 'cancellation_reason',
            ]);
        });
    }

    private function removeApprovalArtifacts(): void
    {
        $approvalIds = DB::table('approval_requests')
            ->where('entity_type', 'purchase_requisition')
            ->pluck('id');

        if ($approvalIds->isEmpty()) {
            return;
        }

        DB::table('requisitions')->whereIn('approval_request_id', $approvalIds)->update([
            'approval_request_id' => null,
        ]);
        DB::table('work_items')
            ->where('source_type', 'approval_request')
            ->whereIn('source_id', $approvalIds)
            ->delete();
        DB::table('approval_decisions')->whereIn('approval_request_id', $approvalIds)->delete();
        DB::table('approval_requests')->whereIn('id', $approvalIds)->update([
            'resubmission_of_id' => null,
        ]);
        DB::table('approval_requests')->whereIn('id', $approvalIds)->delete();
    }

    private function backfillLegacyShellRows(): void
    {
        DB::table('requisitions')->orderBy('id')->get()->each(function (object $requisition): void {
            $plantId = $requisition->plant_id ?: DB::table('plants')
                ->where('company_id', $requisition->company_id)
                ->orderBy('id')->value('id');
            $actorId = $requisition->created_by ?: DB::table('role_assignments')
                ->where('company_id', $requisition->company_id)
                ->when($plantId, fn ($query) => $query->where(fn ($scope) => $scope
                    ->whereNull('plant_id')->orWhere('plant_id', $plantId)))
                ->where('is_active', true)
                ->orderBy('id')->value('user_id');
            $actorId = $actorId ?: DB::table('users')->where('status', 'ACTIVE')->orderBy('id')->value('id');
            if (! $plantId || ! $actorId) {
                throw new RuntimeException(
                    "Legacy requisition {$requisition->id} cannot be assigned to a valid plant and requester."
                );
            }
            $requestedDate = $requisition->created_at
                ? substr((string) $requisition->created_at, 0, 10)
                : now()->toDateString();

            DB::table('requisitions')->where('id', $requisition->id)->update([
                'plant_id' => $plantId,
                'status' => 'DRAFT',
                'created_by' => $actorId,
                'requisition_number' => 'LEGACY-REQ-'.Str::upper(substr(str_replace('-', '', (string) $requisition->id), 0, 12)),
                'requested_by' => $actorId,
                'department' => 'Legacy',
                'purpose' => 'Migrated legacy requisition shell record.',
                'requested_date' => $requestedDate,
                'required_by_date' => $requestedDate,
                'currency' => 'INR',
                'estimated_total' => 0,
                'approval_request_id' => null,
                'submitted_at' => null,
                'submitted_by' => null,
                'approved_at' => null,
                'approved_by' => null,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'updated_at' => now(),
            ]);
        });
    }

    private function constraints(): array
    {
        return [
            ['requisitions', 'requisitions_requester_fk', 'FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT'],
            ['requisitions', 'requisitions_submitter_fk', 'FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT'],
            ['requisitions', 'requisitions_approver_fk', 'FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT'],
            ['requisitions', 'requisitions_rejector_fk', 'FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE RESTRICT'],
            ['requisitions', 'requisitions_canceller_fk', 'FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE RESTRICT'],
            ['requisitions', 'requisitions_approval_fk', 'FOREIGN KEY (approval_request_id, company_id, plant_id) REFERENCES approval_requests(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['requisition_lines', 'requisition_lines_item_uom_fk', 'FOREIGN KEY (item_id, company_id, uom_code) REFERENCES items(id, company_id, base_uom) ON DELETE RESTRICT'],
            ['requisitions', 'requisitions_status_check', "CHECK (status IN ('DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED'))"],
            ['requisitions', 'requisitions_dates_check', 'CHECK (required_by_date >= requested_date)'],
            ['requisitions', 'requisitions_currency_check', "CHECK (currency = 'INR')"],
            ['requisitions', 'requisitions_total_check', 'CHECK (estimated_total >= 0)'],
            ['requisitions', 'requisitions_workflow_check', <<<'SQL'
CHECK (
    (status = 'DRAFT' AND approval_request_id IS NULL AND submitted_at IS NULL AND submitted_by IS NULL AND approved_at IS NULL AND approved_by IS NULL AND rejected_at IS NULL AND rejected_by IS NULL AND rejection_reason IS NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'SUBMITTED' AND approval_request_id IS NOT NULL AND submitted_at IS NOT NULL AND submitted_by IS NOT NULL AND approved_at IS NULL AND approved_by IS NULL AND rejected_at IS NULL AND rejected_by IS NULL AND rejection_reason IS NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'APPROVED' AND approval_request_id IS NOT NULL AND submitted_at IS NOT NULL AND submitted_by IS NOT NULL AND approved_at IS NOT NULL AND approved_by IS NOT NULL AND rejected_at IS NULL AND rejected_by IS NULL AND rejection_reason IS NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'REJECTED' AND approval_request_id IS NOT NULL AND submitted_at IS NOT NULL AND submitted_by IS NOT NULL AND approved_at IS NULL AND approved_by IS NULL AND rejected_at IS NOT NULL AND rejected_by IS NOT NULL AND rejection_reason IS NOT NULL AND btrim(rejection_reason) <> '' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
)
SQL],
            ['requisition_lines', 'requisition_lines_values_check', 'CHECK (line_number >= 1 AND quantity > 0 AND estimated_unit_cost >= 0 AND estimated_line_total = round(quantity * estimated_unit_cost, 6))'],
        ];
    }

    private function createConstraintTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION qtfoods_check_requisition_total() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    target_id uuid;
    header_total numeric(20, 6);
    line_total numeric(20, 6);
BEGIN
    IF TG_TABLE_NAME = 'requisitions' THEN
        target_id := COALESCE(NEW.id, OLD.id);
    ELSE
        target_id := COALESCE(NEW.requisition_id, OLD.requisition_id);
    END IF;
    SELECT estimated_total INTO header_total FROM requisitions WHERE id = target_id;
    IF FOUND THEN
        SELECT COALESCE(SUM(estimated_line_total), 0) INTO line_total
        FROM requisition_lines WHERE requisition_id = target_id;
        IF header_total IS DISTINCT FROM line_total THEN
            RAISE EXCEPTION 'requisition estimated total must equal the sum of its lines'
                USING ERRCODE = '23514', CONSTRAINT = 'requisitions_line_total_guard';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER requisitions_line_total_guard
AFTER INSERT OR UPDATE OF estimated_total ON requisitions
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_requisition_total();

CREATE CONSTRAINT TRIGGER requisition_lines_total_guard
AFTER INSERT OR UPDATE OR DELETE ON requisition_lines
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_requisition_total();

CREATE OR REPLACE FUNCTION qtfoods_check_requisition_approval() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    approval approval_requests%ROWTYPE;
BEGIN
    IF NEW.approval_request_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT * INTO approval FROM approval_requests WHERE id = NEW.approval_request_id;
    IF NOT FOUND OR approval.entity_type <> 'purchase_requisition' OR approval.entity_id <> NEW.id THEN
        RAISE EXCEPTION 'requisition approval request must identify the same purchase requisition'
            USING ERRCODE = '23514', CONSTRAINT = 'requisitions_approval_identity_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER requisitions_approval_identity_guard
AFTER INSERT OR UPDATE OF approval_request_id ON requisitions
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_requisition_approval();
SQL);
    }

    private function dropConstraintTriggers(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS requisitions_approval_identity_guard ON requisitions;
DROP TRIGGER IF EXISTS requisition_lines_total_guard ON requisition_lines;
DROP TRIGGER IF EXISTS requisitions_line_total_guard ON requisitions;
DROP FUNCTION IF EXISTS qtfoods_check_requisition_approval();
DROP FUNCTION IF EXISTS qtfoods_check_requisition_total();
SQL);
    }
};
