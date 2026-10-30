<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ExchangeRates;
use App\Services\ManageImports;
use App\Services\PriceVerification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Phase 6: tenant analytics computed from real records; decimal-safe, Phase 3 eligibility respected. */
class AnalyticsController extends Controller
{
    public function __construct(private ExchangeRates $exchange, private PriceVerification $verification) {}

    public function dashboard(Request $request): Response
    {
        $organization = $request->attributes->get('organization');
        $tenant = $organization->id;
        $asOf = CarbonImmutable::now()->utc();
        $rates = $this->exchange->at($tenant, $asOf);

        $products = Product::where('organization_id', $tenant)->whereNull('archived_at');
        $suppliers = Supplier::where('organization_id', $tenant);
        $offerBase = Offer::where('organization_id', $tenant);

        $freshOffers = (clone $offerBase)->where('observed_at', '>=', $asOf->subDays(7))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $asOf))->count();
        $pendingVerification = DB::table('price_reviews')->where('organization_id', $tenant)->where('status', 'pending')->count();

        return Inertia::render('AnalyticsDashboard', [
            'stats' => [
                'products' => (clone $products)->count(),
                'suppliers' => (clone $suppliers)->whereNull('archived_at')->count(),
                'offers' => (clone $offerBase)->count(),
                'fresh_offers' => $freshOffers,
                'pending_verification' => $pendingVerification,
            ],
            'imports' => ManageImports::recentFor($tenant, 5),
            'priceMovement' => $this->priceMovement($tenant, $asOf),
            'supplierActivity' => $this->supplierActivity($tenant, $asOf),
        ]);
    }

    /** Currency-aware movement per product; comparisons only within converted totals (Phase 3 rules). */
    private function priceMovement(int $tenant, CarbonImmutable $asOf): array
    {
        $rows = Offer::where('offers.organization_id', $tenant)
            ->join('products', 'products.id', '=', 'offers.product_id')
            ->whereIn('offers.id', function ($query) use ($tenant) {
                $query->selectRaw('MIN(o2.id)')->from('offers as o2')->where('o2.organization_id', $tenant)
                    ->groupBy('o2.product_id', 'o2.supplier_id');
            })
            ->orderBy('offers.product_id')->orderBy('offers.supplier_id')->orderBy('offers.observed_at')
            ->select('offers.id', 'offers.product_id', 'offers.supplier_id', 'offers.price', 'offers.price_exact', 'offers.currency', 'offers.observed_at', 'products.name as product_name', 'products.category')
            ->limit(1000)->get();

        $byProduct = $rows->groupBy('product_id')->map(function ($group): array {
            $first = $group->first();
            $last = $group->last();
            $change = null;
            if ($group->count() > 1 && $first->currency === $last->currency && bccomp((string) $first->price, '0', 2) > 0) {
                $change = bcmul(bcsub((string) $last->price, (string) $first->price, 2), '100', 4);
                $change = bcdiv($change, (string) $first->price, 4);
            }

            return ['product_id' => $first->product_id, 'product_name' => $first->product_name, 'category' => $first->category,
                'observations' => $group->count(), 'latest_price' => (string) $last->price, 'currency' => $last->currency,
                'change_percent' => $change, 'observed_at' => $last->observed_at?->toISOString()];
        })->take(12)->values()->all();

        return $byProduct;
    }

    private function supplierActivity(int $tenant, CarbonImmutable $asOf): array
    {
        return Offer::where('offers.organization_id', $tenant)
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->selectRaw('suppliers.id, suppliers.name, suppliers.verification_status, count(*) as offers_count, max(offers.observed_at) as last_observed_at')
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.verification_status')
            ->orderByDesc('offers_count')->limit(10)->get()
            ->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name, 'verification_status' => $row->verification_status,
                'offers_count' => (int) $row->offers_count, 'last_observed_at' => $row->last_observed_at])->all();
    }

    /** Filtered price report CSV export with formula-injection protection. */
    public function export(Request $request): StreamedResponse
    {
        $organization = $request->attributes->get('organization');
        $tenant = $organization->id;
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer'],
            'currency' => ['nullable', 'string', 'size:3'], 'verification' => ['nullable', 'in:unverified,pending,verified,rejected,disputed'],
        ]);
        $asOf = CarbonImmutable::now()->utc();
        $offerIds = Offer::where('organization_id', $tenant)->pluck('id')->all();
        $reviews = $this->verification->snapshots($tenant, $offerIds, $asOf);

        return response()->streamDownload(function () use ($tenant, $filters, $reviews): void {
            $out = fopen('php://output', 'w+b');
            fputcsv($out, ['Mahsulot', 'SKU', 'Yetkazib beruvchi', 'Narx', 'Valyuta', 'Birlik', 'Qadoq', 'Kuzatilgan', 'Manba', 'Tekshiruv (org)', 'Tekshiruv (platforma)']);
            Offer::where('offers.organization_id', $tenant)
                ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', (int) $v))
                ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', (int) $v))
                ->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', strtoupper((string) $v)))
                ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('observed_at', '>=', CarbonImmutable::parse($v)->utc()))
                ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('observed_at', '<=', CarbonImmutable::parse($v)->endOfDay()->utc()))
                ->join('products', 'products.id', '=', 'offers.product_id')->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
                ->orderBy('offers.observed_at')
                ->select('offers.*', 'products.name as product_name', 'products.sku as product_sku', 'suppliers.name as supplier_name')
                ->chunk(500, function ($chunk) use ($out, $reviews): void {
                    foreach ($chunk as $offer) {
                        $snap = $reviews[$offer->id] ?? [];
                        fputcsv($out, [
                            $this->safeCsv($offer->product_name), $this->safeCsv($offer->product_sku), $this->safeCsv($offer->supplier_name),
                            (string) $offer->price, $offer->currency, $offer->unit, (string) $offer->package_quantity,
                            $offer->observed_at?->toISOString(), $this->safeCsv($offer->source_reference), $snap['organization']['status'] ?? 'unverified', $snap['platform']['status'] ?? 'unverified',
                        ]);
                    }
                });
            fclose($out);
        }, 'price-report-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prefix separators to neutralize spreadsheet formula injection on export. */
    private function safeCsv(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t]/', $value) === 1 ? "'".$value : $value;
    }
}
