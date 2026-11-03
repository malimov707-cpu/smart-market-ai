<?php

namespace Database\Seeders;

use App\Services\ApprovedCatalogImporter;
use App\Services\FayzToolsCatalogImporter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(FayzToolsCatalogImporter $importer, ApprovedCatalogImporter $approvedImporter): void
    {
        if (config('app.env') === 'production') {
            return;
        }

        try {
            $importer->import();
        } catch (RuntimeException $exception) {
            $this->command->warn('Skipping FayzTools seed import: '.$exception->getMessage());
        }

        try {
            $approvedImporter->import();
        } catch (RuntimeException $exception) {
            $this->command->warn('Skipping approved catalog seed import: '.$exception->getMessage());
        }
    }
}
