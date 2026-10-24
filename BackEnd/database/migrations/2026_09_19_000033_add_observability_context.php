<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->char('trace_id', 32)->nullable();
            $table->char('span_id', 16)->nullable();
            $table->index('trace_id', 'audit_events_trace_index');
        });
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->uuid('request_id')->nullable();
            $table->char('trace_id', 32)->nullable();
            $table->char('span_id', 16)->nullable();
            $table->index('request_id', 'outbox_events_request_index');
            $table->index('trace_id', 'outbox_events_trace_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_trace_id_check CHECK (trace_id IS NULL OR trace_id ~ '^[0-9a-f]{32}$')");
            DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_span_id_check CHECK (span_id IS NULL OR span_id ~ '^[0-9a-f]{16}$')");
            DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_trace_id_check CHECK (trace_id IS NULL OR trace_id ~ '^[0-9a-f]{32}$')");
            DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_span_id_check CHECK (span_id IS NULL OR span_id ~ '^[0-9a-f]{16}$')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT IF EXISTS audit_events_trace_id_check');
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT IF EXISTS audit_events_span_id_check');
            DB::statement('ALTER TABLE outbox_events DROP CONSTRAINT IF EXISTS outbox_events_trace_id_check');
            DB::statement('ALTER TABLE outbox_events DROP CONSTRAINT IF EXISTS outbox_events_span_id_check');
        }
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropIndex('outbox_events_trace_index');
            $table->dropIndex('outbox_events_request_index');
            $table->dropColumn(['request_id', 'trace_id', 'span_id']);
        });
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex('audit_events_trace_index');
            $table->dropColumn(['trace_id', 'span_id']);
        });
    }
};
