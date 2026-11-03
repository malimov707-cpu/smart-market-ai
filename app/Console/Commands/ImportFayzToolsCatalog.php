<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FayzToolsCatalogImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportFayzToolsCatalog extends Command
{
    protected $signature = 'fayztools:import {--source= : JSON file or directory with the catalog export} {--limit= : Maximum row count to import}';

    protected $description = 'Create the FayzTools organization and import the product catalog export';

    public function handle(FayzToolsCatalogImporter $importer): int
    {
        try {
            $results = $importer->import($this->option('source'), $this->option('limit') !== null ? (int) $this->option('limit') : null);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $throwable) {
            $this->error('FayzTools import failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d product records into FayzTools (organization #%d). Skipped %d invalid rows.',
            $results['imported'],
            $results['organization_id'],
            $results['skipped'],
        ));

        return self::SUCCESS;
    }
}
