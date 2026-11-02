<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\PriceWatchController;
use App\Services\KorzinkaCatalog;
use App\Services\PriceTrendHistory;
use App\Services\PublicMarketCatalog;
use Illuminate\Console\Command;

class SyncMarketPrices extends Command
{
    protected $signature = 'market:sync';

    protected $description = 'Refresh public Korzinka catalogue prices without changing tenant offers';

    public function handle(KorzinkaCatalog $catalog, PublicMarketCatalog $market, PriceTrendHistory $trends): int
    {
        try {
            $count = $catalog->sync();
            foreach (['olma', 'kartoshka', 'pomidor', 'sut', 'guruch', 'yog\'', 'go\'sht', 'tuxum', 'non', 'sement'] as $query) {
                $trends->record($query, $market->search($query, [], 'grocery', 'korzinka')['products']);
            }
            PriceWatchController::dispatchChanges();
            PriceWatchController::dispatchDeals(['olma', 'kartoshka', 'pomidor', 'sut', 'guruch', 'go\'sht', 'tuxum', 'non', 'sement', 'gipsokarton', 'laminat', 'germetik']);
        } catch (\Throwable) {
            $this->error('Public catalogue refresh failed; previous snapshot retained.');

            return self::FAILURE;
        }
        $this->info('Korzinka public catalogue refreshed: '.$count.' products.');

        return self::SUCCESS;
    }
}
