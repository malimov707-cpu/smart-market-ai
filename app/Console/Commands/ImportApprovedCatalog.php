<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ApprovedCatalogImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportApprovedCatalog extends Command
{
    protected $signature = 'market:import-approved-catalog {--path= : Path to the approved catalog JSON file}';

    protected $description = 'Import the verified internal catalog used for accurate AI matching.';

    public function handle(ApprovedCatalogImporter $importer): int
    {
        try {
            $results = $importer->import($this->option('path'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $throwable) {
            $this->error('Approved catalog import failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d approved catalog rows into organization #%d. Supplier #%d. Skipped %d rows.',
            $results['imported'],
            $results['organization_id'],
            $results['supplier_id'],
            $results['skipped'],
        ));

        return self::SUCCESS;
    }
}
