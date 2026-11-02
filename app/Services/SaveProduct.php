<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\ProductRequest;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveProduct
{
    public function execute(User $actor, Organization $organization, array $data, ?int $id = null): Product
    {
        return DB::transaction(function () use ($actor, $organization, $data, $id): Product {
            $product = $id ? Product::whereIn('organization_id', Organization::allIds())->lockForUpdate()->findOrFail($id) : null;
            $organizationId = $product?->organization_id ?? $organization->id;
            $recordOrganization = Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('catalog', $recordOrganization);
            if ($product) {
                $data = [...$product->only(['package_quantity', 'package_unit', 'parent_product_id']), 'variant_attributes' => $product->variant_attributes ?? [], ...$data];
            }
            $data = Validator::make($data, ProductRequest::rulesFor($organizationId, $id))->validate();
            if ($product?->archived_at) {
                throw ValidationException::withMessages(['product' => 'Avval mahsulotni arxivdan tiklang.']);
            }
            if ($product && $data['unit'] !== $product->unit && (Offer::where('product_id', $id)->exists() || Product::where('parent_product_id', $id)->exists())) {
                throw ValidationException::withMessages(['unit' => 'Tarixi yoki variantlari mavjud mahsulot birligini o‘zgartirib bo‘lmaydi.']);
            }
            if ($product && Offer::where('product_id', $product->id)->exists() && ((int) ($data['parent_product_id'] ?? 0) !== (int) $product->parent_product_id || ($data['variant_attributes'] ?? []) != ($product->variant_attributes ?? []))) {
                throw ValidationException::withMessages(['variant_attributes' => 'Narx tarixi mavjud variantning identifikatsiyasini o‘zgartirib bo‘lmaydi. Yangi SKU yarating.']);
            }
            if (! empty($data['parent_product_id'])) {
                $parent = Product::where('organization_id', $organizationId)->findOrFail($data['parent_product_id']);
                if ($parent->id === $id || $parent->unit !== $data['unit'] || empty($data['variant_attributes']) || ($id && Product::where('parent_product_id', $id)->exists())) {
                    throw ValidationException::withMessages(['parent_product_id' => 'Variant birligi mos, attributlari mavjud va parent bog‘lanishi siklsiz bo‘lishi kerak.']);
                }
            } elseif (! empty($data['variant_attributes'])) {
                throw ValidationException::withMessages(['variant_attributes' => 'Variant attributlari uchun asosiy mahsulotni tanlang.']);
            }
            if (! empty($data['package_unit'])) {
                $base = ['g' => 'kg', 'kg' => 'kg', 'ml' => 'l', 'l' => 'l', 'pcs' => 'pcs'][$data['package_unit']];
                if ($base !== $data['unit']) {
                    throw ValidationException::withMessages(['package_unit' => 'Qadoq birligi asosiy birlikka mos emas.']);
                }
            }
            if ($product) {
                $product->update($data);
            } else {
                $product = Product::create([...$data, 'organization_id' => $organization->id]);
            }
            AuditLog::create(['organization_id' => $organizationId, 'user_id' => $actor->id, 'action' => $id ? 'product.updated' : 'product.created', 'subject_type' => 'products', 'subject_id' => $product->id]);

            return $product;
        });
    }
}
