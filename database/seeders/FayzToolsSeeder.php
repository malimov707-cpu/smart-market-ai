<?php

namespace Database\Seeders;

use App\Services\FayzToolsCatalogImporter;
use Illuminate\Database\Seeder;

class FayzToolsSeeder extends Seeder
{
    public function run(FayzToolsCatalogImporter $importer): void
    {
        $importer->import();
    }
}
