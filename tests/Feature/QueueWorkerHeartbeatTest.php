<?php

use App\Services\OperationsCheck;
use App\Services\QueueWorkerHeartbeat;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

function configureHeartbeatTest(): string
{
    $directory = sys_get_temp_dir().'/smart-market-heartbeat-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    app()->instance('heartbeat-test-directory', $directory);
    config(['operations.worker_heartbeat.enabled' => true, 'operations.worker_heartbeat.path' => $directory.'/heartbeat.json']);

    return $directory;
}

afterEach(function () {
    if ($this->app->bound('heartbeat-test-directory')) {
        (new Filesystem)->deleteDirectory($this->app->make('heartbeat-test-directory'));
    }
});

it('keeps monitoring unknown and writes nothing when heartbeats are disabled', function () {
    $directory = configureHeartbeatTest();
    config(['operations.worker_heartbeat.enabled' => false]);

    Event::dispatch(new WorkerIdle('database', 'imports', new WorkerOptions));

    expect(app(QueueWorkerHeartbeat::class)->snapshot())->toBe(['worker' => 'unknown', 'worker_heartbeat_age_seconds' => null]);
    expect(File::files($directory))->toBeEmpty();
});

it('records a private recent signal only for the configured connection and import queue', function () {
    configureHeartbeatTest();
    $this->freezeTime();
    $heartbeat = app(QueueWorkerHeartbeat::class);
    Event::dispatch(new WorkerIdle('redis', 'imports', new WorkerOptions));
    Event::dispatch(new WorkerIdle('database', 'other-imports', new WorkerOptions));
    expect($heartbeat->snapshot()['worker'])->toBe('heartbeat_missing');

    Event::dispatch(new WorkerIdle('database', 'default,imports', new WorkerOptions));

    expect($heartbeat->snapshot())->toBe(['worker' => 'heartbeat_recent', 'worker_heartbeat_age_seconds' => 0]);
    expect(fileperms(config('operations.worker_heartbeat.path')) & 0777)->toBe(0600);
});

it('records import job start events and excludes synchronous jobs', function () {
    configureHeartbeatTest();
    $this->freezeTime();

    Event::dispatch(new JobProcessing('database', new SyncJob(app(), '{}', 'database', 'imports')));
    expect(app(QueueWorkerHeartbeat::class)->snapshot()['worker'])->toBe('heartbeat_missing');
    Event::dispatch(new JobProcessing('database', new DatabaseJob(app(), app('queue')->connection('database'), (object) ['payload' => '{}'], 'database', 'imports')));

    expect(app(QueueWorkerHeartbeat::class)->snapshot()['worker'])->toBe('heartbeat_recent');
});

it('rejects a monitoring threshold that can expire while a permitted import job is running', function () {
    config(['operations.worker_heartbeat.enabled' => true, 'operations.worker_heartbeat.max_age_seconds' => 60]);

    expect(app(OperationsCheck::class)->configuration()['worker_monitoring']['ok'])->toBeFalse();

    config(['operations.worker_heartbeat.max_age_seconds' => 120]);
    expect(app(OperationsCheck::class)->configuration()['worker_monitoring']['ok'])->toBeTrue();
});

it('throttles heartbeat writes and refreshes after the configured interval', function () {
    configureHeartbeatTest();
    $this->freezeTime();
    Event::dispatch(new WorkerIdle('database', 'imports', new WorkerOptions));
    $this->travel(5)->seconds();
    Event::dispatch(new WorkerIdle('database', 'imports', new WorkerOptions));
    expect(app(QueueWorkerHeartbeat::class)->snapshot()['worker_heartbeat_age_seconds'])->toBe(5);

    $this->travel(5)->seconds();
    Event::dispatch(new WorkerIdle('database', 'imports', new WorkerOptions));

    expect(app(QueueWorkerHeartbeat::class)->snapshot()['worker_heartbeat_age_seconds'])->toBe(0);
});

it('detects a stopped worker after the threshold without modifying its last signal', function () {
    configureHeartbeatTest();
    $this->freezeTime();
    Event::dispatch(new WorkerIdle('database', 'imports', new WorkerOptions));
    $content = File::get(config('operations.worker_heartbeat.path'));
    $this->travel(121)->seconds();

    $snapshot = app(QueueWorkerHeartbeat::class)->snapshot();

    expect($snapshot)->toBe(['worker' => 'heartbeat_stale', 'worker_heartbeat_age_seconds' => 121]);
    expect(File::get(config('operations.worker_heartbeat.path')))->toBe($content);
});

it('rejects invalid future oversized and mismatched heartbeat evidence', function (string $failure) {
    configureHeartbeatTest();
    $this->freezeTime();
    Event::dispatch(new WorkerIdle('database', 'imports', new WorkerOptions));
    $path = config('operations.worker_heartbeat.path');
    $value = json_decode(File::get($path), true);
    $content = match ($failure) {
        'future' => json_encode([...$value, 'at' => now()->addSecond()->getTimestamp()]),
        'stream' => json_encode([...$value, 'stream' => 'incorrect']),
        'oversized' => str_repeat('x', 257),
        default => 'invalid json',
    };
    File::put($path, $content);

    expect(app(QueueWorkerHeartbeat::class)->snapshot()['worker'])->toBeIn(['heartbeat_invalid', 'heartbeat_unavailable']);
})->with(['future', 'stream', 'oversized', 'json']);

it('does not interrupt business jobs or log sensitive exception details if recording fails', function (bool $loggerUnavailable) {
    config(['operations.worker_heartbeat.enabled' => true]);
    File::shouldReceive('ensureDirectoryExists')->once()->andThrow(new RuntimeException('TEST-SECRET-PATH'));
    $log = Log::shouldReceive('warning')->once()->with('Import worker heartbeat could not be recorded.');
    if ($loggerUnavailable) {
        $log->andThrow(new RuntimeException('TEST-SECRET-LOGGER'));
    }

    Event::dispatch(new WorkerIdle('database', 'imports', new WorkerOptions));
})->with([false, true]);

it('fails dependency monitoring when the enabled worker has not reported a signal', function () {
    configureHeartbeatTest();

    $this->artisan('ops:check', ['--dependencies' => true])
        ->expectsOutputToContain('"worker_heartbeat"')->assertFailed();
});

it('observes a real idle worker through a private file across processes without running migrations', function () {
    $directory = configureHeartbeatTest();
    $databasePath = $directory.'/queue.sqlite';
    $database = new PDO('sqlite:'.$databasePath);
    chmod($databasePath, 0600);
    $database->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, queue VARCHAR(255), payload TEXT, attempts INTEGER, reserved_at INTEGER, available_at INTEGER, created_at INTEGER)');
    $database = null;

    $process = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=imports', '--sleep=1', '--max-time=2', '--timeout=60', '--no-interaction'], base_path(), [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_CONFIG_CACHE' => $directory.'/no-config-cache.php',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('T', 32)), 'LOG_CHANNEL' => 'stderr', 'IMPORT_AI_PROVIDER' => 'unavailable',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $databasePath, 'DB_URL' => '', 'DB_QUEUE_CONNECTION' => 'sqlite',
        'DB_QUEUE_TABLE' => 'jobs',
        'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
        'APP_MAINTENANCE_DRIVER' => 'file', 'LARAVEL_STORAGE_PATH' => $directory.'/storage',
        'OPS_WORKER_HEARTBEAT_ENABLED' => 'true', 'OPS_WORKER_HEARTBEAT_PATH' => config('operations.worker_heartbeat.path'),
    ], null, 10);

    $process->mustRun();

    expect($process->isSuccessful())->toBeTrue();
    expect(app(QueueWorkerHeartbeat::class)->snapshot()['worker'])->toBe('heartbeat_recent');
    $database = new PDO('sqlite:'.$databasePath);
    expect((int) $database->query('SELECT COUNT(*) FROM jobs')->fetchColumn())->toBe(0);
    $database = null;
});
