<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('unsold_return_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('return_case_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->string('category', 40);
            $table->unsignedBigInteger('case_record_version');
            $table->string('original_name', 255);
            $table->string('storage_disk', 64);
            $table->string('storage_path', 512)->unique();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('notes')->nullable();
            $table->string('retention_policy', 64);
            $table->date('retention_until')->index();
            $table->boolean('legal_hold')->default(false);
            $table->uuid('uploaded_by')->index();
            $table->uuid('upload_audit_event_id')->nullable()->unique();
            $table->string('idempotency_key', 160);
            $table->timestampTz('uploaded_at');
            $table->timestampsTz();

            $table->unique(
                ['return_case_id', 'case_record_version'],
                'unsold_evidence_case_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unsold_return_evidence');
    }
};
