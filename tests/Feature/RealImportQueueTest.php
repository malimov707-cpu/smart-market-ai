<?php

use App\Services\ManageImports;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('runs preparation confirmation retry failure and idempotent commit through actual PostgreSQL queue workers', function () {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'quality_queue_test')) {
        $this->markTestSkipped('Requires a separately provisioned quality_queue_test database, without a wrapping transaction.');
    }
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    $directory = sys_get_temp_dir().'/smart-market-queue-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    config(['filesystems.disks.catalog.root' => $directory, 'imports.queue_connection' => 'database']);
    $database = config('database.connections.pgsql');
    $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'],
        'DB_URL' => '', 'CATALOG_STORAGE_PATH' => $directory, 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'database'];
    $worker = function () use ($environment): string {
        $process = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=imports', '--once', '--sleep=0', '--timeout=60', '--tries=2', '--no-interaction'], base_path(), $environment, null, 80);
        $process->mustRun();

        return $process->getOutput();
    };
    try {
        $failuresBefore = DB::table('failed_jobs')->count();
        [$user,$org] = marketIdentity();
        $imports = app(ManageImports::class);
        $file = importXlsx(['Data' => [['name', 'sku', 'unit'], ['TEST real queue', 'REAL-QUEUE-'.bin2hex(random_bytes(4)), 'kg']]]);
        $started = hrtime(true);
        $batch = $imports->upload($user, $org, $file, ['type' => 'products', 'timezone' => 'UTC', 'header_row' => 20]);
        expect(DB::table('jobs')->where('queue', 'imports')->count())->toBe(1);
        $worker();
        $batch = $batch->fresh();
        expect($batch->status)->toBe('failed');
        expect(DB::table('failed_jobs')->count())->toBeGreaterThan(0);
        $imports->retry($user, $batch);
        $worker();
        expect($batch->fresh()->attempts)->toBe(2);
        $imports->selectSheet($user, $batch->fresh(), 0, 1);
        $worker();
        $batch = $batch->fresh();
        expect($batch->status)->toBe('awaiting_confirmation');
        expect($batch->events()->where('to_status', 'validating')->count())->toBe(3);
        $imports->confirm($user, $batch, $batch->revision);
        expect($batch->fresh()->status)->toBe('queued');
        $worker();
        $batch = $batch->fresh();
        expect($batch->status)->toBe('completed');
        expect($batch->rows()->where('status', 'created')->count())->toBe(1);
        expect($batch->events()->where('to_status', 'processing')->exists())->toBeTrue();
        $imports->dispatch($batch);
        $worker();
        expect($batch->rows()->where('status', 'created')->count())->toBe(1);
        expect(DB::table('jobs')->where('queue', 'imports')->count())->toBe(0);
        $partial = $imports->upload($user, $org, importCsv([['name', 'sku', 'unit'], ['TEST valid subset', 'PARTIAL-'.bin2hex(random_bytes(4)), 'kg'], ['TEST invalid subset', 'INVALID-'.bin2hex(random_bytes(4)), 'bad']]), ['type' => 'products', 'timezone' => 'UTC']);
        $worker();
        $partial = $partial->fresh();
        $imports->configure($user, $partial, ['revision' => $partial->revision, 'mapping' => $partial->mapping, 'duplicate_policy' => 'skip', 'error_policy' => 'valid_rows']);
        $partial = $partial->fresh();
        $imports->confirm($user, $partial, $partial->revision);
        $worker();
        expect($partial->fresh()->status)->toBe('completed_with_errors');
        expect($partial->fresh()->summary)->toMatchArray(['created' => 1, 'failed' => 1]);

        file_put_contents(storage_path('framework/testing/quality-queue.json'), json_encode(['driver' => 'PostgreSQL database queue', 'worker' => 'actual artisan queue:work --once', 'wall_ms' => (hrtime(true) - $started) / 1000000, 'attempts' => $batch->attempts, 'completed' => $batch->status, 'failed_jobs_added' => DB::table('failed_jobs')->count() - $failuresBefore], JSON_PRETTY_PRINT));
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});
