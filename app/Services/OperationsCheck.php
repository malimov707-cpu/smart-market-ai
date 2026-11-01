<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CommitImport;
use App\Jobs\PrepareImport;
use Illuminate\Support\Facades\DB;

class OperationsCheck
{
    /** Configuration checks never contain credential values, URLs, addresses or filesystem paths. */
    public function configuration(): array
    {
        $connection = config('database.default');
        $database = config('database.connections.'.$connection, []);
        $mail = config('mail.mailers.'.config('mail.default'), []);
        $timeout = max((new PrepareImport(0))->timeout, (new CommitImport(0))->timeout);
        $retry = (int) config('queue.connections.database.retry_after');
        $smtp = ($mail['transport'] ?? null) === 'smtp';
        $tls = ($mail['scheme'] ?? null) === 'smtps' || (bool) ($mail['require_tls'] ?? false);
        $extensions = array_filter(['bcmath', 'gd', 'pdo_pgsql', 'zip', 'dom', 'mbstring', 'fileinfo', 'openssl'], fn (string $name): bool => ! extension_loaded($name));

        return [
            'environment' => ['ok' => in_array(app()->environment(), ['staging', 'production'], true), 'detail' => 'staging yoki production kerak'],
            'debug_disabled' => ['ok' => ! config('app.debug'), 'detail' => 'APP_DEBUG=false'],
            'https_url' => ['ok' => parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https', 'detail' => 'HTTPS URL; live TLS alohida tekshiriladi'],
            'application_key' => ['ok' => filled(config('app.key')), 'detail' => 'Mavjud kalit saqlansin'],
            'secure_sessions' => ['ok' => config('session.secure') === true && config('session.encrypt') === true && config('session.http_only') === true && in_array(config('session.same_site'), ['lax', 'strict'], true), 'detail' => 'Secure/encrypted/HttpOnly cookie'],
            'persistent_session_cache' => ['ok' => config('session.driver') === 'database' && config('cache.default') === 'database', 'detail' => 'Bir nechta process uchun database session/cache'],
            'database_tls' => ['ok' => ($database['driver'] ?? null) === 'pgsql' && ($database['sslmode'] ?? null) === 'verify-full', 'detail' => 'PostgreSQL verify-full; CA va least privilege live tekshirilsin'],
            'persistent_import_queue' => ['ok' => config('imports.queue_connection') === 'database' && config('queue.failed.driver') === 'database-uuids', 'detail' => 'database/imports + failed_jobs; worker faolligi noma’lum'],
            'queue_timeout' => ['ok' => $timeout < $retry && $retry >= 90, 'detail' => 'job='.$timeout.'s; retry_after='.$retry.'s; lease=90s'],
            'worker_monitoring' => ['ok' => config('operations.worker_heartbeat.enabled') === true && (int) config('operations.worker_heartbeat.interval_seconds') > 0 && (int) config('operations.worker_heartbeat.max_age_seconds') > $timeout + (int) config('operations.worker_heartbeat.interval_seconds'), 'detail' => 'Persistent private heartbeat; max age job timeout + intervaldan katta bo‘lsin'],
            'smtp_configuration' => ['ok' => $smtp && $tls && filled($mail['host'] ?? null) && filled($mail['username'] ?? null) && filled($mail['password'] ?? null) && (int) ($mail['timeout'] ?? 0) > 0 && empty($mail['url']), 'detail' => 'SMTP auth + mandatory TLS + finite timeout; URL overrides uchun alohida review'],
            'private_storage' => ['ok' => config('filesystems.disks.catalog.driver') === 'local' && config('filesystems.disks.catalog.visibility') === 'private', 'detail' => 'Persistent shared local volume kerak; ephemeral serverga mos emas'],
            'runtime_extensions' => ['ok' => $extensions === [], 'detail' => $extensions === [] ? 'PHP kengaytmalari mavjud' : 'Yetishmaydi: '.implode(',', $extensions)],
            'ai_configuration' => ['ok' => true, 'detail' => app(DocumentExtractor::class)->availability()['available'] ? 'Sozlangan; live verification hali yo‘q' : 'Unavailable; manual import ishlashi kerak'],
        ];
    }

    /** Explicit opt-in read-only dependency probes; no DDL, writes or outbound provider/mail requests. */
    public function dependencies(): array
    {
        $result = app(QueueWorkerHeartbeat::class)->snapshot() + ['smtp_live' => 'unverified', 'ai_live' => 'unverified'];
        try {
            DB::select('SELECT 1');
            $result['database'] = 'reachable';
            $applied = DB::table('migrations')->pluck('migration')->all();
            $expected = array_map(fn (string $path): string => basename($path, '.php'), glob(database_path('migrations/*.php')));
            $result['schema_migrations'] = count($applied);
            $result['pending_schema_changes'] = count(array_diff($expected, $applied));
            $queue = DB::table('jobs')->where('queue', config('imports.queue'));
            $result['pending_jobs'] = (clone $queue)->whereNull('reserved_at')->count();
            $result['reserved_jobs'] = (clone $queue)->whereNotNull('reserved_at')->count();
            $result['failed_jobs'] = DB::table('failed_jobs')->count();
            $oldest = (clone $queue)->min('created_at');
            $result['oldest_job_age_seconds'] = $oldest === null ? null : max(0, time() - (int) $oldest);
            $result['last_import_completed_at'] = DB::table('import_batches')->max('completed_at');
            if (DB::getDriverName() === 'pgsql') {
                $ssl = DB::selectOne('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()');
                $role = DB::selectOne('SELECT rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = current_user');
                $result['database_tls_active'] = (bool) ($ssl->ssl ?? false);
                $result['database_role_admin'] = (bool) ($role->rolsuper || $role->rolcreatedb || $role->rolcreaterole);
            }
        } catch (\Throwable) {
            $result['database'] = 'unavailable_or_schema_incomplete';
        }
        $root = config('filesystems.disks.catalog.root');
        $result['storage_directory'] = is_string($root) && is_dir($root) && is_readable($root) && is_writable($root) ? 'accessible' : 'unavailable';

        return $result;
    }
}
