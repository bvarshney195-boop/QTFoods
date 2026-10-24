<?php

namespace App\Modules\Foundation\Application;

use App\Modules\Foundation\Domain\User;
use App\Shared\Audit\AuditService;
use App\Shared\Outbox\OutboxService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeviceSessionService
{
    private const SESSION_KEY = 'identity.device_session_id';

    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function start(User $user, Request $request): string
    {
        $existingId = $this->currentId($request);
        if ($existingId) {
            DB::table('user_sessions')
                ->where('id', $existingId)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $user->id,
                    'revoke_reason' => 'SESSION_REPLACED',
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => now(),
                ]);
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::table('user_sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => $this->ip($request),
            'user_agent' => $this->userAgent($request),
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addMinutes($this->lifetimeMinutes()),
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revoke_reason' => null,
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $request->session()->put(self::SESSION_KEY, $id);

        return $id;
    }

    public function ensure(User $user, Request $request): void
    {
        $id = $this->currentId($request);
        if (! $id) {
            $this->start($user, $request);

            return;
        }

        $device = DB::table('user_sessions')->where('id', $id)->first();
        if (! $device || (string) $device->user_id !== (string) $user->id) {
            $request->session()->forget(self::SESSION_KEY);
            $this->start($user, $request);

            return;
        }

        if ($device->revoked_at !== null || now()->greaterThanOrEqualTo($device->expires_at)) {
            if ($device->revoked_at === null) {
                DB::table('user_sessions')->where('id', $id)->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $user->id,
                    'revoke_reason' => 'EXPIRED',
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => now(),
                ]);
            }
            $this->invalidateAuthentication($request);
            throw new AuthenticationException('This device session is no longer active.');
        }

        if (now()->subMinutes(5)->greaterThan($device->last_seen_at)) {
            DB::table('user_sessions')->where('id', $id)->update([
                'ip_address' => $this->ip($request),
                'user_agent' => $this->userAgent($request),
                'last_seen_at' => now(),
                'expires_at' => now()->addMinutes($this->lifetimeMinutes()),
                'updated_at' => now(),
            ]);
        }
    }

    public function currentId(Request $request): ?string
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    public function revokeCurrent(User $user, Request $request, string $reason = 'LOGOUT'): void
    {
        $id = $this->currentId($request);
        if (! $id) {
            return;
        }

        DB::table('user_sessions')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $user->id,
                'revoke_reason' => $reason,
                'record_version' => DB::raw('record_version + 1'),
                'updated_at' => now(),
            ]);
    }

    public function listForUser(string $userId, Request $request): array
    {
        $currentId = $this->currentId($request);
        $items = DB::table('user_sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->map(fn (object $session) => $this->payload($session, $currentId))
            ->values()
            ->all();

        return [
            'data' => $items,
            'summary' => [
                'total' => count($items),
                'active' => collect($items)->where('status', 'ACTIVE')->count(),
            ],
        ];
    }

    public function revokeOwn(User $actor, string $sessionId, Request $request): array
    {
        $device = DB::table('user_sessions')
            ->where('id', $sessionId)
            ->where('user_id', $actor->id)
            ->first();
        if (! $device) {
            throw new NotFoundHttpException('Device session not found.');
        }

        $changed = $this->revoke($device, (string) $actor->id, 'USER_REVOKED');
        $this->recordRevocation($device, (string) $actor->id, 'USER_REVOKED', $changed);

        return [
            'id' => $sessionId,
            'revoked' => true,
            'current' => $this->currentId($request) === $sessionId,
        ];
    }

    public function revokeOthers(User $actor, Request $request): array
    {
        $currentId = $this->currentId($request);
        $query = DB::table('user_sessions')
            ->where('user_id', $actor->id)
            ->whereNull('revoked_at');
        if ($currentId) {
            $query->where('id', '<>', $currentId);
        }
        $count = $query->update([
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
            'revoke_reason' => 'USER_REVOKED_OTHERS',
            'record_version' => DB::raw('record_version + 1'),
            'updated_at' => now(),
        ]);

        if ($count > 0 && $currentId) {
            $this->recordBulkRevocation((string) $actor->id, $currentId, 'USER_REVOKED_OTHERS', $count);
        }

        return ['revoked_count' => $count];
    }

    public function listForAdmin(string $userId, array $scope, Request $request): array
    {
        $this->assertScopedUser($userId, $scope);

        return $this->listForUser($userId, $request);
    }

    public function revokeAsAdmin(
        User $actor,
        string $userId,
        string $sessionId,
        array $scope,
        Request $request,
    ): array {
        $this->assertScopedUser($userId, $scope);
        $device = DB::table('user_sessions')
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->first();
        if (! $device) {
            throw new NotFoundHttpException('Device session not found.');
        }

        $changed = $this->revoke($device, (string) $actor->id, 'ADMIN_REVOKED');
        if ($changed) {
            $this->audit->record('REVOKE_USER_SESSION', 'user_session', $sessionId, (string) $actor->id,
                $scope['company_id'], $scope['plant_id'], 'SUCCESS', [
                    'entity_version' => (int) $device->record_version + 1,
                    'safe_diff' => ['revoked_at' => ['from' => null, 'to' => now()->toISOString()]],
                ]);
            $this->outbox->append('identity.session.revoked', 'user_session', $sessionId,
                $sessionId.':'.((int) $device->record_version + 1), [
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'reason' => 'ADMIN_REVOKED',
                ]);
        }

        return [
            'id' => $sessionId,
            'revoked' => true,
            'current' => $this->currentId($request) === $sessionId,
        ];
    }

    public function revokeAllForUser(string $userId, string $actorId, string $reason, ?string $exceptId = null): int
    {
        $query = DB::table('user_sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at');
        if ($exceptId) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->update([
            'revoked_at' => now(),
            'revoked_by_user_id' => $actorId,
            'revoke_reason' => $reason,
            'record_version' => DB::raw('record_version + 1'),
            'updated_at' => now(),
        ]);
    }

    private function revoke(object $device, string $actorId, string $reason): bool
    {
        if ($device->revoked_at !== null) {
            return false;
        }

        return DB::table('user_sessions')
            ->where('id', $device->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actorId,
                'revoke_reason' => $reason,
                'record_version' => DB::raw('record_version + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    private function recordRevocation(object $device, string $actorId, string $reason, bool $changed): void
    {
        $scope = $this->scopeForUser((string) $device->user_id);
        if (! $changed || ! $scope) {
            return;
        }

        $version = (int) $device->record_version + 1;
        $this->audit->record('REVOKE_OWN_SESSION', 'user_session', (string) $device->id, $actorId,
            $scope['company_id'], $scope['plant_id'], 'SUCCESS', ['entity_version' => $version]);
        $this->outbox->append('identity.session.revoked', 'user_session', (string) $device->id,
            $device->id.':'.$version, [
                'session_id' => (string) $device->id,
                'user_id' => (string) $device->user_id,
                'reason' => $reason,
            ]);
    }

    private function recordBulkRevocation(string $actorId, string $currentId, string $reason, int $count): void
    {
        if (! $scope = $this->scopeForUser($actorId)) {
            return;
        }

        $this->audit->record('REVOKE_OTHER_SESSIONS', 'user_session', $currentId, $actorId,
            $scope['company_id'], $scope['plant_id'], 'SUCCESS', [
                'safe_diff' => ['revoked_count' => ['from' => 0, 'to' => $count]],
            ]);
        $this->outbox->append('identity.sessions.revoked', 'user_session', $currentId,
            $currentId.':bulk:'.Str::uuid(), [
                'user_id' => $actorId,
                'reason' => $reason,
                'revoked_count' => $count,
            ]);
    }

    private function assertScopedUser(string $userId, array $scope): void
    {
        $visible = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])
            ->exists();
        if (! $visible) {
            throw new NotFoundHttpException('User not found.');
        }
    }

    private function scopeForUser(string $userId): ?array
    {
        $assignment = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereNotNull('company_id')
            ->orderByDesc('is_active')
            ->first(['company_id', 'plant_id']);

        return $assignment ? [
            'company_id' => (string) $assignment->company_id,
            'plant_id' => $assignment->plant_id ? (string) $assignment->plant_id : null,
        ] : null;
    }

    private function payload(object $session, ?string $currentId): array
    {
        $expired = now()->greaterThanOrEqualTo($session->expires_at);
        $status = $session->revoked_at !== null ? 'REVOKED' : ($expired ? 'EXPIRED' : 'ACTIVE');

        return [
            'id' => (string) $session->id,
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'last_seen_at' => $this->timestamp($session->last_seen_at),
            'expires_at' => $this->timestamp($session->expires_at),
            'revoked_at' => $this->timestamp($session->revoked_at),
            'revoke_reason' => $session->revoke_reason,
            'status' => $status,
            'current' => $currentId === (string) $session->id,
            'record_version' => (int) $session->record_version,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : \Carbon\CarbonImmutable::parse($value)->toISOString();
    }

    private function lifetimeMinutes(): int
    {
        return max(5, (int) config('qtfoods.identity.session_lifetime_minutes', 120));
    }

    private function ip(Request $request): ?string
    {
        $ip = $request->ip();

        return is_string($ip) ? mb_substr($ip, 0, 45) : null;
    }

    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return is_string($agent) ? mb_substr($agent, 0, 1024) : null;
    }

    private function invalidateAuthentication(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
