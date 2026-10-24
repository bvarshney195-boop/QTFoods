<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('record_version')->default(1);
            $table->index(['status', 'name'], 'users_status_name_index');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('record_version')->default(1);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('record_version')->default(1);
        });

        Schema::table('role_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('record_version')->default(1);
            $table->index(
                ['company_id', 'plant_id', 'is_active', 'user_id'],
                'role_assignments_scope_active_user_index'
            );
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->uuid('parent_location_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->index(
                ['company_id', 'plant_id', 'status', 'location_type'],
                'locations_scope_status_type_index'
            );
        });

        DB::table('roles')->update([
            'status' => 'ACTIVE',
            'is_system' => true,
            'record_version' => 1,
        ]);
        DB::table('permissions')->update([
            'status' => 'ACTIVE',
            'is_system' => true,
            'record_version' => 1,
        ]);

        Schema::table('plants', function (Blueprint $table) {
            $table->foreign('company_id', 'plants_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->foreign('company_id', 'roles_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->foreign('company_id', 'permissions_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
        });
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->foreign('role_id', 'role_permissions_role_fk')
                ->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('permission_id', 'role_permissions_permission_fk')
                ->references('id')->on('permissions')->restrictOnDelete();
        });
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->foreign('user_id', 'role_assignments_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('role_id', 'role_assignments_role_fk')
                ->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('company_id', 'role_assignments_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('plant_id', 'role_assignments_plant_fk')
                ->references('id')->on('plants')->restrictOnDelete();
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->foreign('company_id', 'locations_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('plant_id', 'locations_plant_fk')
                ->references('id')->on('plants')->restrictOnDelete();
            $table->foreign('parent_location_id', 'locations_parent_fk')
                ->references('id')->on('locations')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                "ALTER TABLE companies ADD CONSTRAINT companies_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'INACTIVE'))",
                "ALTER TABLE plants ADD CONSTRAINT plants_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'INACTIVE'))",
                "ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))",
                "ALTER TABLE roles ADD CONSTRAINT roles_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))",
                "ALTER TABLE permissions ADD CONSTRAINT permissions_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))",
                "ALTER TABLE locations ADD CONSTRAINT locations_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))",
                'ALTER TABLE locations ADD CONSTRAINT locations_parent_not_self_check CHECK (parent_location_id IS NULL OR parent_location_id <> id)',
                'ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_effective_period_check CHECK (effective_from IS NULL OR effective_to IS NULL OR effective_to > effective_from)',
            ] as $statement) {
                DB::statement($statement);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'companies_status_check' => 'companies',
                'plants_status_check' => 'plants',
                'users_status_check' => 'users',
                'roles_status_check' => 'roles',
                'permissions_status_check' => 'permissions',
                'locations_status_check' => 'locations',
                'locations_parent_not_self_check' => 'locations',
                'role_assignments_effective_period_check' => 'role_assignments',
            ] as $constraint => $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign('locations_parent_fk');
            $table->dropForeign('locations_plant_fk');
            $table->dropForeign('locations_company_fk');
        });
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropForeign('role_assignments_plant_fk');
            $table->dropForeign('role_assignments_company_fk');
            $table->dropForeign('role_assignments_role_fk');
            $table->dropForeign('role_assignments_user_fk');
        });
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->dropForeign('role_permissions_permission_fk');
            $table->dropForeign('role_permissions_role_fk');
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropForeign('permissions_company_fk');
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign('roles_company_fk');
        });
        Schema::table('plants', function (Blueprint $table) {
            $table->dropForeign('plants_company_fk');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex('locations_scope_status_type_index');
            $table->dropColumn(['parent_location_id', 'description', 'record_version']);
        });
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropIndex('role_assignments_scope_active_user_index');
            $table->dropColumn('record_version');
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['company_id', 'description', 'status', 'is_system', 'record_version']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['company_id', 'description', 'status', 'is_system', 'record_version']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_status_name_index');
            $table->dropColumn('record_version');
        });
    }
};
