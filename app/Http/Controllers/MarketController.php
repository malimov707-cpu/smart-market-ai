<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ImportRequest;
use App\Http\Requests\MarketRequest;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\CompareOffers;
use App\Services\ImportOffers;
use App\Services\ManageImports;
use App\Services\OpenRouterAssistant;
use App\Services\RecordOffer;
use App\Services\WorkspaceInsights;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketController extends Controller
{
    public function index(Request $request, WorkspaceInsights $insights): Response
    {
        $organization = $request->attributes->get('organization');
        $tenant = $organization->id;
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000000']]);
        $section = $request->route()->getName();

        return Inertia::render('Workspace', [
            'section' => $section,
            'assistant' => $section === 'ai' ? app(OpenRouterAssistant::class)->availability() : null,
            'conversation' => $section === 'ai' ? $request->session()->get('ai_chat.'.$tenant, []) : [],
            'insights' => $section === 'ai' ? $insights->summarize($organization, $request->user(), isset($filters['product_id']) ? (int) $filters['product_id'] : null, (string) ($filters['quantity'] ?? '1')) : null,
            'canManage' => $request->user()->can(in_array($section, ['products', 'suppliers']) ? 'catalog' : 'manage', $organization),
            'filters' => $filters,
            'stats' => [
                'products' => Product::where('organization_id', $tenant)->whereNull('archived_at')->count(),
                'suppliers' => Supplier::where('organization_id', $tenant)->count(),
                'offers' => Offer::where('organization_id', $tenant)->count(),
                'fresh' => Offer::where('organization_id', $tenant)->where('observed_at', '>=', now()->subDays(7))->where('observed_at', '<=', now())
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            ],
            'products' => $section === 'products' ? Product::where('organization_id', $tenant)->whereNull('archived_at')
                ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")))
                ->orderBy('name')->paginate(20)->withQueryString() : null,
            'suppliers' => $section === 'suppliers' ? Supplier::where('organization_id', $tenant)
                ->when($filters['q'] ?? null, fn ($query, $q) => $query->where('name', 'like', "%{$q}%"))
                ->orderBy('name')->paginate(20)->withQueryString() : null,
            'offers' => in_array($section, ['dashboard', 'offers']) ? Offer::where('organization_id', $tenant)->with(['product', 'supplier'])
                ->orderByDesc('observed_at')->paginate(20)->withQueryString() : null,
            'productOptions' => [], 'supplierOptions' => [],
            'activity' => $section === 'dashboard' ? AuditLog::where('organization_id', $tenant)->latest('id')->limit(6)->get() : [],
        ]);
    }

    public function storeProduct(MarketRequest $request): RedirectResponse
    {
        $this->record($request, Product::class, 'product.created');

        return back()->with('success', 'Mahsulot qo‘shildi.');
    }

    public function storeSupplier(MarketRequest $request): RedirectResponse
    {
        $this->record($request, Supplier::class, 'supplier.created');

        return back()->with('success', 'Yetkazib beruvchi qo‘shildi.');
    }

    private function record(MarketRequest $request, string $model, string $action): void
    {
        DB::transaction(function () use ($request, $model, $action): void {
            $tenant = $request->attributes->get('organization')->id;
            $record = $model::create([...$request->validated(), 'organization_id' => $tenant]);
            AuditLog::create(['organization_id' => $tenant, 'user_id' => $request->user()->id,
                'action' => $action, 'subject_type' => $record->getTable(), 'subject_id' => $record->id]);
        });
    }

    public function archiveProduct(Request $request, int $product): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $record = Product::where('organization_id', $organization->id)->findOrFail($product);
        Gate::authorize('catalog', $organization);
        DB::transaction(function () use ($record, $request, $organization): void {
            $record->update(['archived_at' => now()]);
            AuditLog::create(['organization_id' => $organization->id, 'user_id' => $request->user()->id,
                'action' => 'product.archived', 'subject_type' => 'products', 'subject_id' => $record->id]);
        });

        return back()->with('success', 'Mahsulot arxivlandi.');
    }

    public function storeOffer(MarketRequest $request, RecordOffer $record): RedirectResponse
    {
        $offer = $record->execute($request->user(), $request->attributes->get('organization'), $request->validated());

        return to_route('offers')->with('success', $offer->wasRecentlyCreated ? 'Taklif saqlandi.' : 'Bu taklif allaqachon mavjud.');
    }

    public function compare(Request $request, CompareOffers $engine): Response
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000000'], 'as_of' => ['nullable', 'date', 'before_or_equal:now']]);
        $product = Product::where('organization_id', $request->attributes->get('organization')->id)->whereNull('archived_at')->findOrFail($data['product_id']);
        $asOf = isset($data['as_of']) ? CarbonImmutable::parse($data['as_of'])->utc() : CarbonImmutable::now();

        return Inertia::render('Comparison', ['product' => $product, 'quantity' => (string) $data['quantity'],
            'results' => $engine->compare($product, (string) $data['quantity'], $asOf, $request->user()), 'asOf' => $asOf->toISOString()]);
    }

    public function import(ImportRequest $request, ManageImports $import): RedirectResponse
    {
        $batch = $import->upload($request->user(), $request->attributes->get('organization'), $request->file('file'), ['type' => 'offers', 'timezone' => 'UTC']);

        return to_route('managed-imports.show', $batch->id)->with('success', 'CSV private saqlandi. Previewdan keyin alohida tasdiq kerak.');
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ImportOffers::COLUMNS, ',', '"', '');
            fclose($handle);
        }, 'offer-template.csv', ['Content-Type' => 'text/csv']);
    }
}
