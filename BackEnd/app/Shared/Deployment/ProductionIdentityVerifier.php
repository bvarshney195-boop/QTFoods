<?php

namespace App\Shared\Deployment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProductionIdentityVerifier
{
    private const DEMO_EMAILS = [
        'demo.user@qtfoods.local',
        'operations.user@qtfoods.local',
        'finance.user@qtfoods.local',
        'admin.user@qtfoods.local',
        'partner.user@qtfoods.local',
        'bi.user@qtfoods.local',
    ];

    private const DEMO_COMPANY_ID = '00000000-0000-4000-8000-000000000001';

    private const DEMO_PLANT_IDS = [
        '00000000-0000-4000-8000-000000000101',
        '00000000-0000-4000-8000-000000000102',
    ];

    public function verify(): array
    {
        $issues = [];
        foreach (['users', 'user_sessions', 'companies', 'plants', 'role_assignments'] as $table) {
            if (! Schema::hasTable($table)) {
                $issues[] = $this->issue('identity.schema_missing', "Required identity table {$table} is missing.");
            }
        }
        foreach ([['users', 'is_demo'], ['companies', 'is_demo'], ['plants', 'is_demo']] as [$table, $column]) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
                $issues[] = $this->issue('identity.classification_missing', "{$table}.{$column} is required to isolate synthetic records.");
            }
        }
        if ($issues !== []) {
            return $this->result($issues, []);
        }

        $users = DB::table('users')->get(['id', 'email', 'password_hash', 'status', 'is_demo']);
        $demoIds = $users->where('is_demo', true)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $activeDemoIds = $users->filter(static fn (object $user): bool => (bool) $user->is_demo && $user->status === 'ACTIVE')
            ->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $unclassifiedDemoEmails = $users->filter(static fn (object $user): bool =>
            in_array(strtolower((string) $user->email), self::DEMO_EMAILS, true) && ! (bool) $user->is_demo
        )->pluck('email')->values()->all();
        $presetCredentialEmails = $users->filter(static fn (object $user): bool =>
            password_verify('prototype', (string) $user->password_hash)
        )->pluck('email')->values()->all();
        $activeDemoSessions = $demoIds === [] ? 0 : DB::table('user_sessions')
            ->whereIn('user_id', $demoIds)->whereNull('revoked_at')->count();

        $demoCompany = DB::table('companies')->where('id', self::DEMO_COMPANY_ID)->first(['id', 'is_demo']);
        $unclassifiedDemoCompany = $demoCompany !== null && ! (bool) $demoCompany->is_demo;
        $unclassifiedDemoPlants = DB::table('plants')->whereIn('id', self::DEMO_PLANT_IDS)
            ->where('is_demo', false)->count();
        $assignmentLeaks = DB::table('role_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->leftJoin('companies as company', 'company.id', '=', 'assignment.company_id')
            ->leftJoin('plants as plant', 'plant.id', '=', 'assignment.plant_id')
            ->where('assignment.is_active', true)
            ->where('user.is_demo', false)
            ->where(fn ($query) => $query->where('company.is_demo', true)->orWhere('plant.is_demo', true))
            ->count();

        if ((bool) config('deployment.allow_demo_authentication', false)) {
            $issues[] = $this->issue('identity.demo_authentication_enabled', 'Demo authentication is enabled.');
        }
        if ((bool) config('deployment.allow_demo_seeders', false)) {
            $issues[] = $this->issue('identity.demo_seeders_enabled', 'Demo seeders are enabled.');
        }
        if ($activeDemoIds !== []) {
            $issues[] = $this->issue('identity.demo_principal_active', 'One or more demo principals remain active.', count($activeDemoIds));
        }
        if ($activeDemoSessions > 0) {
            $issues[] = $this->issue('identity.demo_session_active', 'One or more demo sessions remain usable.', $activeDemoSessions);
        }
        if ($presetCredentialEmails !== []) {
            $issues[] = $this->issue('identity.preset_credential_present', 'A known shared preset credential is still valid.', count($presetCredentialEmails));
        }
        if ($unclassifiedDemoEmails !== []) {
            $issues[] = $this->issue('identity.demo_principal_unclassified', 'A known demo principal is not classified as synthetic.', count($unclassifiedDemoEmails));
        }
        if ($unclassifiedDemoCompany || $unclassifiedDemoPlants > 0) {
            $issues[] = $this->issue('identity.synthetic_context_unclassified', 'A known training company or plant is not classified as synthetic.', ($unclassifiedDemoCompany ? 1 : 0) + $unclassifiedDemoPlants);
        }
        if ($assignmentLeaks > 0) {
            $issues[] = $this->issue('identity.synthetic_context_assignment_leak', 'A non-demo user has an active assignment to a synthetic company or plant.', $assignmentLeaks);
        }

        return $this->result($issues, [
            'users_checked' => $users->count(),
            'demo_principals' => count($demoIds),
            'active_demo_principals' => count($activeDemoIds),
            'active_demo_sessions' => $activeDemoSessions,
            'preset_credentials' => count($presetCredentialEmails),
            'unclassified_demo_principals' => count($unclassifiedDemoEmails),
            'unclassified_synthetic_contexts' => ($unclassifiedDemoCompany ? 1 : 0) + $unclassifiedDemoPlants,
            'synthetic_assignment_leaks' => $assignmentLeaks,
        ]);
    }

    private function issue(string $code, string $message, ?int $count = null): array
    {
        return array_filter([
            'code' => $code,
            'message' => $message,
            'count' => $count,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function result(array $issues, array $metrics): array
    {
        return [
            'status' => $issues === [] ? 'pass' : 'fail',
            'checked_at' => now()->utc()->toIso8601String(),
            'metrics' => $metrics,
            'issues' => $issues,
        ];
    }
}
