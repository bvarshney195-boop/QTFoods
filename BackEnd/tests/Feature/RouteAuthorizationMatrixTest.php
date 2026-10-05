<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureContextSelected;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureScreenAccess;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class RouteAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';

    private const PUBLIC_ROUTES = [
        'GET api/v1/auth/csrf',
        'GET api/v1/auth/invitations/{token}',
        'POST api/v1/auth/email/verification/request',
        'POST api/v1/auth/email/verify',
        'POST api/v1/auth/invitations/accept',
        'POST api/v1/auth/challenge',
        'POST api/v1/auth/login',
        'POST api/v1/auth/mfa/challenge',
        'POST api/v1/auth/password/forgot',
        'POST api/v1/auth/password/reset',
    ];

    private const ACCOUNT_ROUTES = [
        'GET api/v1/auth/sessions',
        'GET api/v1/contexts',
        'GET api/v1/me',
        'POST api/v1/auth/logout',
        'POST api/v1/auth/mfa/confirm',
        'POST api/v1/auth/mfa/disable',
        'POST api/v1/auth/mfa/recovery-codes',
        'POST api/v1/auth/mfa/setup',
        'POST api/v1/auth/password/change',
        'POST api/v1/auth/sessions/revoke-others',
        'POST api/v1/auth/sessions/{sessionId}/revoke',
        'POST api/v1/contexts/select',
    ];

    private const STATE_AUTHORIZED_ROUTES = [
        'POST api/v1/procurement/requisition-approvals/{approvalId}/approve' => [
            'screen' => 'PUR-REQ',
            'entity_type' => 'purchase_requisition',
            'permission_prefix' => 'ACTION:PUR-REQ:APPROVE',
        ],
        'POST api/v1/procurement/requisition-approvals/{approvalId}/reject' => [
            'screen' => 'PUR-REQ',
            'entity_type' => 'purchase_requisition',
            'permission_prefix' => 'ACTION:PUR-REQ:APPROVE',
        ],
        'POST api/v1/sales/unsold-return-approvals/{approvalId}/approve' => [
            'screen' => 'RET-UNSOLD',
            'entity_type' => 'unsold_return_loss',
            'permission_prefix' => 'ACTION:RET-UNSOLD:APPROVE',
        ],
        'POST api/v1/sales/unsold-return-approvals/{approvalId}/reject' => [
            'screen' => 'RET-UNSOLD',
            'entity_type' => 'unsold_return_loss',
            'permission_prefix' => 'ACTION:RET-UNSOLD:APPROVE',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_every_v1_route_has_an_explicit_security_classification(): void
    {
        $routes = $this->v1Routes();
        $keys = $routes->map(fn (RoutingRoute $route): string => $this->routeKey($route))->all();
        $this->assertCount(count(array_unique($keys)), $keys, 'Duplicate v1 method/URI registrations were found.');

        $public = $routes
            ->reject(fn (RoutingRoute $route): bool => in_array('auth', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route): string => $this->routeKey($route))->sort()->values()->all();
        $expectedPublic = self::PUBLIC_ROUTES;
        sort($expectedPublic);
        $this->assertSame($expectedPublic, $public, 'The reviewed public-route allowlist changed.');

        $account = $routes
            ->filter(fn (RoutingRoute $route): bool => in_array('auth', $route->gatherMiddleware(), true))
            ->reject(fn (RoutingRoute $route): bool => in_array('erp.context', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route): string => $this->routeKey($route))->sort()->values()->all();
        $expectedAccount = self::ACCOUNT_ROUTES;
        sort($expectedAccount);
        $this->assertSame($expectedAccount, $account, 'The reviewed account-route allowlist changed.');

        $errors = [];
        $stateAuthorized = [];
        $permissionCodes = [];

        foreach ($this->businessCatalog() as $route) {
            $middleware = $route['middleware'];
            $key = $route['key'];

            foreach (['auth', 'erp.device', 'erp.context'] as $requiredMiddleware) {
                if (! in_array($requiredMiddleware, $middleware, true)) {
                    $errors[] = "{$key} is missing {$requiredMiddleware}.";
                }
            }

            if ($route['screen'] === null) {
                $errors[] = "{$key} does not declare exactly one screen gate.";
                continue;
            }

            $permissionCodes[] = "SCREEN:{$route['screen']}:VIEW";
            if ($route['action'] !== null) {
                $permissionCodes[] = $route['action'];
                $actionParts = explode(':', $route['action'], 3);
                if (($actionParts[0] ?? null) !== 'ACTION' || ($actionParts[1] ?? null) !== $route['screen']) {
                    $errors[] = "{$key} action {$route['action']} does not match screen {$route['screen']}.";
                }
            }

            if ($route['mutation'] && $route['action'] === null) {
                $stateAuthorized[] = $key;
                if (! isset(self::STATE_AUTHORIZED_ROUTES[$key])) {
                    $errors[] = "{$key} mutates state without a static or reviewed state-derived action gate.";
                }
            }
        }

        sort($stateAuthorized);
        $expectedStateAuthorized = array_keys(self::STATE_AUTHORIZED_ROUTES);
        sort($expectedStateAuthorized);
        $this->assertSame($expectedStateAuthorized, $stateAuthorized);
        $this->assertSame([], $errors, implode(PHP_EOL, $errors));

        $permissionCodes = array_values(array_unique($permissionCodes));
        sort($permissionCodes);
        $activePermissions = DB::table('permissions')
            ->whereIn('code', $permissionCodes)
            ->where('status', 'ACTIVE')
            ->pluck('code')->unique()->sort()->values()->all();
        $this->assertSame($permissionCodes, $activePermissions, 'A route references a missing or inactive permission.');

        $rolePermissions = $this->permissionsByRole();
        foreach ($this->businessCatalog() as $route) {
            $screenPermission = "SCREEN:{$route['screen']}:VIEW";
            $rolesWithScreen = collect($rolePermissions)
                ->filter(fn (array $permissions): bool => in_array($screenPermission, $permissions, true));
            $this->assertNotEmpty($rolesWithScreen, "{$route['key']} has no role with screen access.");

            if ($route['action'] === null) {
                continue;
            }

            $rolesWithAction = collect($rolePermissions)
                ->filter(fn (array $permissions): bool => in_array($route['action'], $permissions, true));
            $this->assertNotEmpty($rolesWithAction, "{$route['key']} has no role with action access.");
            foreach ($rolesWithAction as $roleCode => $permissions) {
                $this->assertContains(
                    $screenPermission,
                    $permissions,
                    "{$roleCode} has {$route['action']} without {$screenPermission}.",
                );
            }
        }
    }

    public function test_every_protected_route_rejects_anonymous_and_contextless_requests_before_controller_code(): void
    {
        Log::spy();
        $routes = $this->v1Routes();
        $public = array_flip(self::PUBLIC_ROUTES);

        foreach ($routes as $route) {
            $key = $this->routeKey($route);
            if (isset($public[$key])) {
                continue;
            }

            $response = $this->call(
                $this->primaryMethod($route),
                '/'.$this->materializeUri($route),
                server: ['HTTP_ACCEPT' => 'application/json'],
            );
            $this->assertSame(401, $response->getStatusCode(), "{$key} did not reject an anonymous request.");
            $this->assertSame('UNAUTHENTICATED', $response->json('error.code'), $key);
        }

        $this->actingAs(User::query()->findOrFail(self::ADMIN_USER_ID))->withSession([]);
        foreach ($this->businessRoutes() as $route) {
            $key = $this->routeKey($route);
            $response = $this->call(
                $this->primaryMethod($route),
                '/'.$this->materializeUri($route),
                server: ['HTTP_ACCEPT' => 'application/json'],
            );
            $this->assertSame(409, $response->getStatusCode(), "{$key} ran without an ERP context.");
            $this->assertSame('CONTEXT_REQUIRED', $response->json('error.code'), $key);
        }
    }

    public function test_every_seeded_role_context_resolves_every_business_route_gate(): void
    {
        $catalog = $this->businessCatalog();
        $screenCodes = collect($catalog)->pluck('screen')->filter()->unique()->sort()->values()->all();
        $actionCodes = collect($catalog)->pluck('action')->filter()->unique()->sort()->values()->all();
        $screenMiddleware = $this->app->make(EnsureScreenAccess::class);
        $permissionMiddleware = $this->app->make(EnsurePermission::class);
        $routeOutcomes = collect($catalog)->mapWithKeys(fn (array $route): array => [
            $route['key'] => ['allowed' => 0, 'denied' => 0],
        ])->all();
        $gateOutcomes = [];
        $seenRoles = [];

        foreach ($this->roleContexts() as $context) {
            $user = User::query()->findOrFail($context->user_id);
            $request = $this->requestFor($user, (string) $context->company_id, $context->plant_id);
            $expected = $this->expectedAccess(
                (string) $user->id,
                (string) $context->company_id,
                $context->plant_id,
            );
            $payload = $this->app->make(SessionService::class)->payload($user, $request);

            $this->assertSame($expected['roles'], $payload['roles']);
            $this->assertSame($expected['screens'], $payload['allowed_screens']);
            $this->assertSame($expected['actions'], $payload['allowed_actions']);
            $this->assertSame((string) $context->company_id, $payload['selected_context']['company_id']);
            $this->assertSame($context->plant_id, $payload['selected_context']['plant_id']);
            $seenRoles = [...$seenRoles, ...$expected['roles']];

            $screenDecisions = [];
            foreach ($screenCodes as $screenCode) {
                $expectedAllowed = in_array("SCREEN:{$screenCode}:VIEW", $expected['permissions'], true);
                $actualAllowed = $this->screenAllows($screenMiddleware, $request, $screenCode);
                $this->assertSame(
                    $expectedAllowed,
                    $actualAllowed,
                    implode(' ', [$context->role_code, $context->plant_id, $screenCode]),
                );
                $screenDecisions[$screenCode] = $actualAllowed;
                $this->recordOutcome($gateOutcomes, "SCREEN:{$screenCode}:VIEW", $actualAllowed);
            }

            $actionDecisions = [];
            foreach ($actionCodes as $actionCode) {
                $expectedAllowed = in_array($actionCode, $expected['permissions'], true);
                $actualAllowed = $this->permissionAllows($permissionMiddleware, $request, $actionCode);
                $this->assertSame(
                    $expectedAllowed,
                    $actualAllowed,
                    implode(' ', [$context->role_code, $context->plant_id, $actionCode]),
                );
                $actionDecisions[$actionCode] = $actualAllowed;
                $this->recordOutcome($gateOutcomes, $actionCode, $actualAllowed);
            }

            foreach ($catalog as $route) {
                $allowed = ($screenDecisions[$route['screen']] ?? false)
                    && ($route['action'] === null || ($actionDecisions[$route['action']] ?? false));
                $routeOutcomes[$route['key']][$allowed ? 'allowed' : 'denied']++;
            }
        }

        $activeRoles = DB::table('roles')->where('status', 'ACTIVE')->pluck('code')->sort()->values()->all();
        $seenRoles = array_values(array_unique($seenRoles));
        sort($seenRoles);
        $this->assertSame($activeRoles, $seenRoles, 'An active role has no tested active context.');

        foreach ($gateOutcomes as $permissionCode => $outcome) {
            $this->assertGreaterThan(0, $outcome['allowed'], "{$permissionCode} has no allowed role/context.");
            $this->assertGreaterThan(0, $outcome['denied'], "{$permissionCode} has no denied role/context.");
        }
        foreach ($routeOutcomes as $routeKey => $outcome) {
            $this->assertGreaterThan(0, $outcome['allowed'], "{$routeKey} has no allowed role/context.");
            $this->assertGreaterThan(0, $outcome['denied'], "{$routeKey} has no denied role/context.");
        }
    }

    public function test_inactive_and_out_of_window_identity_state_revokes_context_and_action_authority(): void
    {
        $assignment = DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', self::SALES_USER_ID)
            ->where('assignment.company_id', self::COMPANY_ID)
            ->where('assignment.plant_id', self::TRAINING_PLANT_ID)
            ->where('role.code', 'SALES_MANAGER')
            ->first(['assignment.id', 'assignment.role_id']);
        $this->assertNotNull($assignment);

        $contextMiddleware = $this->app->make(EnsureContextSelected::class);
        $screenMiddleware = $this->app->make(EnsureScreenAccess::class);
        $permissionMiddleware = $this->app->make(EnsurePermission::class);
        $user = User::query()->findOrFail(self::SALES_USER_ID);
        $activeRequest = $this->requestFor($user, self::COMPANY_ID, self::TRAINING_PLANT_ID);
        $this->assertSame(204, $contextMiddleware->handle(
            $activeRequest,
            static fn (): Response => new Response(status: 204),
        )->getStatusCode());
        $this->assertTrue($this->screenAllows($screenMiddleware, $activeRequest, 'CRM-LEAD'));
        $this->assertTrue($this->permissionAllows($permissionMiddleware, $activeRequest, 'ACTION:CRM-LEAD:CREATE'));

        DB::table('role_assignments')->where('id', $assignment->id)->update(['is_active' => false]);
        $this->assertContextRejected($contextMiddleware, $user);

        DB::table('role_assignments')->where('id', $assignment->id)->update([
            'is_active' => true,
            'effective_from' => now()->addDay(),
            'effective_to' => null,
        ]);
        $this->assertContextRejected($contextMiddleware, $user);

        DB::table('role_assignments')->where('id', $assignment->id)->update([
            'effective_from' => now()->subDays(2),
            'effective_to' => now()->subDay(),
        ]);
        $this->assertContextRejected($contextMiddleware, $user);

        DB::table('role_assignments')->where('id', $assignment->id)->update([
            'effective_from' => null,
            'effective_to' => null,
        ]);
        DB::table('users')->where('id', $user->id)->update(['status' => 'INACTIVE']);
        $this->assertContextRejected($contextMiddleware, $user->refresh());
        DB::table('users')->where('id', $user->id)->update(['status' => 'ACTIVE']);
        $user->refresh();

        DB::table('roles')->where('id', $assignment->role_id)->update(['status' => 'INACTIVE']);
        $this->assertFalse($this->screenAllows(
            $screenMiddleware,
            $this->requestFor($user, self::COMPANY_ID, self::TRAINING_PLANT_ID),
            'CRM-LEAD',
        ));
        DB::table('roles')->where('id', $assignment->role_id)->update(['status' => 'ACTIVE']);

        $screenPermissionId = DB::table('permissions')->where('code', 'SCREEN:CRM-LEAD:VIEW')->value('id');
        DB::table('permissions')->where('id', $screenPermissionId)->update(['status' => 'INACTIVE']);
        $this->assertFalse($this->screenAllows(
            $screenMiddleware,
            $this->requestFor($user, self::COMPANY_ID, self::TRAINING_PLANT_ID),
            'CRM-LEAD',
        ));
        DB::table('permissions')->where('id', $screenPermissionId)->update(['status' => 'ACTIVE']);

        $actionPermissionId = DB::table('permissions')->where('code', 'ACTION:CRM-LEAD:CREATE')->value('id');
        DB::table('permissions')->where('id', $actionPermissionId)->update(['status' => 'INACTIVE']);
        $this->assertFalse($this->permissionAllows(
            $permissionMiddleware,
            $this->requestFor($user, self::COMPANY_ID, self::TRAINING_PLANT_ID),
            'ACTION:CRM-LEAD:CREATE',
        ));
    }

    public function test_state_derived_approval_routes_have_live_authority_permissions(): void
    {
        $catalog = collect($this->businessCatalog())->keyBy('key');
        $bands = DB::table('approval_rule_bands as band')
            ->join('approval_rules as rule', 'rule.id', '=', 'band.approval_rule_id')
            ->where('rule.status', 'ACTIVE')
            ->whereIn('rule.entity_type', ['purchase_requisition', 'unsold_return_loss'])
            ->get([
                'rule.entity_type',
                'band.required_permission',
                'band.escalation_permission',
            ]);
        $this->assertNotEmpty($bands);

        foreach (self::STATE_AUTHORIZED_ROUTES as $routeKey => $contract) {
            $route = $catalog->get($routeKey);
            $this->assertNotNull($route, "Reviewed state-authorized route {$routeKey} is missing.");
            $this->assertSame($contract['screen'], $route['screen']);
            $this->assertNull($route['action']);

            $entityBands = $bands->where('entity_type', $contract['entity_type']);
            $this->assertNotEmpty($entityBands, "{$contract['entity_type']} has no active authority band.");
            foreach ($entityBands as $band) {
                foreach ([$band->required_permission, $band->escalation_permission] as $permissionCode) {
                    $this->assertStringStartsWith($contract['permission_prefix'], $permissionCode);
                    $permission = DB::table('permissions')
                        ->where('code', $permissionCode)
                        ->where('status', 'ACTIVE')
                        ->first();
                    $this->assertNotNull($permission, "Dynamic authority {$permissionCode} is unavailable.");

                    $authorisedRoles = DB::table('role_permissions as role_permission')
                        ->join('roles as role', 'role.id', '=', 'role_permission.role_id')
                        ->where('role_permission.permission_id', $permission->id)
                        ->where('role.status', 'ACTIVE')
                        ->pluck('role.id');
                    $this->assertNotEmpty($authorisedRoles, "Dynamic authority {$permissionCode} has no active role.");

                    $screenPermissionId = DB::table('permissions')
                        ->where('code', "SCREEN:{$contract['screen']}:VIEW")
                        ->where('status', 'ACTIVE')
                        ->value('id');
                    $this->assertTrue(DB::table('role_permissions')
                        ->whereIn('role_id', $authorisedRoles)
                        ->where('permission_id', $screenPermissionId)
                        ->exists(), "Dynamic authority {$permissionCode} cannot reach {$contract['screen']}.");
                }
            }
        }
    }

    /** @return Collection<int, RoutingRoute> */
    private function v1Routes(): Collection
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->values();
    }

    /** @return Collection<int, RoutingRoute> */
    private function businessRoutes(): Collection
    {
        return $this->v1Routes()->filter(
            fn (RoutingRoute $route): bool => in_array('erp.context', $route->gatherMiddleware(), true),
        )->values();
    }

    /** @return array<int, array<string, mixed>> */
    private function businessCatalog(): array
    {
        return $this->businessRoutes()->map(function (RoutingRoute $route): array {
            $middleware = $route->gatherMiddleware();
            $screens = array_values(array_filter(
                $middleware,
                static fn (string $name): bool => str_starts_with($name, 'erp.screen:'),
            ));
            $actions = array_values(array_filter(
                $middleware,
                static fn (string $name): bool => str_starts_with($name, 'erp.permission:'),
            ));

            return [
                'key' => $this->routeKey($route),
                'middleware' => $middleware,
                'screen' => count($screens) === 1 ? substr($screens[0], strlen('erp.screen:')) : null,
                'action' => count($actions) === 1 ? substr($actions[0], strlen('erp.permission:')) : null,
                'mutation' => ! in_array($this->primaryMethod($route), ['GET', 'HEAD', 'OPTIONS'], true),
            ];
        })->all();
    }

    private function routeKey(RoutingRoute $route): string
    {
        return $this->primaryMethod($route).' '.$route->uri();
    }

    private function primaryMethod(RoutingRoute $route): string
    {
        return collect($route->methods())->first(
            static fn (string $method): bool => ! in_array($method, ['HEAD', 'OPTIONS'], true),
        ) ?? $route->methods()[0];
    }

    private function materializeUri(RoutingRoute $route): string
    {
        return preg_replace_callback('/\{([^}]+)\}/', static function (array $matches): string {
            $parameter = rtrim($matches[1], '?');

            return match ($parameter) {
                'kind' => 'DEVIATION',
                'slug' => 'authorization-matrix',
                'token' => str_repeat('a', 64),
                default => '00000000-0000-4000-8000-000000000000',
            };
        }, $route->uri());
    }

    /** @return array<string, array<int, string>> */
    private function permissionsByRole(): array
    {
        return DB::table('roles as role')
            ->join('role_permissions as role_permission', 'role_permission.role_id', '=', 'role.id')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('role.status', 'ACTIVE')
            ->where('permission.status', 'ACTIVE')
            ->get(['role.code as role_code', 'permission.code as permission_code'])
            ->groupBy('role_code')
            ->map(fn (Collection $rows): array => $rows->pluck('permission_code')->unique()->sort()->values()->all())
            ->all();
    }

    private function roleContexts(): Collection
    {
        return DB::table('role_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('user.status', 'ACTIVE')
            ->where('role.status', 'ACTIVE')
            ->where('assignment.is_active', true)
            ->whereNotNull('assignment.company_id')
            ->where(fn ($query) => $query
                ->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->orderBy('role.code')
            ->orderBy('assignment.plant_id')
            ->get([
                'assignment.user_id',
                'assignment.company_id',
                'assignment.plant_id',
                'role.code as role_code',
            ])
            ->unique(fn (object $row): string => implode('|', [
                $row->user_id,
                $row->company_id,
                $row->plant_id,
            ]))
            ->values();
    }

    /** @return array{roles: array<int, string>, screens: array<int, string>, actions: array<int, string>, permissions: array<int, string>} */
    private function expectedAccess(string $userId, string $companyId, ?string $plantId): array
    {
        $rows = DB::table('role_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('role_permissions as role_permission', 'role_permission.role_id', '=', 'role.id')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('assignment.user_id', $userId)
            ->where('user.status', 'ACTIVE')
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->where('permission.status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('assignment.company_id')->orWhere('assignment.company_id', $companyId))
            ->where(fn ($query) => $query->whereNull('assignment.plant_id')->orWhere('assignment.plant_id', $plantId))
            ->where(fn ($query) => $query
                ->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->get(['role.code as role_code', 'permission.code as permission_code']);
        $permissions = $rows->pluck('permission_code')->unique()->sort()->values();

        return [
            'roles' => $rows->pluck('role_code')->unique()->sort()->values()->all(),
            'screens' => $permissions
                ->filter(fn (string $permission): bool => str_starts_with($permission, 'SCREEN:')
                    && str_ends_with($permission, ':VIEW'))
                ->map(fn (string $permission): string => substr($permission, 7, -5))
                ->values()->all(),
            'actions' => $permissions
                ->filter(fn (string $permission): bool => str_starts_with($permission, 'ACTION:'))
                ->values()->all(),
            'permissions' => $permissions->all(),
        ];
    }

    private function requestFor(User $user, string $companyId, ?string $plantId): Request
    {
        $session = new Store('authorization-matrix', new ArraySessionHandler(120));
        $session->start();
        $session->put([
            'erp.company_id' => $companyId,
            'erp.plant_id' => $plantId,
        ]);
        $request = Request::create('/api/v1/authorization-matrix', 'GET');
        $request->setLaravelSession($session);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function screenAllows(EnsureScreenAccess $middleware, Request $request, string $screenCode): bool
    {
        try {
            $middleware->handle($request, static fn (): Response => new Response(status: 204), $screenCode);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function permissionAllows(EnsurePermission $middleware, Request $request, string $permissionCode): bool
    {
        try {
            $middleware->handle($request, static fn (): Response => new Response(status: 204), $permissionCode);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** @param array<string, array{allowed: int, denied: int}> $outcomes */
    private function recordOutcome(array &$outcomes, string $key, bool $allowed): void
    {
        $outcomes[$key] ??= ['allowed' => 0, 'denied' => 0];
        $outcomes[$key][$allowed ? 'allowed' : 'denied']++;
    }

    private function assertContextRejected(EnsureContextSelected $middleware, User $user): void
    {
        $request = $this->requestFor($user, self::COMPANY_ID, self::TRAINING_PLANT_ID);
        $response = $middleware->handle($request, static fn (): Response => new Response(status: 204));
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('CONTEXT_REQUIRED', $response->getData(true)['error']['code']);
        $this->assertFalse($request->session()->has('erp.company_id'));
        $this->assertFalse($request->session()->has('erp.plant_id'));
    }
}
