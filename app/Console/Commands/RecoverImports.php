<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\ManageImports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverImports extends Command
{
    protected $signature = 'imports:recover {--dry-run : List candidates without dispatch}';

    protected $description = 'Recover queued or expired-leased imports without duplicating business records';

    public function handle(ManageImports $imports): int
    {
        $count = 0;
        foreach (ImportBatch::whereIn('status', ['uploaded', 'queued', 'validating', 'processing'])
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->where('updated_at', '<=', now()->subSeconds(90))->orderBy('id')->lazyById(100) as $candidate) {
            if (! $this->option('dry-run')) {
                $batch = DB::transaction(function () use ($candidate, $imports): ?ImportBatch {
                    $batch = ImportBatch::lockForUpdate()->findOrFail($candidate->id);
                    if (! in_array($batch->status, ['uploaded', 'queued', 'validating', 'processing'], true) || $batch->lease_expires_at?->isFuture() || $batch->updated_at->gt(now()->subSeconds(90))) {
                        return null;
                    }
                    $batch->forceFill(['lease_token' => null, 'lease_expires_at' => null, 'generation' => $batch->generation + 1])->save();
                    $imports->transition($batch, 'queued', 'recovered');

                    return $batch;
                });
                if ($batch) {
                    $imports->dispatch($batch);
                }
            }
            $count++;
        }
        $this->info($count.' recovery candidates; '.($this->option('dry-run') ? 'dry run only.' : 'eligible imports re-dispatched.'));

        return self::SUCCESS;
    }
}
