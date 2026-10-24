<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->index(
                ['company_id', 'plant_id', 'event_at'],
                'audit_events_scope_event_index'
            );
            $table->index(
                ['company_id', 'plant_id', 'outcome', 'event_at'],
                'audit_events_scope_outcome_index'
            );
        });

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->uuid('company_id')->nullable();
            $table->uuid('plant_id')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampTz('locked_at')->nullable();
            $table->string('locked_by', 160)->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->string('acknowledgement_id', 255)->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->text('last_error_message')->nullable();
            $table->jsonb('transport_response_json')->nullable();
            $table->timestampTz('quarantined_at')->nullable();
            $table->uuid('quarantined_by')->nullable();
            $table->text('quarantine_reason')->nullable();

            $table->index(
                ['company_id', 'plant_id', 'status', 'created_at'],
                'outbox_events_scope_status_index'
            );
            $table->index(
                ['status', 'next_retry_at', 'created_at'],
                'outbox_events_delivery_index'
            );
            $table->index('locked_at', 'outbox_events_locked_index');
        });

        Schema::create('outbox_delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('outbox_event_id');
            $table->unsignedInteger('attempt_number');
            $table->string('worker_id', 160);
            $table->string('transport', 32);
            $table->string('outcome', 32);
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->string('acknowledgement_id', 255)->nullable();
            $table->jsonb('response_json')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at');
            $table->timestampTz('created_at');

            $table->unique(
                ['outbox_event_id', 'attempt_number'],
                'outbox_delivery_attempt_number_unique'
            );
            $table->index(['outcome', 'completed_at'], 'outbox_delivery_attempt_outcome_index');
            $table->foreign('outbox_event_id', 'outbox_delivery_attempt_event_fk')
                ->references('id')->on('outbox_events')->cascadeOnDelete();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $this->backfillOutboxScope();
        $this->addChecks();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE outbox_events DROP CONSTRAINT IF EXISTS outbox_events_status_check');
            DB::statement('ALTER TABLE outbox_events DROP CONSTRAINT IF EXISTS outbox_events_attempts_check');
            DB::statement('ALTER TABLE outbox_delivery_attempts DROP CONSTRAINT IF EXISTS outbox_delivery_attempts_outcome_check');
        }

        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('outbox_delivery_attempts');

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropIndex('outbox_events_locked_index');
            $table->dropIndex('outbox_events_delivery_index');
            $table->dropIndex('outbox_events_scope_status_index');
            $table->dropColumn([
                'company_id', 'plant_id', 'record_version', 'locked_at', 'locked_by',
                'last_attempt_at', 'delivered_at', 'acknowledged_at', 'acknowledgement_id',
                'last_error_code', 'last_error_message', 'transport_response_json',
                'quarantined_at', 'quarantined_by', 'quarantine_reason',
            ]);
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex('audit_events_scope_outcome_index');
            $table->dropIndex('audit_events_scope_event_index');
        });
    }

    private function backfillOutboxScope(): void
    {
        DB::table('outbox_events')->orderBy('created_at')->orderBy('id')->get()
            ->each(function (object $event): void {
                $payload = json_decode((string) $event->payload_json, true);
                $companyId = $this->findPayloadValue($payload, 'company_id');
                $plantId = $this->findPayloadValue($payload, 'plant_id');

                $audit = null;
                if (is_string($event->correlation_id) && $event->correlation_id !== '') {
                    $audit = DB::table('audit_events')
                        ->where('correlation_id', $event->correlation_id)
                        ->orderByDesc('event_at')->first(['company_id', 'plant_id']);
                }
                if (! $audit) {
                    $audit = DB::table('audit_events')
                        ->where('entity_id', $event->aggregate_id)
                        ->orderByDesc('event_at')
                        ->first(['company_id', 'plant_id']);
                }

                $companyId ??= is_string($audit?->company_id ?? null) ? $audit->company_id : null;
                $plantId ??= is_string($audit?->plant_id ?? null) ? $audit->plant_id : null;

                DB::table('outbox_events')->where('id', $event->id)->update([
                    'company_id' => $companyId,
                    'plant_id' => $plantId,
                    'record_version' => 1,
                ]);
            });
    }

    private function findPayloadValue(mixed $payload, string $key): ?string
    {
        if (! is_array($payload)) {
            return null;
        }
        if (isset($payload[$key]) && is_string($payload[$key])) {
            return $payload[$key];
        }
        foreach ($payload as $value) {
            $found = $this->findPayloadValue($value, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function addChecks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_status_check CHECK (status IN ('PENDING', 'PROCESSING', 'RETRY', 'DELIVERED', 'QUARANTINED'))");
        DB::statement('ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_attempts_check CHECK (attempts >= 0 AND record_version > 0)');
        DB::statement("ALTER TABLE outbox_delivery_attempts ADD CONSTRAINT outbox_delivery_attempts_outcome_check CHECK (outcome IN ('DELIVERED', 'RETRY', 'QUARANTINED'))");
    }
};
