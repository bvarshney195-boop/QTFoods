<?php

namespace App\Shared\Deployment;

use App\Shared\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use RuntimeException;

final class ProductionAdminBootstrapper
{
    public const CONFIRMATION = 'CREATE_PRODUCTION_ADMIN';
    public const PASSWORD_CONFIRMATION = 'INITIALIZE_PRODUCTION_ADMIN_PASSWORD';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Create the first real organisation context and ERP administrator without
     * enabling demo authentication or introducing a shared preset credential.
     * Re-running the exact request is safe and does not reset an existing user.
     */
    public function bootstrap(array $input): array
    {
        $data = $this->validated($input);

        return DB::transaction(function () use ($data): array {
            $now = now();
            $company = DB::table('companies')->where('code', $data['company_code'])->lockForUpdate()->first();
            $companyCreated = false;
            if ($company === null) {
                $companyId = (string) Str::uuid();
                DB::table('companies')->insert([
                    'id' => $companyId,
                    'code' => $data['company_code'],
                    'legal_name' => $data['company_name'],
                    'display_name' => $data['company_name'],
                    'status' => 'ACTIVE',
                    'is_demo' => false,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $company = DB::table('companies')->where('id', $companyId)->first();
                $companyCreated = true;
            }
            $this->assertLegitimateContextRecord($company, 'company');

            $plant = DB::table('plants')
                ->where('company_id', $company->id)
                ->where('code', $data['plant_code'])
                ->lockForUpdate()
                ->first();
            $plantCreated = false;
            if ($plant === null) {
                $plantId = (string) Str::uuid();
                DB::table('plants')->insert([
                    'id' => $plantId,
                    'company_id' => $company->id,
                    'code' => $data['plant_code'],
                    'name' => $data['plant_name'],
                    'timezone' => $data['timezone'],
                    'status' => 'ACTIVE',
                    'is_demo' => false,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $plant = DB::table('plants')->where('id', $plantId)->first();
                $plantCreated = true;
            }
            $this->assertLegitimateContextRecord($plant, 'plant');

            $role = DB::table('roles')->where('code', 'ERP_ADMIN')->where('status', 'ACTIVE')->first();
            if ($role === null) {
                throw new RuntimeException('The active ERP_ADMIN system role is missing; load approved system definitions before bootstrapping an administrator.');
            }
            if ($role->company_id !== null && (string) $role->company_id !== (string) $company->id) {
                throw new RuntimeException('The ERP_ADMIN role belongs to a different company.');
            }

            $user = DB::table('users')->where('email', $data['email'])->lockForUpdate()->first();
            $userCreated = false;
            if ($user === null) {
                $userId = (string) Str::uuid();
                DB::table('users')->insert([
                    'id' => $userId,
                    'email' => $data['email'],
                    'name' => $data['name'],
                    // Initial access is established through the registered mailbox.
                    // A reset link lets the owner choose a password without placing
                    // a temporary secret in source, command arguments, or logs.
                    'password_hash' => Hash::make(Str::random(64)),
                    'status' => 'ACTIVE',
                    'is_demo' => false,
                    'email_verified_at' => $now,
                    'password_changed_at' => null,
                    'last_login_at' => null,
                    'last_login_ip' => null,
                    'mfa_secret' => null,
                    'mfa_enabled_at' => null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $user = DB::table('users')->where('id', $userId)->first();
                $userCreated = true;
            } else {
                if ((bool) $user->is_demo) {
                    throw new RuntimeException('Refusing to promote a demo identity. Use a distinct real email address.');
                }
                if ($user->status !== 'ACTIVE') {
                    throw new RuntimeException('The requested identity already exists but is not active; review it through the governed user-administration flow.');
                }
                if ($user->email_verified_at === null) {
                    throw new RuntimeException('The requested identity already exists but its email is unverified; complete the governed verification flow first.');
                }
            }

            $assignment = DB::table('role_assignments')
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->where('company_id', $company->id)
                ->where('plant_id', $plant->id)
                ->whereNull('party_id')
                ->where('is_active', true)
                ->first();
            $assignmentCreated = false;
            if ($assignment === null) {
                $assignmentId = (string) Str::uuid();
                DB::table('role_assignments')->insert([
                    'id' => $assignmentId,
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'company_id' => $company->id,
                    'plant_id' => $plant->id,
                    'party_id' => null,
                    'is_active' => true,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $assignment = DB::table('role_assignments')->where('id', $assignmentId)->first();
                $assignmentCreated = true;
            }

            if ($userCreated || $assignmentCreated || $companyCreated || $plantCreated) {
                $this->audit->record(
                    'BOOTSTRAP_PRODUCTION_ADMIN',
                    'user',
                    (string) $user->id,
                    (string) $user->id,
                    (string) $company->id,
                    (string) $plant->id,
                    'SUCCESS',
                    [
                        'entity_version' => (int) $user->record_version,
                        'reason_code' => 'AUTHORIZED_OPERATIONAL_BOOTSTRAP',
                        'safe_diff' => ['created' => [
                            'email' => $data['email'],
                            'role' => 'ERP_ADMIN',
                            'company_code' => $data['company_code'],
                            'plant_code' => $data['plant_code'],
                            'user' => $userCreated,
                            'assignment' => $assignmentCreated,
                            'company' => $companyCreated,
                            'plant' => $plantCreated,
                        ]],
                    ],
                );
            }

            return [
                'status' => $userCreated || $assignmentCreated || $companyCreated || $plantCreated
                    ? 'created'
                    : 'already_provisioned',
                'email' => $data['email'],
                'user_id' => (string) $user->id,
                'role' => 'ERP_ADMIN',
                'company' => ['id' => (string) $company->id, 'code' => (string) $company->code],
                'plant' => ['id' => (string) $plant->id, 'code' => (string) $plant->code],
                'role_assignment_id' => (string) $assignment->id,
                'mfa_required' => true,
                'password_setup_required' => $userCreated,
            ];
        });
    }

    /**
     * Establish the bootstrapped administrator's first password from a managed
     * deployment secret when mailbox delivery is unavailable. This deliberately
     * refuses to reset an account after its first password has been established.
     */
    public function initializePassword(string $email, mixed $password, string $confirmation): array
    {
        $email = Str::lower(trim($email));
        if (! hash_equals(self::PASSWORD_CONFIRMATION, $confirmation)) {
            throw new InvalidArgumentException('Initialising the administrator password requires explicit confirmation.');
        }

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024', Password::min(16)->letters()->numbers()->mixedCase()],
        ]);
        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->first());
        }

        return DB::transaction(function () use ($email, $password): array {
            $user = DB::table('users')->where('email', $email)->lockForUpdate()->first();
            if ($user === null
                || $user->status !== 'ACTIVE'
                || (bool) $user->is_demo
                || $user->email_verified_at === null) {
                throw new RuntimeException('The requested active, verified, non-demo administrator does not exist.');
            }

            $assignment = DB::table('role_assignments as assignment')
                ->join('roles as role', 'role.id', '=', 'assignment.role_id')
                ->join('companies as company', 'company.id', '=', 'assignment.company_id')
                ->leftJoin('plants as plant', 'plant.id', '=', 'assignment.plant_id')
                ->where('assignment.user_id', $user->id)
                ->where('assignment.is_active', true)
                ->where('role.code', 'ERP_ADMIN')
                ->where('role.status', 'ACTIVE')
                ->where('company.status', 'ACTIVE')
                ->where('company.is_demo', false)
                ->where(fn ($query) => $query->whereNull('assignment.effective_from')
                    ->orWhere('assignment.effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                    ->orWhere('assignment.effective_to', '>', now()))
                ->first([
                    'assignment.company_id',
                    'assignment.plant_id',
                    'plant.status as plant_status',
                    'plant.is_demo as plant_is_demo',
                ]);
            if ($assignment === null
                || ($assignment->plant_id !== null
                    && ($assignment->plant_status !== 'ACTIVE' || (bool) $assignment->plant_is_demo))) {
                throw new RuntimeException('The requested identity has no active ERP_ADMIN assignment in a legitimate context.');
            }

            if ($user->password_changed_at !== null) {
                return [
                    'status' => 'already_initialized',
                    'email' => $email,
                    'password_initialized' => true,
                ];
            }

            $now = now();
            $version = (int) $user->record_version + 1;
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make((string) $password),
                'password_changed_at' => $now,
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            $revokedResetTokens = DB::table('identity_tokens')
                ->where('user_id', $user->id)
                ->where('type', 'PASSWORD_RESET')
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);
            $revoked = DB::table('user_sessions')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'revoked_by_user_id' => $user->id,
                    'revoke_reason' => 'BOOTSTRAP_PASSWORD_INITIALIZED',
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => $now,
                ]);
            $this->audit->record(
                'INITIALIZE_PRODUCTION_ADMIN_PASSWORD',
                'user',
                (string) $user->id,
                (string) $user->id,
                (string) $assignment->company_id,
                $assignment->plant_id === null ? null : (string) $assignment->plant_id,
                'SUCCESS',
                [
                    'entity_version' => $version,
                    'reason_code' => 'AUTHORIZED_OPERATIONAL_BOOTSTRAP',
                    'safe_diff' => [
                        'password_initialized' => ['from' => false, 'to' => true],
                        'active_sessions_revoked' => ['from' => 0, 'to' => $revoked],
                        'password_reset_tokens_revoked' => ['from' => 0, 'to' => $revokedResetTokens],
                    ],
                ],
            );

            return [
                'status' => 'initialized',
                'email' => $email,
                'password_initialized' => true,
                'active_sessions_revoked' => $revoked,
                'password_reset_tokens_revoked' => $revokedResetTokens,
            ];
        });
    }

    private function validated(array $input): array
    {
        $normalised = [
            'email' => Str::lower(trim((string) ($input['email'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'company_code' => Str::upper(trim((string) ($input['company_code'] ?? ''))),
            'company_name' => trim((string) ($input['company_name'] ?? '')),
            'plant_code' => Str::upper(trim((string) ($input['plant_code'] ?? ''))),
            'plant_name' => trim((string) ($input['plant_name'] ?? '')),
            'timezone' => trim((string) ($input['timezone'] ?? '')),
            'confirmation' => (string) ($input['confirmation'] ?? ''),
        ];
        $validator = Validator::make($normalised, [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'company_code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'company_name' => ['required', 'string', 'max:255'],
            'plant_code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'plant_name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'confirmation' => ['required', 'in:'.self::CONFIRMATION],
        ]);
        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->first());
        }

        return $validator->validated();
    }

    private function assertLegitimateContextRecord(object $record, string $type): void
    {
        if ((bool) $record->is_demo) {
            throw new RuntimeException("Refusing to assign a real administrator to a demo {$type}.");
        }
        if ($record->status !== 'ACTIVE') {
            throw new RuntimeException("The selected production {$type} is not active.");
        }
    }
}
