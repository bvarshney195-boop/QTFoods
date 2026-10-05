<?php

namespace App\Modules\Foundation\Application;

use App\Modules\Foundation\Domain\User;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IdentityLifecycleService
{
    public const PASSWORD_RESET = 'PASSWORD_RESET';
    public const EMAIL_VERIFICATION = 'EMAIL_VERIFICATION';

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly IdentityNotificationService $notifications,
        private readonly DeviceSessionService $deviceSessions,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function invitation(string $token): array
    {
        $invitation = DB::table('identity_invitations as invitation')
            ->join('users as user', 'user.id', '=', 'invitation.user_id')
            ->join('companies as company', 'company.id', '=', 'invitation.company_id')
            ->join('plants as plant', 'plant.id', '=', 'invitation.plant_id')
            ->where('invitation.token_hash', $this->tokenHash($token))
            ->select([
                'invitation.*', 'user.email', 'user.name', 'user.status as user_status',
                'company.display_name as company_name', 'plant.name as plant_name',
            ])
            ->first();

        if (! $invitation || ! $this->invitationIsUsable($invitation)) {
            throw new NotFoundHttpException('This invitation link is invalid or has expired.');
        }

        return [
            'id' => (string) $invitation->id,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'company_name' => $invitation->company_name,
            'plant_name' => $invitation->plant_name,
            'expires_at' => $this->timestamp($invitation->expires_at),
        ];
    }

    public function createInvitation(array $data): array
    {
        $result = $this->executeIdempotent(
            'identity.invitation.create.'.$data['company_id'].'.'.$data['plant_id'],
            $data,
            function () use ($data): array {
                $this->assertPlant($data['company_id'], $data['plant_id']);
                $this->assertRole($data['role_id'], $data['company_id']);
                if (DB::table('users')->where('email', $data['email'])->exists()) {
                    throw ValidationException::withMessages([
                        'email' => ['A user with this email address already exists.'],
                    ]);
                }

                $now = now();
                $userId = (string) Str::uuid();
                $assignmentId = (string) Str::uuid();
                $invitationId = (string) Str::uuid();
                $rawToken = $this->newToken();
                $expiresAt = $now->copy()->addHours(
                    max(1, (int) config('qtfoods.identity.invitation_hours', 72))
                );

                DB::table('users')->insert([
                    'id' => $userId,
                    'email' => $data['email'],
                    'name' => $data['name'],
                    'password_hash' => Hash::make(Str::random(64)),
                    'status' => 'INVITED',
                    'email_verified_at' => null,
                    'password_changed_at' => null,
                    'last_login_at' => null,
                    'last_login_ip' => null,
                    'mfa_secret' => null,
                    'mfa_enabled_at' => null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('role_assignments')->insert([
                    'id' => $assignmentId,
                    'user_id' => $userId,
                    'role_id' => $data['role_id'],
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'party_id' => null,
                    'is_active' => true,
                    'effective_from' => $data['effective_from'] ?? null,
                    'effective_to' => $data['effective_to'] ?? null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('identity_invitations')->insert([
                    'id' => $invitationId,
                    'user_id' => $userId,
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'invited_by_user_id' => $data['actor_id'],
                    'token_hash' => $this->tokenHash($rawToken),
                    'expires_at' => $expiresAt,
                    'accepted_at' => null,
                    'revoked_at' => null,
                    'last_sent_at' => null,
                    'last_delivery_status' => null,
                    'delivery_count' => 0,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->audit->record('INVITE_USER', 'identity_invitation', $invitationId,
                    $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
                        'entity_version' => 1,
                        'correlation_id' => $data['correlation_id'] ?? null,
                        'safe_diff' => ['created' => [
                            'user_id' => $userId,
                            'email' => $data['email'],
                            'role_id' => $data['role_id'],
                        ]],
                    ]);
                $this->outbox->append('identity.invitation.created', 'identity_invitation', $invitationId,
                    $invitationId.':1', [
                        'invitation_id' => $invitationId,
                        'user_id' => $userId,
                        'company_id' => $data['company_id'],
                        'plant_id' => $data['plant_id'],
                        'expires_at' => $expiresAt->toISOString(),
                    ], $data['correlation_id'] ?? null);

                return [
                    'entity_type' => 'identity_invitation',
                    'id' => $invitationId,
                    'user_id' => $userId,
                    'role_assignment_id' => $assignmentId,
                    'status' => 'PENDING',
                    'record_version' => 1,
                    'expires_at' => $expiresAt->toISOString(),
                    '_raw_token' => $rawToken,
                    '_email' => $data['email'],
                    '_name' => $data['name'],
                ];
            }
        );

        return $this->deliverInvitation($result);
    }

    public function resendInvitation(string $invitationId, array $data): array
    {
        $result = $this->executeIdempotent(
            'identity.invitation.resend.'.$invitationId,
            $data,
            function () use ($invitationId, $data): array {
                $invitation = $this->scopedInvitation($invitationId, $data, true);
                $this->assertVersion($invitation, $data['expected_version']);
                if ($invitation->accepted_at !== null || $invitation->revoked_at !== null
                    || $invitation->user_status !== 'INVITED') {
                    throw new ConflictHttpException('Only a pending invitation can be resent.');
                }

                $rawToken = $this->newToken();
                $expiresAt = now()->addHours(
                    max(1, (int) config('qtfoods.identity.invitation_hours', 72))
                );
                $version = (int) $invitation->record_version + 1;
                DB::table('identity_invitations')->where('id', $invitationId)->update([
                    'token_hash' => $this->tokenHash($rawToken),
                    'expires_at' => $expiresAt,
                    'last_delivery_status' => null,
                    'record_version' => $version,
                    'updated_at' => now(),
                ]);

                $this->audit->record('RESEND_USER_INVITATION', 'identity_invitation', $invitationId,
                    $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
                        'entity_version' => $version,
                        'correlation_id' => $data['correlation_id'] ?? null,
                        'safe_diff' => ['expires_at' => [
                            'from' => $this->timestamp($invitation->expires_at),
                            'to' => $expiresAt->toISOString(),
                        ]],
                    ]);
                $this->outbox->append('identity.invitation.resent', 'identity_invitation', $invitationId,
                    $invitationId.':'.$version, [
                        'invitation_id' => $invitationId,
                        'user_id' => (string) $invitation->user_id,
                        'expires_at' => $expiresAt->toISOString(),
                    ], $data['correlation_id'] ?? null);

                return [
                    'entity_type' => 'identity_invitation',
                    'id' => $invitationId,
                    'user_id' => (string) $invitation->user_id,
                    'status' => 'PENDING',
                    'record_version' => $version,
                    'expires_at' => $expiresAt->toISOString(),
                    '_raw_token' => $rawToken,
                    '_email' => $invitation->email,
                    '_name' => $invitation->name,
                ];
            }
        );

        return $this->deliverInvitation($result);
    }

    public function revokeInvitation(string $invitationId, array $data): array
    {
        return $this->executeIdempotent(
            'identity.invitation.revoke.'.$invitationId,
            $data,
            function () use ($invitationId, $data): array {
                $invitation = $this->scopedInvitation($invitationId, $data, true);
                $this->assertVersion($invitation, $data['expected_version']);
                if ($invitation->accepted_at !== null) {
                    throw new ConflictHttpException('An accepted invitation cannot be revoked.');
                }
                if ($invitation->revoked_at !== null) {
                    return [
                        'entity_type' => 'identity_invitation',
                        'id' => $invitationId,
                        'user_id' => (string) $invitation->user_id,
                        'status' => 'REVOKED',
                        'record_version' => (int) $invitation->record_version,
                    ];
                }

                $version = (int) $invitation->record_version + 1;
                DB::table('identity_invitations')->where('id', $invitationId)->update([
                    'revoked_at' => now(),
                    'record_version' => $version,
                    'updated_at' => now(),
                ]);
                DB::table('users')->where('id', $invitation->user_id)->where('status', 'INVITED')->update([
                    'status' => 'INACTIVE',
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => now(),
                ]);
                DB::table('role_assignments')->where('user_id', $invitation->user_id)->update([
                    'is_active' => false,
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => now(),
                ]);

                $this->audit->record('REVOKE_USER_INVITATION', 'identity_invitation', $invitationId,
                    $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
                        'entity_version' => $version,
                        'correlation_id' => $data['correlation_id'] ?? null,
                        'safe_diff' => ['status' => ['from' => 'PENDING', 'to' => 'REVOKED']],
                    ]);
                $this->outbox->append('identity.invitation.revoked', 'identity_invitation', $invitationId,
                    $invitationId.':'.$version, [
                        'invitation_id' => $invitationId,
                        'user_id' => (string) $invitation->user_id,
                    ], $data['correlation_id'] ?? null);

                return [
                    'entity_type' => 'identity_invitation',
                    'id' => $invitationId,
                    'user_id' => (string) $invitation->user_id,
                    'status' => 'REVOKED',
                    'record_version' => $version,
                ];
            }
        );
    }

    public function acceptInvitation(string $token, string $password): array
    {
        return DB::transaction(function () use ($token, $password): array {
            $invitation = DB::table('identity_invitations')
                ->where('token_hash', $this->tokenHash($token))
                ->lockForUpdate()
                ->first();
            if (! $invitation || ! $this->invitationIsUsable($invitation)) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation link is invalid or has expired.'],
                ]);
            }

            $user = DB::table('users')->where('id', $invitation->user_id)->lockForUpdate()->first();
            if (! $user || $user->status !== 'INVITED') {
                throw new ConflictHttpException('This invitation has already been completed or revoked.');
            }

            $now = now();
            $userVersion = (int) $user->record_version + 1;
            $invitationVersion = (int) $invitation->record_version + 1;
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make($password),
                'status' => 'ACTIVE',
                'email_verified_at' => $now,
                'password_changed_at' => $now,
                'record_version' => $userVersion,
                'updated_at' => $now,
            ]);
            DB::table('identity_invitations')->where('id', $invitation->id)->update([
                'accepted_at' => $now,
                'record_version' => $invitationVersion,
                'updated_at' => $now,
            ]);

            $this->audit->record('ACCEPT_USER_INVITATION', 'identity_invitation', (string) $invitation->id,
                (string) $user->id, (string) $invitation->company_id,
                $invitation->plant_id ? (string) $invitation->plant_id : null, 'SUCCESS', [
                    'entity_version' => $invitationVersion,
                    'safe_diff' => ['status' => ['from' => 'PENDING', 'to' => 'ACCEPTED']],
                ]);
            $this->outbox->append('identity.invitation.accepted', 'identity_invitation',
                (string) $invitation->id, $invitation->id.':'.$invitationVersion, [
                    'invitation_id' => (string) $invitation->id,
                    'user_id' => (string) $user->id,
                    'user_record_version' => $userVersion,
                ]);

            return [
                'accepted' => true,
                'email' => $user->email,
            ];
        });
    }

    public function requestPasswordReset(string $email, ?string $ip, bool $includeDeliveryStatus = false): array
    {
        $response = $this->genericPasswordResetResponse();
        $user = User::query()->where('email', $email)->where('status', 'ACTIVE')->first();
        if (! $user) {
            return $response;
        }
        $scope = $this->scopeForUser((string) $user->id);
        if (! $scope) {
            return $response;
        }

        $issued = DB::transaction(function () use ($user, $scope, $ip): array {
            $token = $this->issueToken($user, self::PASSWORD_RESET, $scope, $ip);
            $this->recordTokenIssued($token, $user, $scope, (string) $user->id);

            return $token;
        });
        $delivery = $this->notifications->passwordReset(
            $user->email,
            $user->name,
            $issued['_raw_token'],
            $issued['expires_at']
        );

        if ($includeDeliveryStatus) {
            return $response + ['delivery' => Arr::only($delivery, ['channel', 'status'])];
        }

        return $this->withDevelopmentDelivery($response, $delivery);
    }

    public function resetPassword(string $email, string $token, string $password): array
    {
        return DB::transaction(function () use ($email, $token, $password): array {
            $identityToken = $this->usableIdentityToken($email, $token, self::PASSWORD_RESET);
            $user = DB::table('users')->where('id', $identityToken->user_id)->lockForUpdate()->first();
            if (! $user || $user->status !== 'ACTIVE') {
                $this->invalidToken('token', 'This password reset link is invalid or has expired.');
            }

            $now = now();
            $version = (int) $user->record_version + 1;
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make($password),
                'password_changed_at' => $now,
                'email_verified_at' => $user->email_verified_at ?? $now,
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            DB::table('identity_tokens')->where('id', $identityToken->id)->update([
                'used_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('identity_tokens')
                ->where('user_id', $user->id)
                ->where('type', self::PASSWORD_RESET)
                ->where('id', '<>', $identityToken->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);
            $this->deviceSessions->revokeAllForUser(
                (string) $user->id,
                (string) $user->id,
                'PASSWORD_RESET'
            );
            $this->recordIdentityMutation(
                'RESET_PASSWORD',
                'identity.password.reset',
                $user,
                $identityToken,
                $version,
                ['password_changed_at' => ['from' => $user->password_changed_at, 'to' => $now->toISOString()]]
            );

            return ['reset' => true];
        });
    }

    public function requestEmailVerification(string $email, ?string $ip): array
    {
        $response = $this->genericVerificationResponse();
        $user = User::query()
            ->where('email', $email)
            ->where('status', 'ACTIVE')
            ->whereNull('email_verified_at')
            ->first();
        $scope = $user ? $this->scopeForUser((string) $user->id) : null;
        if (! $user || ! $scope) {
            return $response;
        }

        $issued = DB::transaction(function () use ($user, $scope, $ip): array {
            $token = $this->issueToken($user, self::EMAIL_VERIFICATION, $scope, $ip);
            $this->recordTokenIssued($token, $user, $scope, (string) $user->id);

            return $token;
        });
        $delivery = $this->notifications->emailVerification(
            $user->email,
            $user->name,
            $issued['_raw_token'],
            $issued['expires_at']
        );

        return $this->withDevelopmentDelivery($response, $delivery);
    }

    public function requestEmailVerificationAsAdmin(string $userId, array $data, ?string $ip): array
    {
        $result = $this->executeIdempotent(
            'identity.verification.admin.'.$userId,
            $data,
            function () use ($userId, $data, $ip): array {
                $user = User::query()->where('id', $userId)->first();
                if (! $user || ! DB::table('role_assignments')
                    ->where('user_id', $userId)
                    ->where('company_id', $data['company_id'])
                    ->where('plant_id', $data['plant_id'])
                    ->exists()) {
                    throw new NotFoundHttpException('User not found.');
                }
                if ($user->status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'user' => ['Only an active account can receive a verification link.'],
                    ]);
                }
                if ($user->email_verified_at !== null) {
                    throw ValidationException::withMessages([
                        'user' => ['This email address is already verified.'],
                    ]);
                }

                $scope = ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']];
                $token = $this->issueToken($user, self::EMAIL_VERIFICATION, $scope, $ip);
                $this->recordTokenIssued($token, $user, $scope, $data['actor_id'], $data['correlation_id'] ?? null);

                return [
                    'entity_type' => 'identity_token',
                    'id' => $token['id'],
                    'user_id' => (string) $user->id,
                    'status' => 'PENDING',
                    'record_version' => 1,
                    'expires_at' => $token['expires_at'],
                    '_raw_token' => $token['_raw_token'],
                    '_email' => $user->email,
                    '_name' => $user->name,
                ];
            }
        );

        if (! isset($result['_raw_token'])) {
            return $result;
        }
        $delivery = $this->notifications->emailVerification(
            $result['_email'], $result['_name'], $result['_raw_token'], $result['expires_at']
        );

        return Arr::except($result, ['_raw_token', '_email', '_name']) + ['delivery' => $delivery];
    }

    public function verifyEmail(string $email, string $token): array
    {
        return DB::transaction(function () use ($email, $token): array {
            $identityToken = $this->usableIdentityToken($email, $token, self::EMAIL_VERIFICATION);
            $user = DB::table('users')->where('id', $identityToken->user_id)->lockForUpdate()->first();
            if (! $user || $user->status !== 'ACTIVE') {
                $this->invalidToken('token', 'This verification link is invalid or has expired.');
            }

            $now = now();
            $version = (int) $user->record_version;
            if ($user->email_verified_at === null) {
                $version++;
                DB::table('users')->where('id', $user->id)->update([
                    'email_verified_at' => $now,
                    'record_version' => $version,
                    'updated_at' => $now,
                ]);
            }
            DB::table('identity_tokens')->where('id', $identityToken->id)->update([
                'used_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('identity_tokens')
                ->where('user_id', $user->id)
                ->where('type', self::EMAIL_VERIFICATION)
                ->where('id', '<>', $identityToken->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);
            $this->recordIdentityMutation(
                'VERIFY_EMAIL',
                'identity.email.verified',
                $user,
                $identityToken,
                $version,
                ['email_verified_at' => ['from' => $user->email_verified_at, 'to' => $now->toISOString()]]
            );

            return ['verified' => true, 'email' => $user->email];
        });
    }

    public function changePassword(
        User $user,
        Request $request,
        string $currentPassword,
        string $password,
    ): array {
        if (! Hash::check($currentPassword, $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
        if (Hash::check($password, $user->password_hash)) {
            throw ValidationException::withMessages([
                'password' => ['Choose a password that is different from the current password.'],
            ]);
        }

        $currentSessionId = $this->deviceSessions->currentId($request);
        return DB::transaction(function () use ($user, $password, $currentSessionId): array {
            $locked = DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $version = (int) $locked->record_version + 1;
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make($password),
                'password_changed_at' => $now,
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            $revoked = $this->deviceSessions->revokeAllForUser(
                (string) $user->id,
                (string) $user->id,
                'PASSWORD_CHANGED',
                $currentSessionId
            );
            $scope = $this->scopeForUser((string) $user->id);
            if ($scope) {
                $this->audit->record('CHANGE_PASSWORD', 'user', (string) $user->id, (string) $user->id,
                    $scope['company_id'], $scope['plant_id'], 'SUCCESS', [
                        'entity_version' => $version,
                        'safe_diff' => [
                            'password_changed_at' => ['from' => $locked->password_changed_at, 'to' => $now->toISOString()],
                            'other_sessions_revoked' => ['from' => 0, 'to' => $revoked],
                        ],
                    ]);
                $this->outbox->append('identity.password.changed', 'user', (string) $user->id,
                    $user->id.':'.$version.':password', [
                        'user_id' => (string) $user->id,
                        'record_version' => $version,
                        'other_sessions_revoked' => $revoked,
                    ]);
            }

            return ['changed' => true, 'other_sessions_revoked' => $revoked];
        });
    }

    private function deliverInvitation(array $result): array
    {
        if (! isset($result['_raw_token'])) {
            return $result;
        }

        $delivery = $this->notifications->invitation(
            $result['_email'], $result['_name'], $result['_raw_token'], $result['expires_at']
        );
        DB::table('identity_invitations')->where('id', $result['id'])->update([
            'last_sent_at' => now(),
            'last_delivery_status' => $delivery['status'],
            'delivery_count' => DB::raw('delivery_count + 1'),
            'updated_at' => now(),
        ]);

        return Arr::except($result, ['_raw_token', '_email', '_name']) + ['delivery' => $delivery];
    }

    private function issueToken(User $user, string $type, array $scope, ?string $ip): array
    {
        $now = now();
        DB::table('identity_tokens')
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);

        $id = (string) Str::uuid();
        $rawToken = $this->newToken();
        $minutes = $type === self::PASSWORD_RESET
            ? max(5, (int) config('qtfoods.identity.password_reset_minutes', 60))
            : max(5, (int) config('qtfoods.identity.verification_minutes', 60));
        $expiresAt = $now->copy()->addMinutes($minutes);
        DB::table('identity_tokens')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'company_id' => $scope['company_id'],
            'plant_id' => $scope['plant_id'],
            'type' => $type,
            'token_hash' => $this->tokenHash($rawToken),
            'requested_ip' => $ip ? mb_substr($ip, 0, 45) : null,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => $id,
            'type' => $type,
            'expires_at' => $expiresAt->toISOString(),
            '_raw_token' => $rawToken,
        ];
    }

    private function recordTokenIssued(
        array $token,
        User $user,
        array $scope,
        string $actorId,
        ?string $correlationId = null,
    ): void {
        $command = $token['type'] === self::PASSWORD_RESET
            ? 'REQUEST_PASSWORD_RESET'
            : 'REQUEST_EMAIL_VERIFICATION';
        $event = $token['type'] === self::PASSWORD_RESET
            ? 'identity.password-reset.requested'
            : 'identity.email-verification.requested';
        $this->audit->record($command, 'identity_token', $token['id'], $actorId,
            $scope['company_id'], $scope['plant_id'], 'SUCCESS', [
                'correlation_id' => $correlationId,
                'safe_diff' => ['created' => [
                    'type' => $token['type'],
                    'expires_at' => $token['expires_at'],
                ]],
            ]);
        $this->outbox->append($event, 'identity_token', $token['id'], $token['id'], [
            'token_id' => $token['id'],
            'user_id' => (string) $user->id,
            'type' => $token['type'],
            'expires_at' => $token['expires_at'],
        ], $correlationId);
    }

    private function recordIdentityMutation(
        string $command,
        string $event,
        object $user,
        object $identityToken,
        int $version,
        array $safeDiff,
    ): void {
        $companyId = $identityToken->company_id;
        if (! is_string($companyId)) {
            return;
        }
        $plantId = is_string($identityToken->plant_id) ? $identityToken->plant_id : null;
        $this->audit->record($command, 'user', (string) $user->id, (string) $user->id,
            $companyId, $plantId, 'SUCCESS', [
                'entity_version' => $version,
                'safe_diff' => $safeDiff,
            ]);
        $this->outbox->append($event, 'user', (string) $user->id,
            $identityToken->id.':consumed', [
                'user_id' => (string) $user->id,
                'token_id' => (string) $identityToken->id,
                'record_version' => $version,
            ]);
    }

    private function usableIdentityToken(string $email, string $token, string $type): object
    {
        $identityToken = DB::table('identity_tokens')
            ->where('token_hash', $this->tokenHash($token))
            ->where('type', $type)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();
        if (! $identityToken
            || now()->greaterThanOrEqualTo($identityToken->expires_at)
            || ! DB::table('users')->where('id', $identityToken->user_id)->where('email', $email)->exists()) {
            $message = $type === self::PASSWORD_RESET
                ? 'This password reset link is invalid or has expired.'
                : 'This verification link is invalid or has expired.';
            $this->invalidToken('token', $message);
        }

        return $identityToken;
    }

    private function scopedInvitation(string $id, array $data, bool $lock): object
    {
        $query = DB::table('identity_invitations as invitation')
            ->join('users as user', 'user.id', '=', 'invitation.user_id')
            ->where('invitation.id', $id)
            ->where('invitation.company_id', $data['company_id'])
            ->where('invitation.plant_id', $data['plant_id'])
            ->select([
                'invitation.*', 'user.email', 'user.name', 'user.status as user_status',
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $invitation = $query->first();
        if (! $invitation) {
            throw new NotFoundHttpException('Invitation not found.');
        }

        return $invitation;
    }

    private function assertPlant(string $companyId, string $plantId): void
    {
        if (! DB::table('plants')->where('id', $plantId)->where('company_id', $companyId)
            ->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages([
                'context' => ['Select an active plant before inviting a user.'],
            ]);
        }
    }

    private function assertRole(string $roleId, string $companyId): void
    {
        if (! DB::table('roles')->where('id', $roleId)->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
            ->exists()) {
            throw ValidationException::withMessages(['role_id' => ['Select an active role in this organisation.']]);
        }
    }

    private function invitationIsUsable(object $invitation): bool
    {
        return $invitation->accepted_at === null
            && $invitation->revoked_at === null
            && ($invitation->user_status ?? 'INVITED') === 'INVITED'
            && now()->lessThan($invitation->expires_at);
    }

    private function executeIdempotent(string $namespace, array $data, Closure $command): array
    {
        return DB::transaction(function () use ($namespace, $data, $command): array {
            $payload = Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']);
            $existing = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($existing !== null) {
                return $existing;
            }

            $result = $command();
            $stored = Arr::except($result, ['_raw_token', '_email', '_name']);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $stored);

            return $result;
        });
    }

    private function assertVersion(object $record, int $expected): void
    {
        if ((int) $record->record_version !== $expected) {
            throw new ConflictHttpException('The invitation changed after it was opened. Refresh and try again.');
        }
    }

    private function scopeForUser(string $userId): ?array
    {
        $row = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereNotNull('company_id')
            ->orderByDesc('is_active')
            ->first(['company_id', 'plant_id']);

        return $row ? [
            'company_id' => (string) $row->company_id,
            'plant_id' => $row->plant_id ? (string) $row->plant_id : null,
        ] : null;
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toISOString();
    }

    private function invalidToken(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function genericPasswordResetResponse(): array
    {
        return [
            'accepted' => true,
            'message' => 'If an eligible account matches that email, a password reset link has been sent.',
        ];
    }

    private function genericVerificationResponse(): array
    {
        return [
            'accepted' => true,
            'message' => 'If that account still requires verification, a new link has been sent.',
        ];
    }

    private function withDevelopmentDelivery(array $response, array $delivery): array
    {
        if (config('qtfoods.identity.preview_links', false)) {
            $response['delivery'] = $delivery;
        }

        return $response;
    }
}
