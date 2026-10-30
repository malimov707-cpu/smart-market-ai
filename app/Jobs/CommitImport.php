<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\ImportFailure;
use App\Services\ManageImports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommitImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public array $backoff = [5, 15];

    public function __construct(public int $batchId, public int $generation = 0) {}

    public function handle(ManageImports $imports): void
    {
        $token = $imports->claim($this->batchId, 'commit', $this->generation);
        if ($token === null) {
            return;
        }
        try {
            $imports->commit($this->batchId, $token);
        } catch (ValidationException) {
            $imports->fail(ImportBatch::findOrFail($this->batchId), 'validation_failed', $token);
            $this->fail(new ImportFailure('validation_failed'));
        } catch (ImportFailure $error) {
            if ($error->retryable && $this->attempts() < $this->tries) {
                $imports->release($this->batchId, $token, $error->errorCode);
                throw $error;
            }
            $imports->fail(ImportBatch::findOrFail($this->batchId), $error->errorCode, $token);
            $this->fail($error);
        } catch (Throwable) {
            $imports->fail(ImportBatch::findOrFail($this->batchId), 'processing_failed', $token);
            $this->fail(new ImportFailure('processing_failed'));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $batch = ImportBatch::find($this->batchId);
        if ($batch && $batch->generation === $this->generation && ! $batch->lease_expires_at?->isFuture()) {
            app(ManageImports::class)->fail($batch, 'worker_exhausted');
        }
    }
}
