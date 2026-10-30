<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CatalogFile;
use App\Models\ExchangeRate;
use App\Models\Offer;
use App\Models\PriceReview;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\CompareOffers;
use App\Services\ExchangeRates;
use App\Services\LatestOffers;
use App\Services\PriceHistory;
use App\Services\PriceVerification;
use App\Services\SupplierAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PriceIntelligenceController extends Controller
{
    public function show(Request $request, int $offer, CompareOffers $comparison, ExchangeRates $exchange, PriceVerification $verification): Response
    {
        $record = $this->offer($request, $offer)->load(['product', 'supplier']);
        $asOf = CarbonImmutable::now();
        $reviews = $verification->snapshots($record->organization_id, [$record->id], $asOf);

        return Inertia::render('PriceObservation', ['offer' => $record,
            'calculation' => $comparison->calculate($record, $record->product, '1', $asOf, $exchange->at($record->organization_id, $asOf), $reviews[$record->id] ?? []),
            'history' => PriceReview::where('organization_id', $record->organization_id)->where('offer_id', $record->id)->orderByDesc('id')->paginate(20)->withQueryString(),
            'evidence' => CatalogFile::where('organization_id', $record->organization_id)->where('supplier_id', $record->supplier_id)->whereNull('retired_at')->select(['id', 'name'])->get(),
            'canManage' => $request->user()->can('manage', $request->attributes->get('organization'))]);
    }

    public function review(Request $request, int $offer, PriceVerification $verification): RedirectResponse
    {
        $verification->transition($request->user(), $this->offer($request, $offer), $request->only(['scope', 'status', 'reason', 'evidence_file_id']));

        return to_route('prices.show', $offer)->with('success', 'Narx tekshiruvi tarixga yozildi.');
    }

    public function history(Request $request, int $product, PriceHistory $history, CompareOffers $comparison, ExchangeRates $exchange, PriceVerification $verification, SupplierAvailability $availability): Response
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('view', $organization);
        $product = Product::where('organization_id', $organization->id)->findOrFail($product);
        $data = $request->validate(['as_of' => ['nullable', 'date', 'before_or_equal:now'], 'from' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $asOf = isset($data['as_of']) ? CarbonImmutable::parse($data['as_of'])->utc() : CarbonImmutable::now();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : $asOf->subDays(30);
        abort_if($from->gt($asOf), 422, 'Davr boshi yakundan keyin bo‘lmasin.');
        $supplier = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;
        if ($supplier !== null) {
            Supplier::where('organization_id', $organization->id)->findOrFail($supplier);
        }
        $lowerBound = $from->micro > 0 ? $from->addSecond()->startOfSecond() : $from;
        $quotes = Offer::where('organization_id', $organization->id)->where('product_id', $product->id)
            ->whereBetween('observed_at', [$lowerBound, $asOf])->whereRaw(LatestOffers::recordedExpression().' <= ?', [$asOf->format('Y-m-d H:i:s.u')])
            ->when($supplier, fn ($query) => $query->where('supplier_id', $supplier))->with('supplier')
            ->orderByDesc('observed_at')->orderByRaw(LatestOffers::recordedExpression().' DESC')->orderByDesc('id')->paginate(50)->withQueryString();
        $rates = $exchange->at($organization->id, $asOf);
        $reviews = $verification->snapshots($organization->id, $quotes->getCollection()->modelKeys(), $asOf);
        $supplierStates = $availability->at($quotes->getCollection(), $asOf);
        $quotes->through(fn (Offer $quote): array => $comparison->calculate($quote, $product, '1', $asOf, $rates, $reviews[$quote->id] ?? [], $supplierStates[$quote->supplier_id] ?? []));

        return Inertia::render('PriceHistory', ['product' => $product, 'observations' => $quotes, 'asOf' => $asOf->toISOString(),
            'from' => $from->toIso8601String(), 'supplierId' => $supplier,
            'changes' => $history->changes($product, $asOf, $from, $request->user(), $supplier)]);
    }

    public function rates(Request $request): Response
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('view', $organization);

        return Inertia::render('ExchangeRates', ['rates' => ExchangeRate::where('organization_id', $organization->id)->orderByDesc('id')->paginate(30)->withQueryString(),
            'canManage' => $request->user()->can('administer', $organization)]);
    }

    public function storeRate(Request $request, ExchangeRates $exchange): RedirectResponse
    {
        $exchange->record($request->user(), $request->attributes->get('organization'), $request->only(['currency', 'rate', 'source', 'source_reference', 'effective_at', 'expires_at']));

        return to_route('exchange-rates')->with('success', 'Kurs saqlandi. Mustaqil Owner review’i kerak.');
    }

    public function approveRate(Request $request, int $rate, ExchangeRates $exchange): RedirectResponse
    {
        $exchange->approve($request->user(), $request->attributes->get('organization'), $rate);

        return to_route('exchange-rates')->with('success', 'Kurs tasdiqlandi.');
    }

    private function offer(Request $request, int $id): Offer
    {
        Gate::authorize('view', $request->attributes->get('organization'));

        return Offer::where('organization_id', $request->attributes->get('organization')->id)->findOrFail($id);
    }
}
