<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->unique(
                ['id', 'user_id', 'company_id', 'plant_id', 'party_id'],
                'role_assignments_portal_identity_unique',
            );
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unique(
                ['id', 'company_id', 'plant_id', 'customer_party_id'],
                'sales_orders_portal_party_identity_unique',
            );
        });
        Schema::table('sales_invoice_financials', function (Blueprint $table) {
            $table->unique(
                ['invoice_id', 'company_id', 'plant_id', 'party_id'],
                'sales_invoice_portal_party_identity_unique',
            );
        });
        Schema::table('customer_claims', function (Blueprint $table) {
            $table->unique(
                ['id', 'company_id', 'plant_id', 'customer_party_id'],
                'customer_claims_portal_party_identity_unique',
            );
        });

        Schema::create('partner_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('party_id');
            $table->uuid('user_id');
            $table->uuid('role_assignment_id');
            $table->string('status', 16)->default('ACTIVE');
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestampsTz();

            $table->unique('role_assignment_id', 'partner_access_grants_assignment_unique');
            $table->unique(
                ['id', 'company_id', 'plant_id', 'party_id'],
                'partner_access_grants_scope_identity_unique',
            );
            $table->index(
                ['company_id', 'plant_id', 'party_id', 'status'],
                'partner_access_grants_party_status_index',
            );
            $table->index(
                ['user_id', 'company_id', 'plant_id', 'status'],
                'partner_access_grants_user_status_index',
            );
            $table->foreign(
                ['role_assignment_id', 'user_id', 'company_id', 'plant_id', 'party_id'],
                'partner_access_grants_assignment_scope_fk',
            )->references(
                ['id', 'user_id', 'company_id', 'plant_id', 'party_id'],
            )->on('role_assignments')->restrictOnDelete();
            $table->foreign('created_by', 'partner_access_grants_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by', 'partner_access_grants_revoker_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('partner_access_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_access_grant_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('party_id');
            $table->string('entitlement_code', 40);
            $table->timestampsTz();

            $table->unique(
                ['partner_access_grant_id', 'entitlement_code'],
                'partner_access_entitlements_code_unique',
            );
            $table->foreign(
                ['partner_access_grant_id', 'company_id', 'plant_id', 'party_id'],
                'partner_access_entitlements_grant_scope_fk',
            )->references(
                ['id', 'company_id', 'plant_id', 'party_id'],
            )->on('partner_access_grants')->cascadeOnDelete();
        });

        Schema::create('partner_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('party_id');
            $table->string('document_number', 80);
            $table->string('direction', 16);
            $table->string('document_type', 32);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->uuid('sales_order_id')->nullable();
            $table->uuid('shipment_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->uuid('customer_claim_id')->nullable();
            $table->string('storage_disk', 32)->default('private');
            $table->string('storage_path', 1000);
            $table->string('original_name', 255);
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256_checksum', 64);
            $table->string('status', 24)->default('AVAILABLE');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('available_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->string('acknowledgement_reference', 160)->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->uuid('withdrawn_by')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['company_id', 'plant_id', 'party_id', 'document_number'],
                'partner_documents_party_number_unique',
            );
            $table->unique(
                ['id', 'company_id', 'plant_id', 'party_id'],
                'partner_documents_scope_identity_unique',
            );
            $table->index(
                ['company_id', 'plant_id', 'party_id', 'direction', 'status'],
                'partner_documents_party_status_index',
            );
            $table->foreign(['plant_id', 'company_id'], 'partner_documents_plant_scope_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'partner_documents_party_scope_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign(
                ['sales_order_id', 'company_id', 'plant_id', 'party_id'],
                'partner_documents_sales_order_party_fk',
            )->references(
                ['id', 'company_id', 'plant_id', 'customer_party_id'],
            )->on('sales_orders')->restrictOnDelete();
            $table->foreign(
                ['shipment_id', 'company_id', 'plant_id', 'party_id'],
                'partner_documents_shipment_party_fk',
            )->references(
                ['id', 'company_id', 'plant_id', 'party_id'],
            )->on('shipments')->restrictOnDelete();
            $table->foreign(
                ['invoice_id', 'company_id', 'plant_id', 'party_id'],
                'partner_documents_invoice_party_fk',
            )->references(
                ['invoice_id', 'company_id', 'plant_id', 'party_id'],
            )->on('sales_invoice_financials')->restrictOnDelete();
            $table->foreign(
                ['customer_claim_id', 'company_id', 'plant_id', 'party_id'],
                'partner_documents_claim_party_fk',
            )->references(
                ['id', 'company_id', 'plant_id', 'customer_party_id'],
            )->on('customer_claims')->restrictOnDelete();
            foreach ([
                'created_by' => 'creator',
                'acknowledged_by' => 'acknowledger',
                'withdrawn_by' => 'withdrawer',
            ] as $column => $name) {
                $table->foreign($column, 'partner_documents_'.$name.'_fk')
                    ->references('id')->on('users')->restrictOnDelete();
            }
        });

        Schema::create('partner_document_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_document_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('party_id');
            $table->string('event_type', 24);
            $table->uuid('actor_id');
            $table->string('reference', 160)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->index(
                ['partner_document_id', 'occurred_at'],
                'partner_document_events_timeline_index',
            );
            $table->foreign(
                ['partner_document_id', 'company_id', 'plant_id', 'party_id'],
                'partner_document_events_document_scope_fk',
            )->references(
                ['id', 'company_id', 'plant_id', 'party_id'],
            )->on('partner_documents')->cascadeOnDelete();
            $table->foreign('actor_id', 'partner_document_events_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                "ALTER TABLE partner_access_grants ADD CONSTRAINT partner_access_grants_status_check CHECK (status IN ('ACTIVE', 'REVOKED'))",
                'ALTER TABLE partner_access_grants ADD CONSTRAINT partner_access_grants_version_check CHECK (record_version >= 1)',
                'ALTER TABLE partner_access_grants ADD CONSTRAINT partner_access_grants_dates_check CHECK (effective_from IS NULL OR effective_to IS NULL OR effective_to > effective_from)',
                "ALTER TABLE partner_access_grants ADD CONSTRAINT partner_access_grants_lifecycle_check CHECK ((status = 'ACTIVE' AND revoked_at IS NULL AND revoked_by IS NULL AND revocation_reason IS NULL) OR (status = 'REVOKED' AND revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revocation_reason IS NOT NULL))",
                "ALTER TABLE partner_access_entitlements ADD CONSTRAINT partner_access_entitlements_code_check CHECK (entitlement_code IN ('ORDERS_VIEW', 'SHIPMENTS_VIEW', 'INVOICES_VIEW', 'CLAIMS_VIEW', 'CLAIMS_CREATE', 'DOCUMENTS_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD', 'DOCUMENT_ACKNOWLEDGE'))",
                "ALTER TABLE partner_documents ADD CONSTRAINT partner_documents_direction_check CHECK (direction IN ('INBOUND', 'OUTBOUND'))",
                "ALTER TABLE partner_documents ADD CONSTRAINT partner_documents_type_check CHECK (document_type IN ('ORDER_CONFIRMATION', 'SHIPPING_DOCUMENT', 'INVOICE', 'CLAIM_EVIDENCE', 'QUALITY_CERTIFICATE', 'GENERAL'))",
                "ALTER TABLE partner_documents ADD CONSTRAINT partner_documents_status_check CHECK (status IN ('AVAILABLE', 'ACKNOWLEDGED', 'WITHDRAWN'))",
                'ALTER TABLE partner_documents ADD CONSTRAINT partner_documents_values_check CHECK (record_version >= 1 AND size_bytes > 0 AND sha256_checksum ~ \'^[0-9a-f]{64}$\')',
                'ALTER TABLE partner_documents ADD CONSTRAINT partner_documents_link_shape_check CHECK (num_nonnulls(sales_order_id, shipment_id, invoice_id, customer_claim_id) <= 1)',
                "ALTER TABLE partner_documents ADD CONSTRAINT partner_documents_lifecycle_check CHECK ((status = 'AVAILABLE' AND acknowledged_at IS NULL AND acknowledged_by IS NULL AND acknowledgement_reference IS NULL AND withdrawn_at IS NULL AND withdrawn_by IS NULL AND withdrawal_reason IS NULL) OR (status = 'ACKNOWLEDGED' AND direction = 'OUTBOUND' AND acknowledged_at IS NOT NULL AND acknowledged_by IS NOT NULL AND acknowledgement_reference IS NOT NULL AND withdrawn_at IS NULL AND withdrawn_by IS NULL AND withdrawal_reason IS NULL) OR (status = 'WITHDRAWN' AND direction = 'OUTBOUND' AND acknowledged_at IS NULL AND acknowledged_by IS NULL AND acknowledgement_reference IS NULL AND withdrawn_at IS NOT NULL AND withdrawn_by IS NOT NULL AND withdrawal_reason IS NOT NULL))",
                "ALTER TABLE partner_document_events ADD CONSTRAINT partner_document_events_type_check CHECK (event_type IN ('PUBLISHED', 'UPLOADED', 'ACKNOWLEDGED', 'WITHDRAWN'))",
            ] as $statement) {
                DB::statement($statement);
            }

            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX partner_access_grants_active_user_scope_unique
ON partner_access_grants (user_id, company_id, plant_id)
WHERE status = 'ACTIVE'
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_document_events');
        Schema::dropIfExists('partner_documents');
        Schema::dropIfExists('partner_access_entitlements');
        Schema::dropIfExists('partner_access_grants');

        Schema::table('customer_claims', function (Blueprint $table) {
            $table->dropUnique('customer_claims_portal_party_identity_unique');
        });
        Schema::table('sales_invoice_financials', function (Blueprint $table) {
            $table->dropUnique('sales_invoice_portal_party_identity_unique');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_orders_portal_party_identity_unique');
        });
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropUnique('role_assignments_portal_identity_unique');
        });
    }
};
