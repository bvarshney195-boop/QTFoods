<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('display_name');
            $table->string('party_kind', 32)->default('ORGANISATION')->after('legal_name');
            $table->text('notes')->nullable()->after('party_kind');
            $table->unsignedBigInteger('record_version')->default(1)->after('status');
            $table->text('status_reason')->nullable()->after('record_version');
            $table->timestampTz('status_changed_at')->nullable()->after('status_reason');
            $table->uuid('status_changed_by')->nullable()->after('status_changed_at');

            $table->index(['company_id', 'status', 'display_name'], 'parties_scope_status_name_index');
            $table->index(['company_id', 'party_kind'], 'parties_scope_kind_index');
            $table->unique(['id', 'company_id'], 'parties_id_company_unique');
            $table->foreign('company_id', 'parties_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('status_changed_by', 'parties_status_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        DB::table('parties')->whereNull('legal_name')->update([
            'legal_name' => DB::raw('display_name'),
        ]);

        Schema::create('party_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('party_id');
            $table->string('role_code', 32);
            $table->timestampsTz();

            $table->unique(['party_id', 'role_code'], 'party_roles_party_role_unique');
            $table->index(['company_id', 'role_code'], 'party_roles_scope_role_index');
            $table->foreign('company_id', 'party_roles_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'party_roles_party_fk')
                ->references(['id', 'company_id'])->on('parties')->cascadeOnDelete();
        });

        Schema::create('party_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('party_id');
            $table->string('label', 80);
            $table->string('address_type', 32);
            $table->string('line_1', 255);
            $table->string('line_2', 255)->nullable();
            $table->string('city', 120);
            $table->string('district', 120)->nullable();
            $table->string('region', 120);
            $table->string('postal_code', 24);
            $table->char('country_code', 2);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['party_id', 'address_type'], 'party_addresses_party_type_index');
            $table->index(['company_id', 'country_code'], 'party_addresses_scope_country_index');
            $table->foreign('company_id', 'party_addresses_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'party_addresses_party_fk')
                ->references(['id', 'company_id'])->on('parties')->cascadeOnDelete();
        });

        Schema::create('party_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('party_id');
            $table->string('name', 160);
            $table->string('job_title', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['party_id', 'is_primary'], 'party_contacts_party_primary_index');
            $table->index(['company_id', 'email'], 'party_contacts_scope_email_index');
            $table->foreign('company_id', 'party_contacts_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'party_contacts_party_fk')
                ->references(['id', 'company_id'])->on('parties')->cascadeOnDelete();
        });

        Schema::create('party_tax_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('party_id');
            $table->string('registration_type', 32);
            $table->string('registration_number', 80);
            $table->char('country_code', 2);
            $table->boolean('is_primary')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['company_id', 'registration_type', 'registration_number'],
                'party_tax_scope_type_number_unique'
            );
            $table->index(['party_id', 'registration_type'], 'party_tax_party_type_index');
            $table->foreign('company_id', 'party_tax_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'party_tax_party_fk')
                ->references(['id', 'company_id'])->on('parties')->cascadeOnDelete();
        });

        Schema::create('party_commercial_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('party_id')->unique();
            $table->char('currency_code', 3)->default('INR');
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->decimal('credit_limit', 20, 2)->default(0);
            $table->boolean('credit_hold')->default(false);
            $table->string('incoterm_code', 10)->nullable();
            $table->text('delivery_terms')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'currency_code'], 'party_terms_scope_currency_index');
            $table->foreign('company_id', 'party_terms_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'party_terms_party_fk')
                ->references(['id', 'company_id'])->on('parties')->cascadeOnDelete();
        });

        $this->addChecksAndPrimaryIndexes();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS party_addresses_primary_type_unique');
            DB::statement('DROP INDEX IF EXISTS party_contacts_primary_unique');
            DB::statement('DROP INDEX IF EXISTS party_tax_primary_type_unique');
            DB::statement('ALTER TABLE parties DROP CONSTRAINT IF EXISTS parties_status_check');
            DB::statement('ALTER TABLE parties DROP CONSTRAINT IF EXISTS parties_kind_check');
        }

        Schema::dropIfExists('party_commercial_terms');
        Schema::dropIfExists('party_tax_registrations');
        Schema::dropIfExists('party_contacts');
        Schema::dropIfExists('party_addresses');
        Schema::dropIfExists('party_roles');

        Schema::table('parties', function (Blueprint $table) {
            $table->dropForeign('parties_status_actor_fk');
            $table->dropForeign('parties_company_fk');
            $table->dropUnique('parties_id_company_unique');
            $table->dropIndex('parties_scope_kind_index');
            $table->dropIndex('parties_scope_status_name_index');
            $table->dropColumn([
                'legal_name', 'party_kind', 'notes', 'record_version', 'status_reason',
                'status_changed_at', 'status_changed_by',
            ]);
        });
    }

    private function addChecksAndPrimaryIndexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE parties ADD CONSTRAINT parties_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'ON_HOLD', 'INACTIVE'))");
        DB::statement("ALTER TABLE parties ADD CONSTRAINT parties_kind_check CHECK (party_kind IN ('ORGANISATION', 'INDIVIDUAL'))");
        DB::statement('ALTER TABLE parties ALTER COLUMN legal_name SET NOT NULL');
        DB::statement('ALTER TABLE parties ADD CONSTRAINT parties_version_check CHECK (record_version >= 1)');
        DB::statement("ALTER TABLE party_roles ADD CONSTRAINT party_roles_code_check CHECK (role_code IN ('CUSTOMER', 'SUPPLIER', 'CARRIER', 'SERVICE_PROVIDER'))");
        DB::statement("ALTER TABLE party_addresses ADD CONSTRAINT party_addresses_type_check CHECK (address_type IN ('REGISTERED', 'BILLING', 'SHIPPING', 'REMITTANCE', 'OTHER'))");
        DB::statement("ALTER TABLE party_addresses ADD CONSTRAINT party_addresses_country_check CHECK (country_code ~ '^[A-Z]{2}$')");
        DB::statement('ALTER TABLE party_contacts ADD CONSTRAINT party_contacts_channel_check CHECK (email IS NOT NULL OR phone IS NOT NULL OR mobile IS NOT NULL)');
        DB::statement("ALTER TABLE party_tax_registrations ADD CONSTRAINT party_tax_type_check CHECK (registration_type IN ('GSTIN', 'PAN', 'VAT', 'TIN', 'OTHER'))");
        DB::statement("ALTER TABLE party_tax_registrations ADD CONSTRAINT party_tax_country_check CHECK (country_code ~ '^[A-Z]{2}$')");
        DB::statement('ALTER TABLE party_tax_registrations ADD CONSTRAINT party_tax_validity_check CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)');
        DB::statement('ALTER TABLE party_commercial_terms ADD CONSTRAINT party_terms_credit_check CHECK (credit_limit >= 0)');
        DB::statement('ALTER TABLE party_commercial_terms ADD CONSTRAINT party_terms_payment_days_check CHECK (payment_terms_days BETWEEN 0 AND 3650)');
        DB::statement("ALTER TABLE party_commercial_terms ADD CONSTRAINT party_terms_currency_check CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE party_commercial_terms ADD CONSTRAINT party_terms_incoterm_check CHECK (incoterm_code IS NULL OR incoterm_code ~ '^[A-Z0-9]+$')");
        DB::statement('CREATE UNIQUE INDEX party_addresses_primary_type_unique ON party_addresses (party_id, address_type) WHERE is_primary');
        DB::statement('CREATE UNIQUE INDEX party_contacts_primary_unique ON party_contacts (party_id) WHERE is_primary');
        DB::statement('CREATE UNIQUE INDEX party_tax_primary_type_unique ON party_tax_registrations (party_id, registration_type) WHERE is_primary');
    }
};
