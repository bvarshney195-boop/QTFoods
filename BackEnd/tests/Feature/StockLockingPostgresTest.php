<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class StockLockingPostgresTest extends TestCase
{
    use DatabaseMigrations;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const ACTOR_ID = '00000000-0000-4000-8000-000000000202';
    private const ITEM_ID = '00000000-0000-4000-8000-000000000603';
    private const LOT_ID = '00000000-0000-4000-8000-000000000703';
    private const SOURCE_ID = '00000000-0000-4000-8000-000000001213';
    private const HOLDER_TARGET_ID = 'ffffffff-ffff-4fff-8fff-fffffffffff1';
    private const CONTENDER_TARGET_ID = 'ffffffff-ffff-4fff-8fff-fffffffffff2';
    private const HOLDER_SOURCE_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
    private const CONTENDER_SOURCE_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2';

    protected $seed = true;

    private ?string $coordinationDirectory = null;

    /** @var array<int, array<string, mixed>> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Simultaneous row-lock assertions require PostgreSQL.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Simultaneous row-lock assertions require proc_open.');
        }

        $this->coordinationDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'qtfoods-stock-lock-'.bin2hex(random_bytes(8));

        if (! mkdir($this->coordinationDirectory, 0700)) {
            throw new RuntimeException('Unable to create the stock-lock coordination directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if (isset($worker['release_path'])) {
                file_put_contents($worker['release_path'], 'release', LOCK_EX);
            }
        }

        foreach (array_keys($this->workers) as $index) {
            $this->closeWorker($index);
        }

        if ($this->coordinationDirectory !== null && is_dir($this->coordinationDirectory)) {
            foreach (glob($this->coordinationDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($this->coordinationDirectory);
        }

        parent::tearDown();
    }

    public function test_competing_stock_movements_block_and_cannot_overconsume(): void
    {
        $this->prepareStockPositions();
        $suffix = bin2hex(random_bytes(6));
        $holderName = 'qtfoods-stock-holder-'.$suffix;
        $contenderName = 'qtfoods-stock-contender-'.$suffix;
        $holderKey = 'stock-lock-holder-'.$suffix;
        $contenderKey = 'stock-lock-contender-'.$suffix;

        $holder = $this->startWorker('holder', $holderName, $this->command(
            self::HOLDER_TARGET_ID,
            self::HOLDER_SOURCE_ID,
            $holderKey,
        ));
        $this->waitForSignal($holder, 'ready_path', 'the holder to retain its transaction locks');

        $contender = $this->startWorker('contender', $contenderName, $this->command(
            self::CONTENDER_TARGET_ID,
            self::CONTENDER_SOURCE_ID,
            $contenderKey,
        ));

        try {
            $this->waitForSignal($contender, 'started_path', 'the contender to connect');
            $this->assertTrue(
                $this->waitForPostgresBlock($contenderName, $contender),
                'PostgreSQL never reported the contender waiting on the holder transaction.',
            );
        } finally {
            file_put_contents($this->workers[$holder]['release_path'], 'release', LOCK_EX);
        }

        $holderResult = $this->waitForWorker($holder);
        $contenderResult = $this->waitForWorker($contender);

        $this->assertSame('success', $holderResult['status']);
        $this->assertSame('validation_error', $contenderResult['status']);
        $this->assertSame(
            ['Insufficient eligible stock at commit time.'],
            $contenderResult['errors']['quantity'] ?? null,
        );
        $this->assertSame(3.0, (float) DB::table('stock_positions')->where('id', self::SOURCE_ID)->value('quantity_base'));
        $this->assertSame(7.0, (float) DB::table('stock_positions')->where('id', self::HOLDER_TARGET_ID)->value('quantity_base'));
        $this->assertSame(0.0, (float) DB::table('stock_positions')->where('id', self::CONTENDER_TARGET_ID)->value('quantity_base'));
        $this->assertSame(2, (int) DB::table('stock_positions')->where('id', self::SOURCE_ID)->value('record_version'));
        $this->assertSame(2, (int) DB::table('stock_positions')->where('id', self::HOLDER_TARGET_ID)->value('record_version'));
        $this->assertSame(1, (int) DB::table('stock_positions')->where('id', self::CONTENDER_TARGET_ID)->value('record_version'));

        $movementId = $holderResult['result']['movement_id'];
        $this->assertSame(1, DB::table('stock_movements')->whereIn('source_id', [
            self::HOLDER_SOURCE_ID,
            self::CONTENDER_SOURCE_ID,
        ])->count());
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movementId,
            'from_position_id' => self::SOURCE_ID,
            'to_position_id' => self::HOLDER_TARGET_ID,
            'quantity_base' => 7,
        ]);
        $this->assertDatabaseHas('idempotency_keys', [
            'namespace' => 'inventory.movement',
            'key' => $holderKey,
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseMissing('idempotency_keys', [
            'namespace' => 'inventory.movement',
            'key' => $contenderKey,
        ]);
        $this->assertSame(1, DB::table('audit_events')->where([
            'entity_type' => 'stock_movement',
            'entity_id' => $movementId,
            'outcome' => 'SUCCESS',
        ])->count());
        $this->assertSame(1, DB::table('outbox_events')->where([
            'aggregate_type' => 'stock_movement',
            'aggregate_id' => $movementId,
        ])->count());
    }

    private function prepareStockPositions(): void
    {
        $now = now();

        DB::table('stock_reservations')->where('stock_position_id', self::SOURCE_ID)->delete();
        DB::table('stock_positions')->where('id', self::SOURCE_ID)->update([
            'quantity_base' => 10,
            'reserved_quantity_base' => 0,
            'record_version' => 1,
            'updated_at' => $now,
        ]);

        $occupiedLocationIds = DB::table('stock_positions')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)
            ->where('item_id', self::ITEM_ID)
            ->where('lot_id', self::LOT_ID)
            ->where('inventory_owner_id', self::COMPANY_ID)
            ->where('quality_status', 'RELEASED')
            ->where('uom_code', 'KG')
            ->pluck('location_id')
            ->all();
        $targetLocationIds = DB::table('locations')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)
            ->where('status', 'ACTIVE')
            ->whereNotIn('id', $occupiedLocationIds)
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->all();

        if (count($targetLocationIds) !== 2) {
            throw new RuntimeException('Two unused stock coordinates are required for the concurrency test.');
        }

        foreach ([
            [self::HOLDER_TARGET_ID, $targetLocationIds[0]],
            [self::CONTENDER_TARGET_ID, $targetLocationIds[1]],
        ] as [$positionId, $locationId]) {
            DB::table('stock_positions')->insert([
                'id' => $positionId,
                'company_id' => self::COMPANY_ID,
                'plant_id' => self::PLANT_ID,
                'item_id' => self::ITEM_ID,
                'lot_id' => self::LOT_ID,
                'owner_party_id' => null,
                'inventory_owner_id' => self::COMPANY_ID,
                'location_id' => $locationId,
                'quality_status' => 'RELEASED',
                'quantity_base' => 0,
                'reserved_quantity_base' => 0,
                'uom_code' => 'KG',
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function command(string $targetId, string $sourceId, string $idempotencyKey): array
    {
        return [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID,
            'source_position_id' => self::SOURCE_ID,
            'target_position_id' => $targetId,
            'quantity_base' => '7',
            'uom_code' => 'KG',
            'movement_type' => 'TRANSFER',
            'source_type' => 'CONCURRENCY_TEST',
            'source_id' => $sourceId,
            'actor_id' => self::ACTOR_ID,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /** @param array<string, mixed> $command */
    private function startWorker(string $mode, string $applicationName, array $command): int
    {
        $index = count($this->workers);
        $prefix = $this->coordinationDirectory.DIRECTORY_SEPARATOR.$mode;
        $payloadPath = $prefix.'.payload.json';
        $worker = [
            'mode' => $mode,
            'application_name' => $applicationName,
            'command' => $command,
            'started_path' => $prefix.'.started',
            'result_path' => $prefix.'.result.json',
            'payload_path' => $payloadPath,
        ];

        if ($mode === 'holder') {
            $worker['ready_path'] = $prefix.'.ready';
            $worker['release_path'] = $prefix.'.release';
        }

        file_put_contents($payloadPath, json_encode($worker, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/StockMovementWorker.php'), $payloadPath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path(),
            null,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException("Unable to start the {$mode} stock worker.");
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $worker['process'] = $process;
        $worker['pipes'] = $pipes;
        $this->workers[$index] = $worker;

        return $index;
    }

    private function waitForSignal(int $workerIndex, string $pathKey, string $description): void
    {
        $path = $this->workers[$workerIndex][$pathKey];
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return;
            }

            if (! $this->workerIsRunning($workerIndex)) {
                break;
            }

            usleep(20_000);
        }

        throw new RuntimeException(
            "Timed out waiting for {$description}. ".$this->workerDiagnostics($workerIndex),
        );
    }

    private function waitForPostgresBlock(string $applicationName, int $workerIndex): bool
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline && $this->workerIsRunning($workerIndex)) {
            $activity = DB::selectOne(<<<'SQL'
SELECT wait_event_type,
       cardinality(pg_blocking_pids(pid)) AS blocking_count
FROM pg_stat_activity
WHERE datname = current_database()
  AND application_name = ?
ORDER BY backend_start DESC
LIMIT 1
SQL, [$applicationName]);

            if (
                $activity !== null
                && $activity->wait_event_type === 'Lock'
                && (int) $activity->blocking_count > 0
            ) {
                return true;
            }

            usleep(20_000);
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function waitForWorker(int $workerIndex): array
    {
        $deadline = microtime(true) + 15;
        $lastStatus = null;

        while (microtime(true) < $deadline) {
            $lastStatus = proc_get_status($this->workers[$workerIndex]['process']);
            if (! $lastStatus['running']) {
                break;
            }

            usleep(20_000);
        }

        if ($lastStatus === null || $lastStatus['running']) {
            proc_terminate($this->workers[$workerIndex]['process']);
            throw new RuntimeException('Stock worker timed out. '.$this->workerDiagnostics($workerIndex));
        }

        $diagnostics = $this->workerDiagnostics($workerIndex);
        $exitCode = $lastStatus['exitcode'];
        $resultPath = $this->workers[$workerIndex]['result_path'];
        $this->closeWorker($workerIndex, false);

        if ($exitCode !== 0) {
            throw new RuntimeException("Stock worker exited with {$exitCode}. {$diagnostics}");
        }

        if (! is_file($resultPath)) {
            throw new RuntimeException("Stock worker did not write a result. {$diagnostics}");
        }

        return json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
    }

    private function workerIsRunning(int $workerIndex): bool
    {
        return is_resource($this->workers[$workerIndex]['process'])
            && proc_get_status($this->workers[$workerIndex]['process'])['running'];
    }

    private function workerDiagnostics(int $workerIndex): string
    {
        $worker = $this->workers[$workerIndex];
        $stdout = is_resource($worker['pipes'][1]) ? stream_get_contents($worker['pipes'][1]) : '';
        $stderr = is_resource($worker['pipes'][2]) ? stream_get_contents($worker['pipes'][2]) : '';

        return trim("stdout={$stdout} stderr={$stderr}");
    }

    private function closeWorker(int $workerIndex, bool $terminate = true): void
    {
        $process = $this->workers[$workerIndex]['process'] ?? null;
        if (! is_resource($process)) {
            return;
        }

        if ($terminate && proc_get_status($process)['running']) {
            proc_terminate($process);
        }

        foreach ($this->workers[$workerIndex]['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($process);
        $this->workers[$workerIndex]['process'] = null;
    }
}
