<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\MarketRequest;
use App\Models\AuditLog;
use App\Models\CatalogFile;
use App\Models\ImportBatch;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordOffer
{
    public function execute(User $user, Organization $organization, array $data, string $provenance = 'manual', ?ImportBatch $import = null): Offer
    {
        Gate::forUser($user)->authorize('manage', $organization);
        $data = Validator::make($data, MarketRequest::offerRules())->validate();
        $data['source_type'] = $provenance === 'csv' ? 'csv' : ($data['source_type'] ?? 'manual');
        $data['submitted_by'] = $user->id;
        $data['price'] = bcadd((string) $data['price'], '0', 2);
        $data['package_quantity'] = bcadd((string) $data['package_quantity'], '0', 3);
        $data['delivery_fee'] = isset($data['delivery_fee']) ? bcadd((string) $data['delivery_fee'], '0', 2) : null;
        $data['minimum_packages'] = (int) $data['minimum_packages'];
        $data['observed_at'] = CarbonImmutable::parse($data['observed_at'])->utc()->format('Y-m-d H:i:s');
        $data['expires_at'] = empty($data['expires_at']) ? null : CarbonImmutable::parse($data['expires_at'])->utc()->format('Y-m-d H:i:s');
        $data['product_id'] = (int) $data['product_id'];
        $data['supplier_id'] = (int) $data['supplier_id'];
        ksort($data);
        $content = $data;
        unset($content['submitted_by']);
        if ($content['source_type'] === $provenance) {
            unset($content['source_type']);
        }
        foreach (['source_identifier', 'task_reference', 'evidence_file_id', 'correction_of_id', 'correction_reason'] as $optional) {
            if (empty($content[$optional])) {
                unset($content[$optional]);
            }
        }
        $fingerprint = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($organization, $user, $data, $fingerprint, $provenance, $import): Offer {
            $product = Product::whereIn('organization_id', Organization::allIds())->lockForUpdate()->findOrFail($data['product_id']);
            $recordOrganization = Organization::whereKey($product->organization_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('manage', $recordOrganization);
            $supplier = Supplier::whereIn('organization_id', Organization::allIds())->lockForUpdate()->findOrFail($data['supplier_id']);
            if ($supplier->organization_id !== $product->organization_id) {
                $field = $product->organization_id === $organization->id ? 'supplier_id' : 'product_id';
                throw ValidationException::withMessages([$field => 'Mahsulot va yetkazib beruvchi bir xil eski ma’lumotlar doirasida bo‘lishi kerak.']);
            }
            if ($import !== null) {
                abort_unless($import->organization_id === $product->organization_id && $import->type === 'offers' && $import->status === 'processing' && $import->reviewed_at !== null, 409);
            }
            if ($supplier->archived_at || $product->archived_at) {
                throw ValidationException::withMessages(['supplier_id' => 'Arxivlangan mahsulot/yetkazib beruvchi uchun yangi taklif yozilmaydi.']);
            }
            if (! empty($data['evidence_file_id'])) {
                $evidence = CatalogFile::where('organization_id', $product->organization_id)->where('supplier_id', $supplier->id)->whereNull('retired_at')->lockForUpdate()->find($data['evidence_file_id']);
                if (! $evidence) {
                    throw ValidationException::withMessages(['evidence_file_id' => 'Shu supplierga tegishli faol dalilni tanlang.']);
                }
            }
            if (! empty($data['correction_of_id'])) {
                $original = Offer::where('organization_id', $product->organization_id)->where('product_id', $product->id)->where('supplier_id', $supplier->id)->find($data['correction_of_id']);
                if (! $original || CarbonImmutable::parse($data['observed_at'])->lt($original->observed_at)) {
                    throw ValidationException::withMessages(['correction_of_id' => 'Tuzatish shu supplier/product tarixiga tegishli va observation vaqti oldingidan kam bo‘lmasin.']);
                }
            }
            if (in_array($data['source_type'], ['excel', 'api', 'ocr'], true) && (empty($data['source_identifier']) || empty($data['task_reference']))) {
                throw ValidationException::withMessages(['source_identifier' => 'Tashqi/import manba va task identifikatori talab qilinadi; bu live integratsiya emas.']);
            }
            $offer = Offer::firstOrCreate(
                ['organization_id' => $product->organization_id, 'fingerprint' => $fingerprint],
                [...$data, 'import_batch_id' => $import?->id, 'provenance' => $provenance, 'recorded_at' => now()->utc()->format('Y-m-d H:i:s.u')],
            );
            if ($offer->wasRecentlyCreated) {
                AuditLog::create(['organization_id' => $product->organization_id, 'user_id' => $user->id,
                    'action' => 'offer.recorded', 'subject_type' => 'offer', 'subject_id' => $offer->id]);
            }

            return $offer;
        });
    }

    /** Phase 5 hook: fire price alerts after a new offer lands, outside the write transaction. */
    public function afterRecorded(Organization $organization, Offer $offer, ?string $previousPrice = null): void
    {
        $alerts = app(ManageAlerts::class);
        $base = ['scope_id' => $offer->product_id, 'supplier_id' => $offer->supplier_id, 'category' => $offer->product?->category];
        $alerts->dispatch($organization, 'new_offer', 'offer:'.$offer->id, 'Yangi narx taklifi',
            $offer->product?->name.' — '.$offer->price.' '.$offer->currency, [...$base, 'offer_id' => $offer->id], '/prices/'.$offer->id);
        if ($previousPrice !== null && bccomp($previousPrice, (string) $offer->price, 2) !== 0) {
            $change = bcsub((string) $offer->price, $previousPrice, 2);
            $percent = bccomp($previousPrice, '0', 2) > 0 ? bcmul(bcdiv($change, $previousPrice, 6), '100', 4) : null;
            $event = bccomp($change, '0', 2) < 0 ? 'price_decrease' : 'price_increase';
            $alerts->dispatch($organization, $event, 'offer:'.$offer->id, $event === 'price_decrease' ? 'Narx pasaydi' : 'Narx oshdi',
                $offer->product?->name.': '.$previousPrice.' → '.$offer->price.' '.$offer->currency,
                [...$base, 'offer_id' => $offer->id, 'change_value' => $percent ?? $change], '/prices/'.$offer->id);
        }
    }
}
