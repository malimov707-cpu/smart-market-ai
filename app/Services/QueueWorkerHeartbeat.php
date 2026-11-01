<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class QueueWorkerHeartbeat
{
    private ?int $lastAttemptAt = null;

    /** Idle iterations and job starts show recent activity, not queue or job success. */
    public function record(WorkerIdle|JobProcessing $event): void
    {
        if (! config('operations.worker_heartbeat.enabled') ||
            $event->connectionName !== config('imports.queue_connection')) {
            return;
        }
        $queue = $event instanceof WorkerIdle ? $event->queue : $event->job->getQueue();
        if (! in_array(config('imports.queue'), explode(',', (string) $queue), true)) {
            return;
        }

        $now = now()->getTimestamp();
        if ($this->lastAttemptAt !== null && $now - $this->lastAttemptAt < (int) config('operations.worker_heartbeat.interval_seconds')) {
            return;
        }
        $this->lastAttemptAt = $now;

        try {
            $path = config('operations.worker_heartbeat.path');
            File::ensureDirectoryExists(dirname($path), 0700);
            File::replace($path, json_encode(['stream' => $this->stream(), 'at' => $now], JSON_THROW_ON_ERROR), 0600);
        } catch (\Throwable) {
            rescue(fn () => Log::warning('Import worker heartbeat could not be recorded.'), report: false);
        }
    }

    /**
     * Read-only; a recent signal does not prove delivery or current process liveness.
     *
     * @return array{worker: string, worker_heartbeat_age_seconds: ?int}
     */
    public function snapshot(): array
    {
        if (! config('operations.worker_heartbeat.enabled')) {
            return ['worker' => 'unknown', 'worker_heartbeat_age_seconds' => null];
        }

        try {
            $path = config('operations.worker_heartbeat.path');
            if (! File::exists($path)) {
                return ['worker' => 'heartbeat_missing', 'worker_heartbeat_age_seconds' => null];
            }
            if (File::size($path) > 256) {
                return ['worker' => 'heartbeat_invalid', 'worker_heartbeat_age_seconds' => null];
            }
            $value = json_decode(File::get($path), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($value) || ($value['stream'] ?? null) !== $this->stream() || ! is_int($value['at'] ?? null) || $value['at'] > now()->getTimestamp()) {
                return ['worker' => 'heartbeat_invalid', 'worker_heartbeat_age_seconds' => null];
            }
            $age = now()->getTimestamp() - $value['at'];

            return ['worker' => $age <= (int) config('operations.worker_heartbeat.max_age_seconds') ? 'heartbeat_recent' : 'heartbeat_stale', 'worker_heartbeat_age_seconds' => $age];
        } catch (\Throwable) {
            return ['worker' => 'heartbeat_unavailable', 'worker_heartbeat_age_seconds' => null];
        }
    }

    private function stream(): string
    {
        return hash('sha256', config('imports.queue_connection').'|'.config('imports.queue'));
    }
}
