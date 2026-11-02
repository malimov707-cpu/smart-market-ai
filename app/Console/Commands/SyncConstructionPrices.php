<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\PriceWatchController;
use App\Services\ConstructionCatalog;
use App\Services\PriceTrendHistory;
use App\Services\PublicMarketCatalog;
use Illuminate\Console\Command;

class SyncConstructionPrices extends Command
{
    protected $signature = 'market:sync-construction {--source=all : all, tezqur, kslux or alstroy}';

    protected $description = 'Refresh public construction catalogues, retaining each last successful snapshot on failure';

    public function handle(ConstructionCatalog $catalog, PublicMarketCatalog $market, PriceTrendHistory $trends): int
    {
        $source = (string) $this->option('source');
        if ($source !== 'all' && ! isset(ConstructionCatalog::SOURCES[$source])) {
            $this->error('Unknown construction source.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($source === 'all' ? array_keys(ConstructionCatalog::SOURCES) : [$source] as $name) {
            try {
                $count = $catalog->sync($name);
                foreach (['sement', 'gipsokarton', 'laminat', 'germetik'] as $query) {
                    $trends->record($query, $market->search($query, [], 'construction', $name)['products']);
                }
                PriceWatchController::dispatchChanges();
                PriceWatchController::dispatchDeals(['sement', 'gipsokarton', 'laminat', 'germetik']);
                $this->info(ConstructionCatalog::SOURCES[$name].': '.$count.' catalogue offers refreshed.');
            } catch (\Throwable) {
                $this->error(ConstructionCatalog::SOURCES[$name].': refresh failed; previous snapshot retained.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
