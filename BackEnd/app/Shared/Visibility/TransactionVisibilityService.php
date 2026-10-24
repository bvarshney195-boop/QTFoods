<?php

namespace App\Shared\Visibility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class TransactionVisibilityService
{
    public const STATUSES = ['NORMAL', 'RESTRICTED'];

    public function __construct(
        private readonly TransactionVisibilityPolicy $policy,
        private readonly VisibilityAuditService $audit,
    ) {}

    public function change(
        string $transactionType,
        string $transactionId,
        string $targetStatus,
        ?string $reason,
        array $context,
    ): array {
        return DB::transaction(function () use (
            $transactionType,
            $transactionId,
            $targetStatus,
            $reason,
            $context,
        ): array {
            $definition = $this->policy->definition($transactionType);
            $type = $definition['transaction_type'];
            $record = $this->policy->scopedRecord($type, $transactionId, $context, true);
            $visibility = DB::table('transaction_visibilities')
                ->where('transaction_type', $type)
                ->where('transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();
            $currentStatus = (string) ($visibility?->visibility_status ?? 'NORMAL');
            $currentVersion = $visibility ? (int) $visibility->record_version : 0;

            $this->policy->assertCanChange($context['permissions'], $type, $currentStatus);
            if ((int) $context['expected_version'] !== $currentVersion) {
                throw new ConflictHttpException('Transaction visibility changed after this screen was loaded. Refresh and try again.');
            }

            if ($currentStatus === $targetStatus) {
                return $this->payload($visibility, $type, $transactionId, $definition['module']);
            }

            $now = now();
            $nextVersion = $currentVersion + 1;
            $values = [
                'module' => $definition['module'],
                'company_id' => (string) $record->company_id,
                'plant_id' => isset($record->plant_id) ? (string) $record->plant_id : ($context['plant_id'] ?? null),
                'visibility_status' => $targetStatus,
                'restricted_by' => $targetStatus === 'RESTRICTED' ? $context['actor_id'] : null,
                'restricted_at' => $targetStatus === 'RESTRICTED' ? $now : null,
                'restriction_reason' => $targetStatus === 'RESTRICTED' ? $this->nullable($reason) : null,
                'record_version' => $nextVersion,
                'updated_at' => $now,
            ];

            if ($visibility) {
                DB::table('transaction_visibilities')->where('id', $visibility->id)->update($values);
                $visibilityId = (string) $visibility->id;
            } else {
                $visibilityId = (string) Str::uuid();
                DB::table('transaction_visibilities')->insert($values + [
                    'id' => $visibilityId,
                    'transaction_type' => $type,
                    'transaction_id' => $transactionId,
                    'created_at' => $now,
                ]);
            }

            $this->audit->record([
                'visibility_id' => $visibilityId,
                'transaction_type' => $type,
                'transaction_id' => $transactionId,
                'module' => $definition['module'],
                'previous_visibility' => $currentStatus,
                'new_visibility' => $targetStatus,
                'actor_id' => $context['actor_id'],
                'company_id' => (string) $record->company_id,
                'plant_id' => isset($record->plant_id) ? (string) $record->plant_id : ($context['plant_id'] ?? null),
                'change_source' => $context['change_source'],
                'reason' => $this->nullable($reason),
                'record_version' => $nextVersion,
                'correlation_id' => $context['correlation_id'] ?? null,
            ]);

            $updated = DB::table('transaction_visibilities')->where('id', $visibilityId)->first();

            return $this->payload($updated, $type, $transactionId, $definition['module']);
        }, 3);
    }

    public function history(string $transactionType, string $transactionId, array $context): array
    {
        $definition = $this->policy->definition($transactionType);
        $type = $definition['transaction_type'];
        $this->policy->scopedRecord($type, $transactionId, $context);
        $visibility = DB::table('transaction_visibilities')
            ->where('transaction_type', $type)->where('transaction_id', $transactionId)->first();
        $status = (string) ($visibility?->visibility_status ?? 'NORMAL');
        $this->policy->assertCanView($context['permissions'], $type, $status);
        // Historical restriction decisions can disclose formerly hidden business details,
        // including actor names and confidential restriction reasons. A record becoming
        // NORMAL must not make those past audit events visible to ordinary users.
        if (! $this->policy->canShowRestricted($context['permissions'])) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Transaction not found.');
        }

        return DB::table('transaction_visibility_events as event')
            ->join('users as actor', 'actor.id', '=', 'event.actor_id')
            ->where('event.transaction_type', $type)
            ->where('event.transaction_id', $transactionId)
            ->where('event.company_id', $context['company_id'])
            ->when($context['plant_id'] ?? null, fn ($query, string $plantId) => $query->where('event.plant_id', $plantId))
            ->orderBy('event.changed_at')->orderBy('event.id')
            ->get([
                'event.id', 'event.previous_visibility', 'event.new_visibility', 'event.change_source',
                'event.reason', 'event.changed_at', 'event.actor_id', 'actor.name as actor_name',
            ])->map(fn (object $event): array => [
                'id' => (string) $event->id,
                'previous_visibility' => $event->previous_visibility,
                'new_visibility' => $event->new_visibility,
                'change_source' => $event->change_source,
                'reason' => $event->reason,
                'changed_at' => $event->changed_at,
                'actor' => ['id' => (string) $event->actor_id, 'name' => $event->actor_name],
            ])->all();
    }

    private function payload(?object $visibility, string $type, string $id, string $module): array
    {
        return [
            'transaction_type' => $type,
            'transaction_id' => $id,
            'module' => $module,
            'status' => (string) ($visibility?->visibility_status ?? 'NORMAL'),
            'is_restricted' => ($visibility?->visibility_status ?? 'NORMAL') === 'RESTRICTED',
            'record_version' => $visibility ? (int) $visibility->record_version : 0,
            'restricted_by' => $visibility?->restricted_by ? (string) $visibility->restricted_by : null,
            'restricted_at' => $visibility?->restricted_at,
            'restriction_reason' => $visibility?->restriction_reason,
        ];
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
