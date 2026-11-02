<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the daily Telegram briefing: market movers, savings insight and the
 * user's watched queries. Honest about data coverage — no invented numbers.
 */
class MarketBriefing
{
    private const TRACKED = ['olma', 'kartoshka', 'pomidor', 'sut', 'guruch', 'tuxum', 'sement', 'gipsokarton'];

    public function __construct(private PublicMarketCatalog $market, private PriceTrendHistory $trends) {}

    public function forUser(User $user): string
    {
        $lines = ['📊 <b>Smart Market AI · Toshkent briefing</b>', $this->stamp(), ''];
        $moved = 0;
        foreach (self::TRACKED as $query) {
            $change = $this->trends->change($query);
            $series = $this->trends->series($query, 1);
            if ($change === null || $series === []) {
                continue;
            }
            $price = $series[0]['price'];
            $arrow = $change < 0 ? '▼' : ($change > 0 ? '▲' : '—');
            $color = $change < 0 ? '🟢' : ($change > 0 ? '🟠' : '⚪');
            $lines[] = sprintf('%s <b>%s</b>: %s so‘m · %s %s%%', $color, mb_ucfirst($query), number_format($price, 0, '.', ' '), $arrow, abs($change));
            if (abs($change) >= 5) {
                $moved++;
            }
        }
        if ($moved === 0) {
            $lines[] = 'Bugun keskin harakat yo‘q — barqaror bozor.';
        }
        $lines[] = '';
        $watches = Cache::get('briefing:watches:'.$user->id);
        $lines[] = '🔗 Kuzatgan mahsulotlaringiz narxi 5%+ o‘zgarsa alohida xabar keladi.';
        $lines[] = 'Bot niksi: narxlar manba kataloglaridan olinadi, AI narx to‘qimaydi.';

        return implode("\n", $lines);
    }

    private function stamp(): string
    {
        return now('Asia/Tashkent')->locale('uz')->translatedFormat('l, d F · H:i');
    }
}
