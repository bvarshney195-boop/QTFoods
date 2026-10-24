<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const SHELL_TABLES = [
        'requisitions',
        'purchase_orders',
        'receipts',
        'quality_tasks',
        'production_orders',
        'stage_events',
        'fg_lots',
        'sales_orders',
        'shipments',
        'invoices',
        'journals',
        'assets',
        'payroll_runs',
        'maintenance_work_orders',
        'integration_events',
        'report_runs',
    ];

    public function up(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table) {
            $table->string('operation_type', 24)->nullable()->after('plant_id');
        });
        Schema::table('unsold_return_lines', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('return_case_id');
            $table->uuid('plant_id')->nullable()->after('company_id');
            $table->uuid('shipment_id')->nullable()->after('plant_id');
        });

        $this->backfillRelationshipDimensions();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'ALTER TABLE inventory_operation_lines ALTER COLUMN operation_type SET NOT NULL',
            'ALTER TABLE unsold_return_lines ALTER COLUMN company_id SET NOT NULL',
            'ALTER TABLE unsold_return_lines ALTER COLUMN plant_id SET NOT NULL',
            'ALTER TABLE unsold_return_lines ALTER COLUMN shipment_id SET NOT NULL',
            'ALTER TABLE unsold_return_lines ALTER COLUMN shipment_line_id SET NOT NULL',
            'ALTER TABLE stock_movements ALTER COLUMN plant_id SET NOT NULL',
            'ALTER TABLE approval_requests ALTER COLUMN approval_rule_id SET NOT NULL',
            'ALTER TABLE approval_requests ALTER COLUMN approval_rule_version SET NOT NULL',
            'ALTER TABLE approval_requests ALTER COLUMN approval_rule_band_id SET NOT NULL',
        ] as $statement) {
            DB::statement($statement);
        }

        foreach ($this->uniqueConstraints() as [$table, $name, $definition]) {
            $this->addConstraint($table, $name, $definition);
        }
        foreach ($this->foreignKeyConstraints() as [$table, $name, $definition]) {
            $this->addConstraint($table, $name, $definition);
        }
        foreach ($this->checkConstraints() as [$table, $name, $definition]) {
            $this->addConstraint($table, $name, $definition);
        }

        foreach (self::SHELL_TABLES as $table) {
            $this->addConstraint(
                $table,
                "{$table}_company_fk",
                'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT',
            );
            $this->addConstraint(
                $table,
                "{$table}_plant_scope_fk",
                'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT',
            );
            $this->addConstraint(
                $table,
                "{$table}_created_by_fk",
                'FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT',
            );
            $this->addConstraint(
                $table,
                "{$table}_version_check",
                'CHECK (record_version >= 1)',
            );
        }

        foreach ($this->partialUniqueIndexes() as $statement) {
            DB::statement($statement);
        }

        $this->createRelationshipTriggers();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->dropRelationshipTriggers();

            foreach ($this->partialUniqueIndexNames() as $name) {
                DB::statement("DROP INDEX IF EXISTS {$name}");
            }

            foreach (array_reverse(self::SHELL_TABLES) as $table) {
                foreach ([
                    "{$table}_version_check",
                    "{$table}_created_by_fk",
                    "{$table}_plant_scope_fk",
                    "{$table}_company_fk",
                ] as $name) {
                    $this->dropConstraint($table, $name);
                }
            }

            foreach (array_reverse($this->checkConstraints()) as [$table, $name]) {
                $this->dropConstraint($table, $name);
            }
            foreach (array_reverse($this->foreignKeyConstraints()) as [$table, $name]) {
                $this->dropConstraint($table, $name);
            }
            foreach (array_reverse($this->uniqueConstraints()) as [$table, $name]) {
                $this->dropConstraint($table, $name);
            }

            foreach ([
                'ALTER TABLE approval_requests ALTER COLUMN approval_rule_band_id DROP NOT NULL',
                'ALTER TABLE approval_requests ALTER COLUMN approval_rule_version DROP NOT NULL',
                'ALTER TABLE approval_requests ALTER COLUMN approval_rule_id DROP NOT NULL',
                'ALTER TABLE stock_movements ALTER COLUMN plant_id DROP NOT NULL',
                'ALTER TABLE unsold_return_lines ALTER COLUMN shipment_line_id DROP NOT NULL',
            ] as $statement) {
                DB::statement($statement);
            }
        }

        Schema::table('unsold_return_lines', function (Blueprint $table) {
            $table->dropColumn(['company_id', 'plant_id', 'shipment_id']);
        });
        Schema::table('inventory_operation_lines', function (Blueprint $table) {
            $table->dropColumn('operation_type');
        });
    }

    private function backfillRelationshipDimensions(): void
    {
        DB::table('inventory_operation_lines')
            ->orderBy('id')
            ->get(['id', 'operation_id'])
            ->each(function (object $line): void {
                $type = DB::table('inventory_operations')
                    ->where('id', $line->operation_id)
                    ->value('operation_type');
                DB::table('inventory_operation_lines')->where('id', $line->id)->update([
                    'operation_type' => $type,
                ]);
            });

        DB::table('unsold_return_lines')
            ->orderBy('id')
            ->get(['id', 'return_case_id'])
            ->each(function (object $line): void {
                $case = DB::table('unsold_return_cases')
                    ->where('id', $line->return_case_id)
                    ->first(['company_id', 'plant_id', 'shipment_id']);
                DB::table('unsold_return_lines')->where('id', $line->id)->update([
                    'company_id' => $case?->company_id,
                    'plant_id' => $case?->plant_id,
                    'shipment_id' => $case?->shipment_id,
                ]);
            });
    }

    private function uniqueConstraints(): array
    {
        return [
            ['approval_rules', 'approval_rules_id_code_unique', 'UNIQUE (id, code)'],
            ['approval_rule_bands', 'approval_rule_bands_id_rule_unique', 'UNIQUE (id, approval_rule_id)'],
            ['approval_requests', 'approval_requests_id_scope_unique', 'UNIQUE (id, company_id, plant_id)'],
            ['approval_requests', 'approval_requests_submission_unique', 'UNIQUE (entity_type, entity_id, rule_code, submission_number)'],
            ['approval_decisions', 'approval_decisions_request_unique', 'UNIQUE (approval_request_id)'],
            ['catalog_items', 'catalog_items_identity_unique', 'UNIQUE (id, company_id, item_type, base_uom)'],
            ['items', 'items_uom_identity_unique', 'UNIQUE (id, company_id, base_uom)'],
            ['lots', 'lots_item_identity_unique', 'UNIQUE (id, company_id, item_id)'],
            ['stock_positions', 'stock_positions_uom_identity_unique', 'UNIQUE (id, company_id, plant_id, uom_code)'],
            ['stock_positions', 'stock_positions_item_identity_unique', 'UNIQUE (id, company_id, plant_id, item_id, uom_code)'],
            ['stock_positions', 'stock_positions_lot_identity_unique', 'UNIQUE (id, company_id, plant_id, item_id, lot_id, uom_code)'],
            ['stock_movements', 'stock_movements_scope_uom_unique', 'UNIQUE (id, company_id, plant_id, uom_code)'],
            ['inventory_operations', 'inventory_operations_id_type_unique', 'UNIQUE (id, company_id, plant_id, operation_type)'],
            ['audit_events', 'audit_events_id_scope_unique', 'UNIQUE (id, company_id, plant_id)'],
            ['sales_orders', 'sales_orders_id_scope_unique', 'UNIQUE (id, company_id, plant_id)'],
            ['shipments', 'shipments_id_scope_unique', 'UNIQUE (id, company_id, plant_id)'],
            ['shipments', 'shipments_party_identity_unique', 'UNIQUE (id, company_id, plant_id, party_id)'],
            ['shipment_lines', 'shipment_lines_identity_unique', 'UNIQUE (id, shipment_id, company_id, plant_id, item_id, uom_code)'],
            ['shipment_lines', 'shipment_lines_lot_identity_unique', 'UNIQUE (id, shipment_id, company_id, plant_id, item_id, fg_lot_id, uom_code)'],
            ['invoices', 'invoices_id_scope_unique', 'UNIQUE (id, company_id, plant_id)'],
            ['sales_invoice_financials', 'sales_invoice_financials_identity_unique', 'UNIQUE (invoice_id, company_id, plant_id, party_id, shipment_id)'],
            ['sales_invoice_financials', 'sales_invoice_financials_scope_unique', 'UNIQUE (invoice_id, company_id, plant_id)'],
            ['sales_invoice_financials', 'sales_invoice_financials_scope_currency_unique', 'UNIQUE (invoice_id, company_id, plant_id, currency)'],
            ['sales_invoice_financials', 'sales_invoice_financials_currency_unique', 'UNIQUE (invoice_id, company_id, plant_id, party_id, shipment_id, currency)'],
            ['unsold_return_cases', 'unsold_return_cases_id_scope_unique', 'UNIQUE (id, company_id, plant_id)'],
            ['unsold_return_cases', 'unsold_return_cases_shipment_unique', 'UNIQUE (id, company_id, plant_id, shipment_id)'],
            ['unsold_return_cases', 'unsold_return_cases_invoice_unique', 'UNIQUE (id, company_id, plant_id, invoice_id)'],
            ['loss_events', 'loss_events_id_scope_unique', 'UNIQUE (id, company_id, plant_id)'],
            ['loss_events', 'loss_events_approval_unique', 'UNIQUE (approval_request_id)'],
            ['unsold_return_status_history', 'unsold_return_history_version_unique', 'UNIQUE (return_case_id, record_version)'],
        ];
    }

    private function foreignKeyConstraints(): array
    {
        return [
            ['locations', 'locations_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['locations', 'locations_parent_scope_fk', 'FOREIGN KEY (parent_location_id, company_id, plant_id) REFERENCES locations(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['role_assignments', 'role_assignments_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['role_assignments', 'role_assignments_party_scope_fk', 'FOREIGN KEY (party_id, company_id) REFERENCES parties(id, company_id) ON DELETE RESTRICT'],
            ['identity_invitations', 'identity_invitations_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['identity_tokens', 'identity_tokens_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['work_items', 'work_items_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['approval_rules', 'approval_rules_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['approval_delegations', 'approval_delegations_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['approval_requests', 'approval_requests_company_fk', 'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT'],
            ['approval_requests', 'approval_requests_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['approval_requests', 'approval_requests_maker_fk', 'FOREIGN KEY (maker_id) REFERENCES users(id) ON DELETE RESTRICT'],
            ['approval_requests', 'approval_requests_rule_code_fk', 'FOREIGN KEY (approval_rule_id, rule_code) REFERENCES approval_rules(id, code) ON DELETE RESTRICT'],
            ['approval_requests', 'approval_requests_band_rule_fk', 'FOREIGN KEY (approval_rule_band_id, approval_rule_id) REFERENCES approval_rule_bands(id, approval_rule_id) ON DELETE RESTRICT'],
            ['approval_decisions', 'approval_decisions_request_fk', 'FOREIGN KEY (approval_request_id) REFERENCES approval_requests(id) ON DELETE RESTRICT'],
            ['approval_decisions', 'approval_decisions_reviewer_fk', 'FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE RESTRICT'],
            ['audit_events', 'audit_events_company_fk', 'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT'],
            ['audit_events', 'audit_events_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['audit_events', 'audit_events_actor_fk', 'FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT'],
            ['outbox_events', 'outbox_events_company_fk', 'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT'],
            ['outbox_events', 'outbox_events_plant_scope_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['outbox_events', 'outbox_events_quarantiner_fk', 'FOREIGN KEY (quarantined_by) REFERENCES users(id) ON DELETE RESTRICT'],
            ['items', 'items_catalog_identity_fk', 'FOREIGN KEY (catalog_item_id, company_id, item_type, base_uom) REFERENCES catalog_items(id, company_id, item_type, base_uom) ON DELETE RESTRICT'],
            ['stock_positions', 'stock_positions_lot_item_fk', 'FOREIGN KEY (lot_id, company_id, item_id) REFERENCES lots(id, company_id, item_id) ON DELETE RESTRICT'],
            ['stock_positions', 'stock_positions_item_uom_fk', 'FOREIGN KEY (item_id, company_id, uom_code) REFERENCES items(id, company_id, base_uom) ON DELETE RESTRICT'],
            ['stock_movements', 'stock_movements_from_uom_fk', 'FOREIGN KEY (from_position_id, company_id, plant_id, uom_code) REFERENCES stock_positions(id, company_id, plant_id, uom_code) ON DELETE RESTRICT'],
            ['stock_movements', 'stock_movements_to_uom_fk', 'FOREIGN KEY (to_position_id, company_id, plant_id, uom_code) REFERENCES stock_positions(id, company_id, plant_id, uom_code) ON DELETE RESTRICT'],
            ['inventory_operation_lines', 'inventory_operation_lines_type_fk', 'FOREIGN KEY (operation_id, company_id, plant_id, operation_type) REFERENCES inventory_operations(id, company_id, plant_id, operation_type) ON DELETE CASCADE'],
            ['inventory_operation_lines', 'inventory_operation_lines_source_uom_fk', 'FOREIGN KEY (source_position_id, company_id, plant_id, uom_code) REFERENCES stock_positions(id, company_id, plant_id, uom_code) ON DELETE RESTRICT'],
            ['inventory_operation_lines', 'inventory_operation_lines_target_uom_fk', 'FOREIGN KEY (target_position_id, company_id, plant_id, uom_code) REFERENCES stock_positions(id, company_id, plant_id, uom_code) ON DELETE RESTRICT'],
            ['inventory_operation_lines', 'inventory_operation_lines_movement_scope_fk', 'FOREIGN KEY (movement_id, company_id, plant_id, uom_code) REFERENCES stock_movements(id, company_id, plant_id, uom_code) ON DELETE RESTRICT'],
            ['shipments', 'shipments_party_fk', 'FOREIGN KEY (party_id, company_id) REFERENCES parties(id, company_id) ON DELETE RESTRICT'],
            ['shipment_lines', 'shipment_lines_shipment_scope_fk', 'FOREIGN KEY (shipment_id, company_id, plant_id) REFERENCES shipments(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['shipment_lines', 'shipment_lines_item_uom_fk', 'FOREIGN KEY (item_id, company_id, uom_code) REFERENCES items(id, company_id, base_uom) ON DELETE RESTRICT'],
            ['shipment_lines', 'shipment_lines_lot_item_fk', 'FOREIGN KEY (fg_lot_id, company_id, item_id) REFERENCES lots(id, company_id, item_id) ON DELETE RESTRICT'],
            ['sales_invoice_financials', 'sales_invoice_financials_invoice_fk', 'FOREIGN KEY (invoice_id, company_id, plant_id) REFERENCES invoices(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['sales_invoice_financials', 'sales_invoice_financials_party_fk', 'FOREIGN KEY (party_id, company_id) REFERENCES parties(id, company_id) ON DELETE RESTRICT'],
            ['sales_invoice_financials', 'sales_invoice_financials_shipment_fk', 'FOREIGN KEY (shipment_id, company_id, plant_id, party_id) REFERENCES shipments(id, company_id, plant_id, party_id) ON DELETE RESTRICT'],
            ['unsold_return_cases', 'unsold_return_cases_company_fk', 'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT'],
            ['unsold_return_cases', 'unsold_return_cases_plant_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['unsold_return_cases', 'unsold_return_cases_party_fk', 'FOREIGN KEY (party_id, company_id) REFERENCES parties(id, company_id) ON DELETE RESTRICT'],
            ['unsold_return_cases', 'unsold_return_cases_sales_order_fk', 'FOREIGN KEY (sales_order_id, company_id, plant_id) REFERENCES sales_orders(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['unsold_return_cases', 'unsold_return_cases_shipment_fk', 'FOREIGN KEY (shipment_id, company_id, plant_id, party_id) REFERENCES shipments(id, company_id, plant_id, party_id) ON DELETE RESTRICT'],
            ['unsold_return_cases', 'unsold_return_cases_invoice_fk', 'FOREIGN KEY (invoice_id, company_id, plant_id, party_id, shipment_id) REFERENCES sales_invoice_financials(invoice_id, company_id, plant_id, party_id, shipment_id) ON DELETE RESTRICT'],
            ['unsold_return_cases', 'unsold_return_cases_maker_fk', 'FOREIGN KEY (maker_id) REFERENCES users(id) ON DELETE RESTRICT'],
            ['unsold_return_cases', 'unsold_return_cases_loss_fk', 'FOREIGN KEY (loss_event_id, company_id, plant_id) REFERENCES loss_events(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['unsold_return_lines', 'unsold_return_lines_case_scope_fk', 'FOREIGN KEY (return_case_id, company_id, plant_id, shipment_id) REFERENCES unsold_return_cases(id, company_id, plant_id, shipment_id) ON DELETE CASCADE'],
            ['unsold_return_lines', 'unsold_return_lines_shipment_line_fk', 'FOREIGN KEY (shipment_line_id, shipment_id, company_id, plant_id, sku_id, uom_code) REFERENCES shipment_lines(id, shipment_id, company_id, plant_id, item_id, uom_code) ON DELETE RESTRICT'],
            ['unsold_return_lines', 'unsold_return_lines_shipment_lot_fk', 'FOREIGN KEY (shipment_line_id, shipment_id, company_id, plant_id, sku_id, fg_lot_id, uom_code) REFERENCES shipment_lines(id, shipment_id, company_id, plant_id, item_id, fg_lot_id, uom_code) ON DELETE RESTRICT'],
            ['unsold_return_lines', 'unsold_return_lines_lot_fk', 'FOREIGN KEY (fg_lot_id, company_id, sku_id) REFERENCES lots(id, company_id, item_id) ON DELETE RESTRICT'],
            ['unsold_return_lines', 'unsold_return_lines_position_fk', 'FOREIGN KEY (return_position_id, company_id, plant_id, sku_id, uom_code) REFERENCES stock_positions(id, company_id, plant_id, item_id, uom_code) ON DELETE RESTRICT'],
            ['unsold_return_lines', 'unsold_return_lines_position_lot_fk', 'FOREIGN KEY (return_position_id, company_id, plant_id, sku_id, fg_lot_id, uom_code) REFERENCES stock_positions(id, company_id, plant_id, item_id, lot_id, uom_code) ON DELETE RESTRICT'],
            ['unsold_return_lines', 'unsold_return_lines_reviewer_fk', 'FOREIGN KEY (quality_reviewer_id) REFERENCES users(id) ON DELETE RESTRICT'],
            ['unsold_return_status_history', 'unsold_return_history_case_fk', 'FOREIGN KEY (return_case_id, company_id, plant_id) REFERENCES unsold_return_cases(id, company_id, plant_id) ON DELETE CASCADE'],
            ['unsold_return_status_history', 'unsold_return_history_actor_fk', 'FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT'],
            ['loss_events', 'loss_events_company_fk', 'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT'],
            ['loss_events', 'loss_events_plant_fk', 'FOREIGN KEY (plant_id, company_id) REFERENCES plants(id, company_id) ON DELETE RESTRICT'],
            ['loss_events', 'loss_events_uom_fk', 'FOREIGN KEY (uom_code) REFERENCES uoms(code) ON DELETE RESTRICT'],
            ['loss_events', 'loss_events_approval_fk', 'FOREIGN KEY (approval_request_id, company_id, plant_id) REFERENCES approval_requests(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['loss_events', 'loss_events_actor_fk', 'FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT'],
            ['unsold_return_finance_actions', 'unsold_finance_case_fk', 'FOREIGN KEY (return_case_id, company_id, plant_id) REFERENCES unsold_return_cases(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['unsold_return_finance_actions', 'unsold_finance_case_invoice_fk', 'FOREIGN KEY (return_case_id, company_id, plant_id, invoice_id) REFERENCES unsold_return_cases(id, company_id, plant_id, invoice_id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED'],
            ['unsold_return_finance_actions', 'unsold_finance_invoice_fk', 'FOREIGN KEY (invoice_id, company_id, plant_id, currency) REFERENCES sales_invoice_financials(invoice_id, company_id, plant_id, currency) ON DELETE RESTRICT'],
            ['unsold_return_finance_actions', 'unsold_finance_actor_fk', 'FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT'],
            ['unsold_return_evidence', 'unsold_evidence_case_fk', 'FOREIGN KEY (return_case_id, company_id, plant_id) REFERENCES unsold_return_cases(id, company_id, plant_id) ON DELETE RESTRICT'],
            ['unsold_return_evidence', 'unsold_evidence_uploader_fk', 'FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT'],
            ['unsold_return_evidence', 'unsold_evidence_audit_fk', 'FOREIGN KEY (upload_audit_event_id, company_id, plant_id) REFERENCES audit_events(id, company_id, plant_id) ON DELETE RESTRICT'],
        ];
    }

    private function checkConstraints(): array
    {
        $checks = [
            ['companies', 'companies_version_check', 'CHECK (record_version >= 1)'],
            ['plants', 'plants_version_check', 'CHECK (record_version >= 1)'],
            ['users', 'users_version_check', 'CHECK (record_version >= 1)'],
            ['users', 'users_mfa_state_check', 'CHECK ((mfa_secret IS NULL) = (mfa_enabled_at IS NULL))'],
            ['roles', 'roles_version_check', 'CHECK (record_version >= 1)'],
            ['roles', 'roles_scope_check', 'CHECK ((is_system AND company_id IS NULL) OR (NOT is_system AND company_id IS NOT NULL))'],
            ['permissions', 'permissions_version_check', 'CHECK (record_version >= 1)'],
            ['permissions', 'permissions_scope_check', 'CHECK ((is_system AND company_id IS NULL) OR (NOT is_system AND company_id IS NOT NULL))'],
            ['role_assignments', 'role_assignments_version_check', 'CHECK (record_version >= 1)'],
            ['role_assignments', 'role_assignments_scope_check', 'CHECK (plant_id IS NULL OR company_id IS NOT NULL)'],
            ['locations', 'locations_version_check', 'CHECK (record_version >= 1)'],
            ['locations', 'locations_type_check', "CHECK (location_type IN ('WAREHOUSE', 'ZONE', 'BIN', 'RETURN_QUARANTINE', 'FINISHED_GOODS', 'RAW_MATERIAL', 'QUALITY_HOLD', 'REPACK', 'REWORK', 'BLOCKED', 'OTHER'))"],
            ['uoms', 'uoms_precision_check', 'CHECK (precision BETWEEN 0 AND 6)'],
            ['idempotency_keys', 'idempotency_keys_status_check', "CHECK (status IN ('IN_PROGRESS', 'COMPLETED'))"],
            ['idempotency_keys', 'idempotency_keys_result_check', "CHECK ((status = 'IN_PROGRESS' AND result_json IS NULL) OR (status = 'COMPLETED' AND result_json IS NOT NULL))"],
            ['idempotency_keys', 'idempotency_keys_hash_check', "CHECK (payload_hash ~ '^[0-9a-f]{64}$')"],
            ['identity_invitations', 'identity_invitations_version_check', 'CHECK (record_version >= 1 AND delivery_count >= 0)'],
            ['identity_invitations', 'identity_invitations_delivery_check', "CHECK (last_delivery_status IS NULL OR last_delivery_status IN ('SENT', 'FAILED'))"],
            ['identity_invitations', 'identity_invitations_dates_check', 'CHECK ((created_at IS NULL OR expires_at > created_at) AND (accepted_at IS NULL OR created_at IS NULL OR accepted_at >= created_at) AND (revoked_at IS NULL OR created_at IS NULL OR revoked_at >= created_at))'],
            ['identity_tokens', 'identity_tokens_scope_check', 'CHECK (plant_id IS NULL OR company_id IS NOT NULL)'],
            ['identity_tokens', 'identity_tokens_terminal_check', 'CHECK (used_at IS NULL OR revoked_at IS NULL)'],
            ['identity_tokens', 'identity_tokens_dates_check', 'CHECK ((created_at IS NULL OR expires_at > created_at) AND (used_at IS NULL OR created_at IS NULL OR used_at >= created_at) AND (revoked_at IS NULL OR created_at IS NULL OR revoked_at >= created_at))'],
            ['user_sessions', 'user_sessions_version_check', 'CHECK (record_version >= 1)'],
            ['user_sessions', 'user_sessions_dates_check', 'CHECK ((created_at IS NULL OR expires_at > created_at) AND (revoked_at IS NULL OR created_at IS NULL OR revoked_at >= created_at))'],
            ['work_items', 'work_items_version_check', 'CHECK (record_version >= 1)'],
            ['work_items', 'work_items_source_pair_check', 'CHECK ((source_type IS NULL) = (source_id IS NULL))'],
            ['work_items', 'work_items_completion_check', "CHECK ((status = 'OPEN' AND completed_at IS NULL AND completed_by IS NULL) OR (status = 'COMPLETED' AND completed_at IS NOT NULL AND completed_by IS NOT NULL) OR status = 'CANCELLED')"],
            ['approval_rules', 'approval_rules_version_check', 'CHECK (record_version >= 1)'],
            ['approval_rule_bands', 'approval_rule_bands_sequence_check', 'CHECK (sequence >= 1)'],
            ['approval_delegations', 'approval_delegations_version_check', 'CHECK (record_version >= 1)'],
            ['approval_delegations', 'approval_delegations_revocation_check', "CHECK ((status = 'ACTIVE' AND revoked_at IS NULL AND revoked_by IS NULL) OR (status = 'REVOKED' AND revoked_at IS NOT NULL AND revoked_by IS NOT NULL))"],
            ['approval_requests', 'approval_requests_status_check', "CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED'))"],
            ['approval_requests', 'approval_requests_version_check', 'CHECK (entity_version >= 1 AND record_version >= 1 AND approval_rule_version >= 1 AND submission_number >= 1 AND escalation_count >= 0)'],
            ['approval_requests', 'approval_requests_timeline_check', 'CHECK ((escalate_at IS NULL OR due_at IS NULL OR escalate_at <= due_at) AND (escalated_at IS NULL OR escalate_at IS NULL OR escalated_at >= escalate_at))'],
            ['approval_decisions', 'approval_decisions_decision_check', "CHECK (decision IN ('APPROVE', 'REJECT'))"],
            ['approval_decisions', 'approval_decisions_authority_check', "CHECK (authority_source IS NULL OR (authority_source IN ('DIRECT', 'DELEGATION', 'INTERNAL') AND ((authority_source = 'DELEGATION') = (delegation_id IS NOT NULL))))"],
            ['audit_events', 'audit_events_outcome_check', "CHECK (outcome IN ('SUCCESS', 'FAILURE', 'DENIED'))"],
            ['audit_events', 'audit_events_version_check', 'CHECK (entity_version IS NULL OR entity_version >= 1)'],
            ['outbox_events', 'outbox_events_scope_check', 'CHECK (plant_id IS NULL OR company_id IS NOT NULL)'],
            ['outbox_events', 'outbox_events_lifecycle_check', "CHECK ((status = 'DELIVERED' AND delivered_at IS NOT NULL AND quarantined_at IS NULL) OR (status = 'QUARANTINED' AND quarantined_at IS NOT NULL AND quarantine_reason IS NOT NULL) OR status IN ('PENDING', 'PROCESSING', 'RETRY'))"],
            ['outbox_delivery_attempts', 'outbox_attempts_number_check', 'CHECK (attempt_number >= 1)'],
            ['outbox_delivery_attempts', 'outbox_attempts_timeline_check', 'CHECK (completed_at >= started_at)'],
            ['catalog_items', 'catalog_items_shelf_life_check', 'CHECK (shelf_life_days IS NULL OR shelf_life_days >= 0)'],
            ['recipes', 'recipes_revision_check', 'CHECK (revision >= 1)'],
            ['recipe_components', 'recipe_components_sequence_check', 'CHECK (sequence_no >= 1)'],
            ['route_operations', 'route_operations_sequence_check', 'CHECK (sequence_no >= 1)'],
            ['quality_spec_parameters', 'quality_spec_parameters_sequence_check', 'CHECK (sequence_no >= 1)'],
            ['quality_spec_parameters', 'quality_spec_parameters_target_range_check', 'CHECK ((minimum_value IS NULL OR target_value IS NULL OR target_value >= minimum_value) AND (maximum_value IS NULL OR target_value IS NULL OR target_value <= maximum_value))'],
            ['inventory_quality_statuses', 'inventory_quality_statuses_sort_check', 'CHECK (sort_order >= 0)'],
            ['stock_movements', 'stock_movements_positions_check', 'CHECK ((from_position_id IS NOT NULL OR to_position_id IS NOT NULL) AND (from_position_id IS NULL OR to_position_id IS NULL OR from_position_id <> to_position_id))'],
            ['stock_movements', 'stock_movements_source_version_check', 'CHECK (source_version IS NULL OR source_version >= 1)'],
            ['stock_movements', 'stock_movements_timeline_check', 'CHECK (posted_at >= event_at)'],
            ['inventory_operation_lines', 'inventory_operation_lines_sequence_check', 'CHECK (sequence_no >= 1)'],
            ['inventory_operation_lines', 'inventory_operation_lines_shape_check', "CHECK ((operation_type = 'ISSUE' AND source_position_id IS NOT NULL AND target_position_id IS NULL AND quantity_base > 0 AND counted_quantity_base IS NULL AND system_quantity_base IS NULL AND source_position_version IS NULL AND adjustment_direction IS NULL) OR (operation_type = 'RETURN' AND source_position_id IS NULL AND target_position_id IS NOT NULL AND quantity_base > 0 AND counted_quantity_base IS NULL AND system_quantity_base IS NULL AND source_position_version IS NULL AND adjustment_direction IS NULL) OR (operation_type IN ('TRANSFER', 'EXPIRY') AND source_position_id IS NOT NULL AND target_position_id IS NOT NULL AND source_position_id <> target_position_id AND quantity_base > 0 AND counted_quantity_base IS NULL AND system_quantity_base IS NULL AND source_position_version IS NULL AND adjustment_direction IS NULL) OR (operation_type = 'COUNT' AND source_position_id IS NOT NULL AND target_position_id IS NULL AND quantity_base IS NULL AND counted_quantity_base >= 0 AND system_quantity_base >= 0 AND source_position_version >= 1 AND adjustment_direction IS NULL) OR (operation_type = 'ADJUSTMENT' AND source_position_id IS NOT NULL AND target_position_id IS NULL AND quantity_base > 0 AND counted_quantity_base IS NULL AND system_quantity_base IS NULL AND source_position_version IS NULL AND adjustment_direction IN ('INCREASE', 'DECREASE')) OR (operation_type = 'DISPOSAL' AND source_position_id IS NOT NULL AND target_position_id IS NULL AND quantity_base > 0 AND counted_quantity_base IS NULL AND system_quantity_base IS NULL AND source_position_version IS NULL AND adjustment_direction IS NULL))"],
            ['shipment_lines', 'shipment_lines_quantity_check', 'CHECK (shipped_quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= shipped_quantity)'],
            ['shipment_lines', 'shipment_lines_version_check', 'CHECK (record_version >= 1)'],
            ['sales_invoice_financials', 'sales_invoice_financials_currency_check', "CHECK (currency ~ '^[A-Z]{3}$')"],
            ['sales_invoice_financials', 'sales_invoice_financials_amounts_check', 'CHECK (net_amount >= 0 AND tax_amount >= 0 AND gross_amount = net_amount + tax_amount AND outstanding_amount >= 0 AND outstanding_amount <= gross_amount)'],
            ['sales_invoice_financials', 'sales_invoice_financials_version_check', 'CHECK (record_version >= 1)'],
            ['unsold_return_cases', 'unsold_return_cases_status_check', "CHECK (status IN ('REQUESTED', 'IN_TRANSIT', 'PARTIALLY_RECEIVED', 'RETURN_QUARANTINE', 'DISPOSITION_REVIEW', 'LOSS_POSTED', 'FINANCE_RESOLVED'))"],
            ['unsold_return_cases', 'unsold_return_cases_version_check', 'CHECK (record_version >= 1)'],
            ['unsold_return_cases', 'unsold_return_cases_receipt_check', "CHECK ((status IN ('REQUESTED', 'IN_TRANSIT', 'PARTIALLY_RECEIVED') AND received_at IS NULL) OR (status IN ('RETURN_QUARANTINE', 'DISPOSITION_REVIEW', 'LOSS_POSTED', 'FINANCE_RESOLVED') AND received_at IS NOT NULL))"],
            ['unsold_return_cases', 'unsold_return_cases_loss_check', "CHECK ((status IN ('LOSS_POSTED', 'FINANCE_RESOLVED')) = (loss_event_id IS NOT NULL))"],
            ['unsold_return_cases', 'unsold_return_cases_finance_check', "CHECK (status <> 'FINANCE_RESOLVED' OR invoice_id IS NOT NULL)"],
            ['unsold_return_lines', 'unsold_return_lines_quantity_check', 'CHECK (requested_quantity > 0 AND received_quantity >= 0 AND received_quantity <= requested_quantity AND restock_quantity >= 0 AND repack_quantity >= 0 AND rework_quantity >= 0 AND destroy_quantity >= 0 AND restock_quantity + repack_quantity + rework_quantity + destroy_quantity <= received_quantity)'],
            ['unsold_return_lines', 'unsold_return_lines_receipt_check', 'CHECK (received_quantity = 0 OR return_position_id IS NOT NULL)'],
            ['unsold_return_lines', 'unsold_return_lines_disposition_check', 'CHECK (restock_quantity + repack_quantity + rework_quantity + destroy_quantity = 0 OR quality_reviewer_id IS NOT NULL)'],
            ['unsold_return_status_history', 'unsold_return_history_status_check', "CHECK ((from_status IS NULL OR from_status IN ('REQUESTED', 'IN_TRANSIT', 'PARTIALLY_RECEIVED', 'RETURN_QUARANTINE', 'DISPOSITION_REVIEW', 'LOSS_POSTED', 'FINANCE_RESOLVED')) AND to_status IN ('REQUESTED', 'IN_TRANSIT', 'PARTIALLY_RECEIVED', 'RETURN_QUARANTINE', 'DISPOSITION_REVIEW', 'LOSS_POSTED', 'FINANCE_RESOLVED'))"],
            ['unsold_return_status_history', 'unsold_return_history_version_check', 'CHECK (record_version >= 1)'],
            ['loss_events', 'loss_events_quantity_check', 'CHECK (quantity_base > 0)'],
            ['loss_events', 'loss_events_amount_check', 'CHECK (cost_amount IS NULL OR cost_amount >= 0)'],
            ['loss_events', 'loss_events_currency_check', "CHECK (currency ~ '^[A-Z]{3}$')"],
            ['unsold_return_finance_actions', 'unsold_finance_type_check', "CHECK (action_type IN ('INVOICE_LINK', 'CREDIT_NOTE', 'TAX_ADJUSTMENT', 'RECEIVABLE_ADJUSTMENT', 'REFUND', 'REPLACEMENT'))"],
            ['unsold_return_finance_actions', 'unsold_finance_currency_check', "CHECK (currency ~ '^[A-Z]{3}$')"],
            ['unsold_return_finance_actions', 'unsold_finance_values_check', 'CHECK (case_record_version >= 1 AND (amount IS NULL OR amount >= 0) AND (balance_before IS NULL OR balance_before >= 0) AND (balance_after IS NULL OR balance_after >= 0))'],
            ['unsold_return_finance_actions', 'unsold_finance_shape_check', "CHECK ((action_type = 'INVOICE_LINK' AND amount IS NULL AND tax_code IS NULL AND balance_before IS NULL AND balance_after IS NULL AND reference_key IS NULL) OR (action_type = 'CREDIT_NOTE' AND amount > 0 AND tax_code IS NULL AND balance_before IS NULL AND balance_after IS NULL AND reference_key IS NOT NULL) OR (action_type = 'TAX_ADJUSTMENT' AND amount >= 0 AND tax_code IS NOT NULL AND balance_before IS NULL AND balance_after IS NULL AND reference_key IS NOT NULL) OR (action_type IN ('RECEIVABLE_ADJUSTMENT', 'REFUND', 'REPLACEMENT') AND amount >= 0 AND tax_code IS NULL AND balance_before IS NOT NULL AND balance_after IS NOT NULL AND reference_key IS NOT NULL))"],
            ['unsold_return_evidence', 'unsold_evidence_category_check', "CHECK (category IN ('RETURN_CONFIRMATION', 'RECEIPT_PHOTO', 'QUALITY_REPORT', 'FINANCE_DOCUMENT', 'OTHER'))"],
            ['unsold_return_evidence', 'unsold_evidence_values_check', "CHECK (case_record_version >= 1 AND size_bytes > 0 AND sha256 ~ '^[0-9a-f]{64}$')"],
            ['unsold_return_evidence', 'unsold_evidence_retention_check', 'CHECK (retention_until >= uploaded_at::date)'],
        ];

        return $checks;
    }

    private function partialUniqueIndexes(): array
    {
        return [
            'CREATE UNIQUE INDEX role_assignments_active_unique ON role_assignments (user_id, role_id, company_id, plant_id, party_id) NULLS NOT DISTINCT WHERE is_active',
            "CREATE UNIQUE INDEX approval_requests_pending_unique ON approval_requests (entity_type, entity_id, rule_code) WHERE status = 'PENDING'",
            'CREATE UNIQUE INDEX inventory_operation_lines_source_unique ON inventory_operation_lines (operation_id, source_position_id) WHERE source_position_id IS NOT NULL',
            'CREATE UNIQUE INDEX inventory_operation_lines_target_unique ON inventory_operation_lines (operation_id, target_position_id) WHERE target_position_id IS NOT NULL',
        ];
    }

    private function partialUniqueIndexNames(): array
    {
        return [
            'inventory_operation_lines_target_unique',
            'inventory_operation_lines_source_unique',
            'approval_requests_pending_unique',
            'role_assignments_active_unique',
        ];
    }

    private function createRelationshipTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION qtfoods_check_position_owner() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    expected_party_id uuid;
BEGIN
    SELECT party_id INTO expected_party_id
    FROM inventory_owners
    WHERE id = NEW.inventory_owner_id AND company_id = NEW.company_id;

    IF FOUND AND NEW.owner_party_id IS DISTINCT FROM expected_party_id THEN
        RAISE EXCEPTION 'stock position owner_party_id must match its inventory owner'
            USING ERRCODE = '23514', CONSTRAINT = 'stock_positions_owner_identity_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER stock_positions_owner_identity_guard
AFTER INSERT OR UPDATE ON stock_positions
DEFERRABLE INITIALLY IMMEDIATE
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_position_owner();

CREATE OR REPLACE FUNCTION qtfoods_check_owner_positions() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM stock_positions position
        WHERE position.inventory_owner_id = NEW.id
          AND position.company_id = NEW.company_id
          AND position.owner_party_id IS DISTINCT FROM NEW.party_id
    ) THEN
        RAISE EXCEPTION 'inventory owner party cannot diverge from existing stock positions'
            USING ERRCODE = '23514', CONSTRAINT = 'inventory_owners_position_identity_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER inventory_owners_position_identity_guard
AFTER UPDATE OF party_id, company_id ON inventory_owners
DEFERRABLE INITIALLY IMMEDIATE
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_owner_positions();
SQL);
    }

    private function dropRelationshipTriggers(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS inventory_owners_position_identity_guard ON inventory_owners;
DROP TRIGGER IF EXISTS stock_positions_owner_identity_guard ON stock_positions;
DROP FUNCTION IF EXISTS qtfoods_check_owner_positions();
DROP FUNCTION IF EXISTS qtfoods_check_position_owner();
SQL);
    }

    private function addConstraint(string $table, string $name, string $definition): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
    }

    private function dropConstraint(string $table, string $name): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
    }
};
