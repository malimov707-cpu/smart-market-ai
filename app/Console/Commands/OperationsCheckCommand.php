<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OperationsCheck;
use Illuminate\Console\Command;

class OperationsCheckCommand extends Command
{
    protected $signature = 'ops:check {--dependencies : Read-only database/storage probes}';

    protected $description = 'Secret-safe readiness checks without migrations, writes or live service calls';

    public function handle(OperationsCheck $checks): int
    {
        $configuration = $checks->configuration();
        $dependencies = $this->option('dependencies') ? $checks->dependencies() : ['worker' => 'unknown'];
        $issues = array_keys(array_filter($configuration, fn (array $row): bool => ! $row['ok']));
        if (isset($dependencies['database']) && $dependencies['database'] !== 'reachable') {
            $issues[] = 'database_probe';
        }
        if (isset($dependencies['storage_directory']) && $dependencies['storage_directory'] !== 'accessible') {
            $issues[] = 'storage_probe';
        }
        if (($dependencies['pending_schema_changes'] ?? 0) > 0) {
            $issues[] = 'schema_changes_require_separate_authorization';
        }
        if (($dependencies['failed_jobs'] ?? 0) > 0 || ($dependencies['oldest_job_age_seconds'] ?? 0) > config('operations.max_queue_age_seconds')) {
            $issues[] = 'queue_attention';
        }
        if ($this->option('dependencies') && config('operations.worker_heartbeat.enabled') && ($dependencies['worker'] ?? null) !== 'heartbeat_recent') {
            $issues[] = 'worker_heartbeat';
        }
        if (($dependencies['database_role_admin'] ?? false) || (($dependencies['database_tls_active'] ?? null) === false)) {
            $issues[] = 'database_security';
        }
        $this->line(json_encode(['configuration_passed' => $issues === [], 'classification' => 'NOT READY', 'issues' => $issues,
            'checks' => $configuration, 'dependencies' => $dependencies, 'note' => 'Config PASS production verification emas; live evidence va release approval alohida.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
