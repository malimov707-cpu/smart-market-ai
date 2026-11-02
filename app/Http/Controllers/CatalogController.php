<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\AuditLog;
use App\Models\CatalogFile;
use App\Models\Organization;
use App\Models\Product;
use App\Services\SaveProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'category' => ['nullable', 'string', 'max:100'], 'unit' => ['nullable', Rule::in(['kg', 'l', 'pcs'])],
            'sort' => ['nullable', Rule::in(['name', 'sku', 'created_at'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])]]);
        $query = $this->query($request);
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') {
            $status === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }
        foreach (['category', 'unit'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        $items = $query->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')->orderBy('id')->paginate(20)->withQueryString();

        return Inertia::render('Workspace', ['section' => 'products', 'products' => $items, 'catalog' => ['kind' => 'products', 'items' => $items,
            'filters' => $filters, 'canManage' => $request->user()->can('catalog', $request->attributes->get('organization')),
            'categories' => Product::whereIn('organization_id', Organization::allIds())->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')]]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->query($request)->whereNull('archived_at')->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name', 'sku', 'unit']));
    }

    private function query(Request $request): Builder
    {
        $query = Product::whereIn('organization_id', Organization::allIds());
        if ($q = $request->input('q')) {
            $pattern = '%'.mb_strtolower($q).'%';
            $query->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$pattern])->orWhereRaw('LOWER(sku) LIKE ?', [$pattern])
                ->orWhere('barcode', 'like', '%'.$q.'%')->orWhereRaw('LOWER(CAST(aliases AS TEXT)) LIKE ?', [$pattern]));
        }

        return $query;
    }

    public function show(Request $request, int $product): Response
    {
        $record = Product::whereIn('organization_id', Organization::allIds())->findOrFail($product);
        Gate::authorize('view', $record);

        return Inertia::render('Product', ['product' => $record, 'canManage' => $request->user()->can('update', $record),
            'images' => CatalogFile::where('organization_id', $record->organization_id)->where('product_id', $record->id)->whereNull('retired_at')->get(),
            'variants' => Product::where('organization_id', $record->organization_id)->where('parent_product_id', $record->id)->orderBy('id')->paginate(20),
            'parent' => $record->parent_product_id ? Product::where('organization_id', $record->organization_id)->find($record->parent_product_id, ['id', 'name']) : null]);
    }

    public function store(ProductRequest $request, SaveProduct $service): RedirectResponse
    {
        $service->execute($request->user(), $request->attributes->get('organization'), $request->validated());

        return to_route('products')->with('success', 'Mahsulot qo‘shildi.');
    }

    public function update(ProductRequest $request, int $product, SaveProduct $service): RedirectResponse
    {
        $service->execute($request->user(), $request->attributes->get('organization'), $request->validated(), $product);

        return to_route('products.show', $product)->with('success', 'Mahsulot yangilandi.');
    }

    public function archive(Request $request, int $product): RedirectResponse
    {
        return $this->status($request, $product, true);
    }

    public function restore(Request $request, int $product): RedirectResponse
    {
        return $this->status($request, $product, false);
    }

    private function status(Request $request, int $product, bool $archive): RedirectResponse
    {
        DB::transaction(function () use ($request, $product, $archive): void {
            $record = Product::whereIn('organization_id', Organization::allIds())->lockForUpdate()->findOrFail($product);
            Organization::whereKey($record->organization_id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $record);
            if ($archive && Product::where('parent_product_id', $record->id)->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['product' => 'Avval faol variantlarni arxivlang.']);
            }
            if (! $archive && $record->parent_product_id && Product::whereKey($record->parent_product_id)->whereNotNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['product' => 'Avval asosiy mahsulotni tiklang.']);
            }
            if (($record->archived_at !== null) === $archive) {
                return;
            }
            $record->update(['archived_at' => $archive ? now() : null]);
            AuditLog::create(['organization_id' => $record->organization_id, 'user_id' => $request->user()->id, 'action' => $archive ? 'product.archived' : 'product.restored', 'subject_type' => 'products', 'subject_id' => $record->id]);
        });

        return to_route('products.show', $product)->with('success', $archive ? 'Mahsulot arxivlandi.' : 'Mahsulot tiklandi.');
    }
}
