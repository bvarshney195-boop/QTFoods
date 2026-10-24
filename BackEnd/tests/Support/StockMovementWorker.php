<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\StockPostingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$backendRoot = dirname(__DIR__, 2);

require $backendRoot.'/vendor/autoload.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php StockMovementWorker.php PAYLOAD_PATH\n");
    exit(64);
}

try {
    $payload = json_decode(
        (string) file_get_contents($argv[1]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Unable to read the worker payload: {$exception->getMessage()}\n");
    exit(65);
}

$required = ['mode', 'application_name', 'command', 'started_path', 'result_path'];
foreach ($required as $key) {
    if (! array_key_exists($key, $payload)) {
        fwrite(STDERR, "Worker payload is missing {$key}.\n");
        exit(65);
    }
}

$writeFile = static function (string $path, string $contents): void {
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write coordination file {$path}.");
    }
};

$writeResult = static function (array $result) use ($payload, $writeFile): void {
    $writeFile(
        $payload['result_path'],
        json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    );
};

$exitCode = 0;
$transactionOpen = false;

try {
    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    DB::selectOne(
        "select set_config('application_name', ?, false)",
        [$payload['application_name']],
    );
    $writeFile($payload['started_path'], 'started');

    if ($payload['mode'] === 'holder') {
        foreach (['ready_path', 'release_path'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                throw new RuntimeException("Holder payload is missing {$key}.");
            }
        }

        DB::beginTransaction();
        $transactionOpen = true;
        $result = $app->make(StockPostingService::class)->move($payload['command']);
        $writeFile($payload['ready_path'], 'locked');

        $deadline = microtime(true) + 20;
        while (! is_file($payload['release_path'])) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting to release the held stock transaction.');
            }

            usleep(20_000);
            clearstatcache(true, $payload['release_path']);
        }

        DB::commit();
        $transactionOpen = false;
    } elseif ($payload['mode'] === 'contender') {
        $result = $app->make(StockPostingService::class)->move($payload['command']);
    } else {
        throw new RuntimeException("Unsupported worker mode {$payload['mode']}.");
    }

    $writeResult(['status' => 'success', 'result' => $result]);
} catch (ValidationException $exception) {
    if ($transactionOpen) {
        DB::rollBack();
        $transactionOpen = false;
    }

    $writeResult([
        'status' => 'validation_error',
        'errors' => $exception->errors(),
    ]);
} catch (Throwable $exception) {
    if ($transactionOpen) {
        DB::rollBack();
    }

    $writeResult([
        'status' => 'error',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ]);
    $exitCode = 1;
} finally {
    DB::disconnect();
}

exit($exitCode);
