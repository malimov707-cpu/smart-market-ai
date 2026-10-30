<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CatalogFile;
use App\Models\ImportBatch;
use App\Services\ManageImports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeImports extends Command
{
    protected $signature = 'imports:purge {--execute : Physically remove expired unapproved cancelled/failed originals; default is dry run}';

    protected $description = 'Purge only expired unapproved imports; preserve confirmed evidence and audit metadata';

    public function handle(ManageImports $imports): int
    {
        $count = 0;
        foreach (ImportBatch::whereIn('status', ['cancelled', 'failed'])->whereNull('reviewed_at')->whereNull('purged_at')->where('expires_at', '<=', now())->orderBy('id')->lazyById(100) as $candidate) {
            if ($this->option('execute')) {
                DB::transaction(function () use ($candidate, $imports): void {
                    $batch = ImportBatch::lockForUpdate()->findOrFail($candidate->id);
                    if ($batch->reviewed_at || $batch->purged_at || ! in_array($batch->status, ['cancelled', 'failed'], true) || $batch->expires_at->isFuture()
                        || CatalogFile::where('import_batch_id', $batch->id)->exists()) {
                        return;
                    }
                    Storage::disk('catalog')->delete($batch->file_path);
                    $batch->rows()->delete();
                    // Unapproved correction payloads follow the same retention as their original file.
                    $batch->events()->where('action', 'row_corrected')->update(['metadata' => null]);
                    $batch->forceFill(['purged_at' => now(), 'headers' => null, 'mapping' => null])->save();
                    $imports->transition($batch, $batch->status, 'unapproved_source_purged');
                });
            }
            $count++;
        }
        $this->info($count.' retention candidates; '.($this->option('execute') ? 'eligible unapproved sources purged.' : 'dry run; no files removed.'));

        return self::SUCCESS;
    }
}
