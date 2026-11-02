<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\User;
use App\Services\PriceTrendHistory;
use App\Services\PublicMarketCatalog;
use App\Services\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public price watchers: any verified user can subscribe to a query and get
 * an in-app notification when the cheapest observed price moves.
 */
class PriceWatchController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            abort(403, 'Email tasdiqlanmagan.');
        }
        $data = $request->validate(['query' => ['required', 'string', 'max:80'],
            'change_percent' => ['nullable', 'numeric', 'min:1', 'max:90']]);
        $key = $this->key($data['query']);

        AppNotification::firstOrCreate(['user_id' => $request->user()->id, 'dedupe_key' => 'watch-sub:'.$key],
            ['organization_id' => null, 'event_type' => 'market_watch_subscribed', 'title' => 'Kuzatuvga olindi: '.$data['query'],
                'body' => 'Bu mahsulot narxi o‘zgarganda shu sahifada bildirishnoma olasiz.',
                'metadata' => ['query' => $data['query'], 'change_percent' => $data['change_percent'] ?? null], 'email_status' => 'not_requested']);

        return back()->with('success', '“'.$data['query'].'” narxi kuzatuvga olindi.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:80']]);
        AppNotification::where('user_id', $request->user()->id)->where('dedupe_key', 'watch-sub:'.$this->key($data['query']))->delete();

        return back()->with('success', 'Kuzatuv bekor qilindi.');
    }

    /** Called by market:sync after trends are recorded. */
    public static function dispatchChanges(?User $forUser = null): int
    {
        $watchers = AppNotification::where('event_type', 'market_watch_subscribed')->get()->groupBy('user_id');
        $trends = app(PriceTrendHistory::class);
        $market = app(PublicMarketCatalog::class);
        $created = 0;
        foreach ($watchers as $userId => $subscriptions) {
            $user = User::find($userId);
            if (! $user || ! $user->is_active || ! $user->hasVerifiedEmail()) {
                continue;
            }
            foreach ($subscriptions as $subscription) {
                $query = (string) ($subscription->metadata['query'] ?? '');
                $minChange = (float) ($subscription->metadata['change_percent'] ?? 5);
                $change = $trends->change($query);
                if ($change === null || abs($change) < $minChange) {
                    continue;
                }
                $day = now()->format('Y-m-d');
                $key = 'watch-hit:'.$userId.':'.$day.':'.md5($query);
                $notification = AppNotification::firstOrCreate(['user_id' => $userId, 'dedupe_key' => $key],
                    ['organization_id' => null, 'event_type' => 'market_price_moved',
                        'title' => ($change < 0 ? '▼ Narx pasaydi: ' : '▲ Narx oshdi: ').$query,
                        'body' => 'Kuzatayotgan “'.$query.'” narxi '.$change.'% o‘zgardi ('.collect($market->search($query, [], 'grocery', 'korzinka')['products'])->count().' ta Korzinka taklifi).',
                        'metadata' => ['query' => $query, 'change' => $change], 'url' => '/market?q='.urlencode($query), 'email_status' => 'not_requested']);
                $created += $notification->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $created;
    }

    private function key(string $query): string
    {
        return md5(mb_strtolower(trim($query)));
    }

    /**
     * Bulk deal windows: notify every linked Telegram user + in-app when a tracked
     * product's cheapest price drops well below its own recent average. Once per day per query.
     *
     * @param  array<int, string>  $queries
     */
    public static function dispatchDeals(array $queries, float $percent = 10.0): int
    {
        $trends = app(PriceTrendHistory::class);
        $created = 0;
        foreach ($queries as $query) {
            $deal = $trends->deal($query, $percent);
            if ($deal === null) {
                continue;
            }
            $key = 'deal-hit:'.now()->format('Y-m-d').':'.md5($query);
            $notified = 0;
            foreach (User::whereNotNull('telegram_chat_id')->where('telegram_briefing', true)->where('is_active', true)->where('is_suspended', false)->get() as $user) {
                $notification = AppNotification::firstOrCreate(['user_id' => $user->id, 'dedupe_key' => $key],
                    ['organization_id' => null, 'event_type' => 'market_deal_window',
                        'title' => "🔥 Xarid oynasi: {$query} −{$deal['percent']}%",
                        'body' => "“{$query}” eng arzon narxi o‘rtacha {$deal['previous_average']} so‘mdan {$deal['price']} so‘mga tushdi. Bugun xarid uchun yaxshi kun.",
                        'metadata' => $deal, 'url' => '/market/product/'.str_replace(' ', '-', $query), 'email_status' => 'not_requested']);
                $notified += $notification->wasRecentlyCreated ? 1 : 0;
            }
            $created += $notified > 0 ? 1 : 0;

            $telegram = app(TelegramService::class);

            if ($notified > 0 && $telegram->configured()) {
                foreach (User::whereNotNull('telegram_chat_id')->where('telegram_briefing', true)->where('is_active', true)->where('is_suspended', false)->get() as $user) {
                    $telegram->send($user, "🔥 <b>Xarid oynasi!</b>\n\n<b>{$query}</b> narxi o‘rtacha {$deal['previous_average']} so‘mdan <b>{$deal['price']} so‘m</b>ga tushdi (−{$deal['percent']}%).\nBugun xarid qilish uchun yaxshi kun.");
                }
            }
        }

        return $created;
    }
}
