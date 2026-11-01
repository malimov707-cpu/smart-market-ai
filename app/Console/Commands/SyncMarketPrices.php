<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\KorzinkaCatalog;
use Illuminate\Console\Command;

class SyncMarketPrices extends Command
{
    protected $signature = 'market:sync';

    protected $description = 'Refresh public Korzinka catalogue prices without changing tenant offers';

    public function handle(KorzinkaCatalog $catalog): int
    {
        try {
            $count = $catalog->sync();
        } catch (\Throwable) {
            $this->error('Public catalogue refresh failed; previous snapshot retained.');

            return self::FAILURE;
        }
        $this->info('Korzinka public catalogue refreshed: '.$count.' products.');

        return self::SUCCESS;
    }
}
