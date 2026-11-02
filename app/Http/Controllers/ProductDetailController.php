<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Services\PriceTrendHistory;
use App\Services\PublicMarketCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public, SEO-friendly product page: /market/product/<slug>
 * Aggregates every source for one query with history, watch state and savings.
 */
class ProductDetailController extends Controller
{
    private const POPULAR = [
        'olma', 'kartoshka', 'pomidor', 'piyoz', 'sut', 'guruch', 'tuxum', 'non',
        'go‘sht', 'banan', 'sabzi', 'yog', 'sement', 'gipsokarton', 'laminat', 'germetik',
    ];

    public function __construct(private PublicMarketCatalog $market, private PriceTrendHistory $trends) {}

    public function index(): Response
    {
        return Inertia::render('ProductIndex', [
            'products' => collect(self::POPULAR)
                ->map(fn (string $query): array => $this->shape($query))->values()->all(),
        ]);
    }

    public function show(Request $request, string $slug): Response|RedirectResponse
    {
        $query = str_replace('-', ' ', $slug);
        if (preg_match("/^[a-z0-9'‘’\s]{2,60}$/u", $query) !== 1) {
            abort(404);
        }
        $market = $this->market->search($query);
        if ($market['total'] === 0) {
            return redirect()->route('market', ['q' => $query]);
        }
        $series = $this->trends->series($query);
        $change = $this->trends->change($query);
        $deal = $this->trends->deal($query);
        $insight = $this->market->insight($query, $market['products']);
        $watching = AppNotification::where('user_id', (int) $request->user()?->id)
            ->where('event_type', 'market_watch_subscribed')->where('metadata->query', $query)->exists();

        return Inertia::render('ProductDetail', [
            'query' => $query,
            'slug' => $slug,
            'market' => $market,
            'trend' => ['series' => $series, 'change' => $change],
            'insight' => $insight,
            'deal' => $deal,
            'watch' => ['watching' => $watching, 'threshold' => '5'],
            'related' => collect(self::POPULAR)->filter(fn (string $item): bool => $item !== $query)->take(8)->values()->all(),
        ]);
    }

    /** @return array{slug: string, name: string, change: float|null, price: float|null} */
    private function shape(string $query): array
    {
        $series = $this->trends->series($query, 1);

        return ['slug' => str_replace(' ', '-', $query), 'name' => $query,
            'change' => $this->trends->change($query), 'price' => $series[0]['price'] ?? null];
    }
}
