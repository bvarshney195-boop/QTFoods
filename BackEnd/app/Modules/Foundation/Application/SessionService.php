<?php

namespace App\Modules\Foundation\Application;

use App\Modules\Foundation\Domain\User;
use App\Shared\Approval\ApprovalAuthorityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SessionService
{
    public function __construct(private readonly ApprovalAuthorityService $approvalAuthority) {}

    public function payload(User $user, Request $request): array
    {
        $contexts = $this->contexts($user);
        $selectedContext = $this->selectedContext($request, $contexts);
        $access = $this->access($user, $selectedContext);

        return [
            'user' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'security' => [
                'email_verified' => $user->email_verified_at !== null,
                'mfa_enabled' => $user->mfa_enabled_at !== null,
                'mfa_required' => (bool) $user->mfa_required_by_admin || $this->requiresRoleMfa($user),
                'password_change_required' => $user->password_changed_at === null,
                'password_changed_at' => $this->timestamp($user->password_changed_at),
                'last_login_at' => $this->timestamp($user->last_login_at),
                'current_session_id' => $request->session()->get('identity.device_session_id'),
            ],
            'roles' => $access['roles'],
            'allowed_screens' => $access['screens'],
            'allowed_actions' => $access['actions'],
            'delegated_authorities' => $access['delegations'],
            'contexts' => $contexts->values()->all(),
            'selected_context' => $selectedContext,
        ];
    }

    private function requiresRoleMfa(User $user): bool
    {
        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $user->id)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->where('role.code', 'ERP_ADMIN')
            ->where(fn (Builder $query) => $query->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->exists();
    }

    public function selectContext(User $user, Request $request, string $companyId, ?string $plantId): array
    {
        $context = $this->contexts($user)->first(
            fn (array $candidate) => $candidate['company_id'] === $companyId
                && $candidate['plant_id'] === $plantId
        );

        if (! $context) {
            throw ValidationException::withMessages([
                'context' => ['You are not authorised for the selected company and plant.'],
            ]);
        }

        $request->session()->put([
            'erp.company_id' => $companyId,
            'erp.plant_id' => $plantId,
        ]);

        return $this->payload($user, $request);
    }

    public function hasSelectedContext(User $user, Request $request): bool
    {
        return $this->currentContext($user, $request) !== null;
    }

    public function currentContext(User $user, Request $request): ?array
    {
        return $this->selectedContext($request, $this->contexts($user));
    }

    public function canViewScreen(User $user, Request $request, string $screenCode): bool
    {
        $context = $this->selectedContext($request, $this->contexts($user));

        return $context !== null
            && in_array($screenCode, $this->access($user, $context)['screens'], true);
    }

    public function can(User $user, Request $request, string $permission): bool
    {
        $context = $this->currentContext($user, $request);

        return $context !== null
            && in_array($permission, $this->access($user, $context)['permissions'], true);
    }

    public function permissions(User $user, Request $request): array
    {
        $context = $this->currentContext($user, $request);

        return $context === null ? [] : $this->access($user, $context)['permissions'];
    }

    private function contexts(User $user): Collection
    {
        return $this->activeAssignments($user)
            ->join('companies as c', 'c.id', '=', 'ra.company_id')
            ->leftJoin('plants as p', 'p.id', '=', 'ra.plant_id')
            ->where('c.status', 'ACTIVE')
            ->where(fn (Builder $q) => $q->whereNull('p.id')->orWhere('p.status', 'ACTIVE'))
            ->when(! config('deployment.allow_demo_authentication', false), fn (Builder $query) => $query
                ->where('c.is_demo', false)
                ->where(fn (Builder $plant) => $plant->whereNull('p.id')->orWhere('p.is_demo', false)))
            ->select([
                'c.id as company_id',
                'c.display_name as company_name',
                'p.id as plant_id',
                'p.name as plant_name',
            ])
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'company_id' => (string) $row->company_id,
                'company_name' => $row->company_name,
                'plant_id' => $row->plant_id !== null ? (string) $row->plant_id : null,
                'plant_name' => $row->plant_name,
            ]);
    }

    private function selectedContext(Request $request, Collection $contexts): ?array
    {
        $companyId = $request->session()->get('erp.company_id');
        $plantId = $request->session()->get('erp.plant_id');

        if (! is_string($companyId)) {
            return null;
        }

        $context = $contexts->first(
            fn (array $candidate) => $candidate['company_id'] === $companyId
                && $candidate['plant_id'] === $plantId
        );

        if (! $context) {
            $request->session()->forget(['erp.company_id', 'erp.plant_id']);
        }

        return $context ?: null;
    }

    private function access(User $user, ?array $context): array
    {
        $query = $this->activeAssignments($user)
            ->join('roles as r', 'r.id', '=', 'ra.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('r.status', 'ACTIVE')
            ->where('p.status', 'ACTIVE');

        if ($context) {
            $query
                ->where(fn (Builder $q) => $q
                    ->whereNull('ra.company_id')
                    ->orWhere('ra.company_id', $context['company_id']))
                ->where(fn (Builder $q) => $q
                    ->whereNull('ra.plant_id')
                    ->orWhere('ra.plant_id', $context['plant_id']));
        }

        $rows = $query->select(['r.code as role_code', 'p.code as permission_code'])->distinct()->get();

        $delegations = $context === null ? [] : $this->approvalAuthority->activeDelegations(
            (string) $user->id,
            $context['company_id'],
            $context['plant_id']
        );
        $permissions = $rows->pluck('permission_code')
            ->merge(collect($delegations)->pluck('permission_code'))
            ->unique()->sort()->values();

        $screens = $permissions
            ->filter(fn (string $permission) => str_starts_with($permission, 'SCREEN:') && str_ends_with($permission, ':VIEW'))
            ->map(fn (string $permission) => substr($permission, 7, -5))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $actions = $permissions
            ->filter(fn (string $permission) => str_starts_with($permission, 'ACTION:'))
            ->values()
            ->all();

        return [
            'roles' => $rows->pluck('role_code')->unique()->sort()->values()->all(),
            'screens' => $screens,
            'actions' => $actions,
            'permissions' => $permissions->all(),
            'delegations' => $delegations,
        ];
    }

    private function activeAssignments(User $user): Builder
    {
        return DB::table('role_assignments as ra')
            ->when($user->status !== 'ACTIVE', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('ra.user_id', $user->id)
            ->where('ra.is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('ra.effective_from')->orWhere('ra.effective_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ra.effective_to')->orWhere('ra.effective_to', '>', now()));
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : \Carbon\CarbonImmutable::parse($value)->toISOString();
    }
}
