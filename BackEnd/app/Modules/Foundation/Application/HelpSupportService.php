<?php

namespace App\Modules\Foundation\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class HelpSupportService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'help.support.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->assertScreenAccess($data['affected_screen_code'] ?? null, $data);

            $id = (string) Str::uuid();
            $caseNumber = 'HLP-'.now()->format('Ymd').'-'.Str::upper(substr(str_replace('-', '', $id), 0, 8));
            $now = now();
            DB::table('help_support_cases')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'case_number' => $caseNumber,
                'category' => $data['category'],
                'priority' => $data['priority'],
                'affected_screen_code' => $data['affected_screen_code'] ?? null,
                'subject' => trim($data['subject']),
                'description' => trim($data['description']),
                'status' => 'OPEN',
                'record_version' => 1,
                'requester_id' => $data['actor_id'],
                'assigned_to' => null,
                'resolution_summary' => null,
                'resolved_at' => null,
                'resolved_by' => null,
                'closed_at' => null,
                'closed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->event($id, 1, 'CREATED', null, 'OPEN', trim($data['description']), $data);
            $result = $this->result($id, $caseNumber, 'OPEN', 1);
            $this->record(
                'CREATE_HELP_SUPPORT_CASE', 'help.support.created', $id, $data, 1,
                ['case_number' => $caseNumber, 'category' => $data['category'], 'priority' => $data['priority']], $result,
            );
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function comment(string $id, string $message, array $data): array
    {
        return $this->transition($id, 'COMMENTED', trim($message), $data, function (object $case): array {
            if ($case->status === 'CLOSED') {
                throw ValidationException::withMessages(['status' => ['A closed support case cannot receive comments.']]);
            }

            return [$case->status, []];
        });
    }

    public function start(string $id, array $data): array
    {
        return $this->transition($id, 'STARTED', 'Support ownership accepted.', $data, function (object $case) use ($data): array {
            if ($case->status !== 'OPEN') {
                throw ValidationException::withMessages(['status' => ['Only an open support case can be started.']]);
            }

            return ['IN_PROGRESS', ['assigned_to' => $data['actor_id']]];
        }, true);
    }

    public function resolve(string $id, string $summary, array $data): array
    {
        return $this->transition($id, 'RESOLVED', trim($summary), $data, function (object $case) use ($summary, $data): array {
            if ($case->status !== 'IN_PROGRESS') {
                throw ValidationException::withMessages(['status' => ['Start the support case before resolving it.']]);
            }

            return ['RESOLVED', [
                'resolution_summary' => trim($summary),
                'resolved_at' => now(),
                'resolved_by' => $data['actor_id'],
            ]];
        }, true);
    }

    public function reopen(string $id, string $reason, array $data): array
    {
        return $this->transition($id, 'REOPENED', trim($reason), $data, function (object $case): array {
            if ($case->status !== 'RESOLVED') {
                throw ValidationException::withMessages(['status' => ['Only a resolved support case can be reopened.']]);
            }

            return ['IN_PROGRESS', [
                'resolution_summary' => null,
                'resolved_at' => null,
                'resolved_by' => null,
            ]];
        });
    }

    public function close(string $id, string $confirmation, array $data): array
    {
        return $this->transition($id, 'CLOSED', trim($confirmation), $data, function (object $case) use ($data): array {
            if ($case->status !== 'RESOLVED') {
                throw ValidationException::withMessages(['status' => ['Only a resolved support case can be closed.']]);
            }

            return ['CLOSED', ['closed_at' => now(), 'closed_by' => $data['actor_id']]];
        });
    }

    private function transition(
        string $id,
        string $eventType,
        string $message,
        array $data,
        callable $change,
        bool $managerOnly = false,
    ): array {
        return DB::transaction(function () use ($id, $eventType, $message, $data, $change, $managerOnly): array {
            $namespace = 'help.support.'.Str::lower($eventType).'.'.$id;
            $input = $data + ['case_id' => $id, 'message' => $message];
            if ($replay = $this->begin($namespace, $input)) return $replay;
            $case = DB::table('help_support_cases')->where('id', $id)->where($this->scope($data))->lockForUpdate()->first();
            if (! $case) throw new NotFoundHttpException('Support case not found.');
            $manage = in_array('ACTION:ADM-HELP:MANAGE', $data['permissions'], true);
            if ($managerOnly && ! $manage) throw new NotFoundHttpException('Support case not found.');
            if (! $managerOnly && $case->requester_id !== $data['actor_id'] && ! $manage) {
                throw new NotFoundHttpException('Support case not found.');
            }
            if ((int) $case->record_version !== (int) $data['expected_version']) {
                throw new ConflictHttpException('The support case changed. Refresh it before continuing.');
            }

            [$nextStatus, $changes] = $change($case);
            $version = (int) $case->record_version + 1;
            DB::table('help_support_cases')->where('id', $id)->update($changes + [
                'status' => $nextStatus,
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $this->event(
                $id,
                DB::table('help_support_case_events')->where('help_support_case_id', $id)->count() + 1,
                $eventType,
                $case->status,
                $nextStatus,
                $message,
                $data,
            );
            $result = $this->result($id, $case->case_number, $nextStatus, $version);
            $this->record(
                $eventType.'_HELP_SUPPORT_CASE', 'help.support.'.Str::lower($eventType), $id, $data, $version,
                ['status' => ['from' => $case->status, 'to' => $nextStatus], 'message' => $message], $result,
            );
            $this->complete($namespace, $input, $result);

            return $result;
        }, 3);
    }

    private function assertScreenAccess(?string $screenCode, array $data): void
    {
        if ($screenCode && ! in_array('SCREEN:'.$screenCode.':VIEW', $data['permissions'], true)) {
            throw ValidationException::withMessages([
                'affected_screen_code' => ['Choose a screen available in your current role and context.'],
            ]);
        }
    }

    private function event(
        string $caseId,
        int $sequence,
        string $eventType,
        ?string $from,
        ?string $to,
        string $message,
        array $data,
    ): void {
        DB::table('help_support_case_events')->insert([
            'id' => (string) Str::uuid(),
            'help_support_case_id' => $caseId,
            'company_id' => $data['company_id'],
            'plant_id' => $data['plant_id'],
            'sequence_number' => $sequence,
            'event_type' => $eventType,
            'status_from' => $from,
            'status_to' => $to,
            'message' => $message,
            'actor_id' => $data['actor_id'],
            'created_at' => now(),
        ]);
    }

    private function result(string $id, string $number, string $status, int $version): array
    {
        return [
            'entity_type' => 'help_support_case',
            'id' => $id,
            'case_number' => $number,
            'status' => $status,
            'record_version' => $version,
        ];
    }

    private function scope(array $data): array
    {
        return ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']];
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']),
        );
    }

    private function complete(string $namespace, array $data, array $result): void
    {
        $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
    }

    private function record(
        string $command,
        string $event,
        string $id,
        array $data,
        int $version,
        array $diff,
        array $result,
    ): void {
        $this->audit->record($command, 'help_support_case', $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version,
            'correlation_id' => $data['correlation_id'] ?? null,
            'safe_diff' => $diff,
        ]);
        $this->outbox->append(
            $event, 'help_support_case', $id, $id.':'.$version, $result + $this->scope($data),
            $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id'],
        );
    }
}
