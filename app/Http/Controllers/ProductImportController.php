<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductImport;
use App\Services\ProductCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('catalog', $request->attributes->get('organization'));

        return Inertia::render('ProductImports', ['imports' => ProductImport::where('organization_id', $request->attributes->get('organization')->id)->latest('id')->paginate(20, ['id', 'created_at', 'expires_at', 'completed_at', 'summary'])]);
    }

    public function upload(Request $request): RedirectResponse
    {
        Gate::authorize('catalog', $request->attributes->get('organization'));
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:2048']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        try {
            $headers = fgetcsv($handle, 16384, ',', '"', '');
            if (! $headers || count($headers) > 30 || count(array_unique($headers)) !== count($headers)) {
                throw ValidationException::withMessages(['file' => '1–30 ta takrorlanmagan ustun bo‘lishi kerak.']);
            }
            if (! mb_check_encoding($headers, 'UTF-8') || array_filter($headers, fn ($header) => str_contains($header, "\0"))) {
                throw ValidationException::withMessages(['file' => 'CSV UTF-8 matn bo‘lishi kerak.']);
            }
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            $rows = [];
            while (($row = fgetcsv($handle, 16384, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($rows) >= 500 || count($row) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => '500 satrgacha; satr ustunlari headerga mos bo‘lsin.']);
                }
                if (! mb_check_encoding($row, 'UTF-8') || array_filter($row, fn ($cell) => str_contains((string) $cell, "\0"))) {
                    throw ValidationException::withMessages(['file' => 'CSV satrida noto‘g‘ri UTF-8 yoki null byte mavjud.']);
                }
                $rows[] = $row;
            }
            if (empty($rows)) {
                throw ValidationException::withMessages(['file' => 'Faylda mahsulotlar yo‘q.']);
            }
            $mapping = [];
            foreach (ProductCsv::COLUMNS as $column) {
                $position = array_search($column, $headers, true);
                $mapping[$column] = $position === false ? null : $position;
            }
            $batch = ProductImport::create(['organization_id' => $request->attributes->get('organization')->id, 'user_id' => $request->user()->id,
                'headers' => $headers, 'rows' => $rows, 'mapping' => $mapping, 'expires_at' => now()->addDays(7)]);
        } finally {
            fclose($handle);
        }

        return to_route('product-imports.show', $batch->id);
    }

    public function show(Request $request, int $import, ProductCsv $service): Response
    {
        $batch = $this->record($request, $import);

        return Inertia::render('ProductImport', ['batch' => $batch, 'columns' => ProductCsv::COLUMNS, 'preview' => $service->preview($batch)]);
    }

    public function mapping(Request $request, int $import): RedirectResponse
    {
        $batch = $this->record($request, $import);
        if ($batch->completed_at || $batch->expires_at->isPast()) {
            abort(409);
        }
        $rules = ['mapping' => ['required', 'array:'.implode(',', ProductCsv::COLUMNS)]];
        foreach (ProductCsv::COLUMNS as $column) {
            $rules['mapping.'.$column] = [in_array($column, ['name', 'sku', 'unit'], true) ? 'required' : 'nullable', 'integer', 'min:0', 'max:'.(count($batch->headers) - 1)];
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($batch, $data): void {
            Organization::whereKey($batch->organization_id)->lockForUpdate()->firstOrFail();
            $locked = ProductImport::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->completed_at || $locked->expires_at->isPast(), 409);
            $locked->update(['mapping' => $data['mapping']]);
        });

        return to_route('product-imports.show', $import)->with('success', 'Ustunlar mosligi saqlandi.');
    }

    public function confirm(Request $request, int $import, ProductCsv $service): RedirectResponse
    {
        $batch = $this->record($request, $import);
        $result = $service->confirm($request->user(), $request->attributes->get('organization'), $batch);

        return to_route('product-imports.show', $import)->with('success', "{$result['created']} yaratildi, {$result['duplicates']} bir xil takror qayd etildi.");
    }

    private function record(Request $request, int $id): ProductImport
    {
        Gate::authorize('catalog', $request->attributes->get('organization'));

        return ProductImport::where('organization_id', $request->attributes->get('organization')->id)->findOrFail($id);
    }

    public function export(Request $request): StreamedResponse
    {
        $tenant = $request->attributes->get('organization')->id;

        return response()->streamDownload(function () use ($tenant): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ProductCsv::COLUMNS, ',', '"', '');
            foreach (Product::where('organization_id', $tenant)->with('parent:id,sku')->orderBy('id')->lazyById(500) as $product) {
                $cells = [];
                foreach (ProductCsv::COLUMNS as $column) {
                    $value = match ($column) {
                        'aliases','variant_attributes' => json_encode($product->$column ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'parent_sku' => $product->parent?->sku, default => $product->$column
                    };
                    $cells[] = ProductCsv::safeCell($value);
                }
                fputcsv($output, $cells, ',', '"', '');
            }
            fclose($output);
        }, 'products.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function errors(Request $request, int $import, ProductCsv $service): StreamedResponse
    {
        $batch = $this->record($request, $import);
        $preview = $service->preview($batch);

        return response()->streamDownload(function () use ($preview): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['row', 'errors'], ',', '"', '');
            foreach ($preview as $row) {
                if ($row['errors']) {
                    fputcsv($output, [$row['row'], ProductCsv::safeCell(implode('; ', $row['errors']))], ',', '"', '');
                }
            }
            fclose($output);
        }, 'product-import-errors.csv', ['Content-Type' => 'text/csv']);
    }
}
