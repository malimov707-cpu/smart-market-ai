<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AnalyticsPrices;
use App\Services\ExchangeRates;
use App\Services\LatestOffers;
use App\Services\ManageImports;
use App\Services\PriceVerification;
use App\Services\ProductCsv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        Gate::authorize('view', $organization);
        $filters = $this->filters($request);
        $asOf = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->utc() : CarbonImmutable::now()->utc();
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->utc() : $asOf->subDays(30);

        $products = Product::where('organization_id', $tenant)->whereNull('archived_at');
        $suppliers = Supplier::where('organization_id', $tenant);
        $offerBase = Offer::where('organization_id', $tenant)->where('observed_at', '<=', $asOf)->whereRaw(str_replace(['recorded_at', 'created_at'], ['offers.recorded_at', 'offers.created_at'], LatestOffers::recordedExpression()).' <= ?', [$asOf->format('Y-m-d H:i:s.u')]);

        $freshOffers = (clone $offerBase)->where('observed_at', '>=', $asOf->subDays(7))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $asOf))->count();
        $pendingStates = DB::table('price_reviews')->where('organization_id', $tenant)->where('created_at', '<=', $asOf->format('Y-m-d H:i:s.u'))
            ->select('status')->selectRaw('ROW_NUMBER() OVER (PARTITION BY offer_id, scope ORDER BY created_at DESC, id DESC) AS rank');
        $pendingVerification = DB::query()->fromSub($pendingStates, 'states')->where('rank', 1)->where('status', 'pending')->count();

        return Inertia::render('AnalyticsDashboard', [
            'stats' => [
                'products' => (clone $products)->count(),
                'suppliers' => (clone $suppliers)->whereNull('archived_at')->count(),
                'offers' => (clone $offerBase)->count(),
                'fresh_offers' => $freshOffers,
                'pending_verification' => $pendingVerification,
            ],
            'imports' => ManageImports::recentFor($tenant, 5),
            'filters' => $filters, 'asOf' => $asOf->toISOString(),
            'priceMovement' => Product::where('organization_id', $tenant)->whereNull('archived_at')
                ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->whereKey($id))->orderBy('id')->paginate(10)->withQueryString()
                ->through(fn (Product $product): array => app(AnalyticsPrices::class)->product($product, $request->user(), $asOf, $from, isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null)),
            'supplierActivity' => $this->supplierActivity($tenant, $asOf),
        ]);
    }

    private function filters(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date', 'before_or_equal:now'], 'to' => ['nullable', 'date', 'before_or_equal:now', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer'], 'currency' => ['nullable', 'in:UZS,USD,EUR,RUB'],
            'verification' => ['nullable', 'in:unverified,pending,verified,rejected,disputed']]);
        $tenant = $request->attributes->get('organization')->id;
        foreach (['product_id' => Product::class, 'supplier_id' => Supplier::class] as $key => $model) {
            if (isset($data[$key])) {
                $model::where('organization_id', $tenant)->findOrFail($data[$key]);
            }
        }

        return $data;
    }

    private function supplierActivity(int $tenant, CarbonImmutable $asOf): array
    {
        return Offer::where('offers.organization_id', $tenant)
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->where('offers.observed_at', '<=', $asOf)->whereRaw(str_replace(['recorded_at', 'created_at'], ['offers.recorded_at', 'offers.created_at'], LatestOffers::recordedExpression()).' <= ?', [$asOf->format('Y-m-d H:i:s.u')])
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
        Gate::authorize('view', $organization);
        $filters = $this->filters($request);
        $asOf = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->utc() : CarbonImmutable::now()->utc();

        return response()->streamDownload(function () use ($tenant, $filters, $asOf): void {
            $out = fopen('php://output', 'w+b');
            fputcsv($out, ['Mahsulot', 'SKU', 'Yetkazib beruvchi', 'Narx', 'Valyuta', 'Birlik', 'Qadoq', 'Kuzatilgan', 'Manba', 'Tekshiruv (org)', 'Tekshiruv (platforma)']);
            Offer::where('offers.organization_id', $tenant)
                ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', (int) $v))
                ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', (int) $v))
                ->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', strtoupper((string) $v)))
                ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('observed_at', '>=', CarbonImmutable::parse($v)->utc()))
                ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('observed_at', '<=', CarbonImmutable::parse($v)->utc()))
                ->join('products', 'products.id', '=', 'offers.product_id')->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
                ->where('offers.observed_at', '<=', $asOf)->whereRaw(str_replace(['recorded_at', 'created_at'], ['offers.recorded_at', 'offers.created_at'], LatestOffers::recordedExpression()).' <= ?', [$asOf->format('Y-m-d H:i:s.u')])->orderBy('offers.id')
                ->select('offers.*', 'products.name as product_name', 'products.sku as product_sku', 'suppliers.name as supplier_name')
                ->chunkById(500, function ($chunk) use ($out, $tenant, $asOf, $filters): void {
                    $reviews = $this->verification->snapshots($tenant, $chunk->modelKeys(), $asOf);
                    foreach ($chunk as $offer) {
                        $snap = $reviews[$offer->id] ?? [];
                        if (isset($filters['verification']) && ($snap['organization']['status'] ?? 'unverified') !== $filters['verification']) {
                            continue;
                        }
                        fputcsv($out, [
                            $this->safeCsv($offer->product_name), $this->safeCsv($offer->product_sku), $this->safeCsv($offer->supplier_name),
                            (string) $offer->price, $offer->currency, $offer->unit, (string) $offer->package_quantity,
                            $offer->observed_at?->toISOString(), $this->safeCsv((string) ($offer->source_reference ?? '')),
                            ($snap['organization']['status'] ?? $snap['organization_status'] ?? ($snap['organization'] ?? 'unverified')),
                            ($snap['platform']['status'] ?? $snap['platform_status'] ?? ($snap['platform'] ?? 'unverified')),
                        ]);
                    }
                }, 'offers.id', 'id');
            fclose($out);
        }, 'price-report-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prefix separators to neutralize spreadsheet formula injection on export. */
    private function safeCsv(?string $value): string
    {
        $value = (string) $value;

        return ProductCsv::safeCell($value);
    }
}
