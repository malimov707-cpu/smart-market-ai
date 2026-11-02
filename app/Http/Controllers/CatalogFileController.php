<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFileRequest;
use App\Models\AuditLog;
use App\Models\CatalogFile;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceReview;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogFileController extends Controller
{
    public function store(CatalogFileRequest $request): RedirectResponse
    {
        $isProduct = (bool) $request->route('product');
        $model = $isProduct ? Product::class : Supplier::class;
        $field = $isProduct ? 'product_id' : 'supplier_id';
        $id = (int) ($request->route('product') ?? $request->route('supplier'));
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $contents = file_get_contents($file->getRealPath());
        $extension = 'pdf';
        if ($mime !== 'application/pdf') {
            $size = @getimagesizefromstring($contents);
            if (! $size || $size[0] > 4096 || $size[1] > 4096) {
                throw ValidationException::withMessages(['file' => 'Rasm o‘lchami 4096×4096 dan oshmasin.']);
            }
            $image = @imagecreatefromstring($contents);
            if (! $image) {
                throw ValidationException::withMessages(['file' => 'Rasmni o‘qib bo‘lmadi.']);
            }
            ob_start();
            imagepng($image);
            $contents = ob_get_clean();
            imagedestroy($image);
            $mime = 'image/png';
            $extension = 'png';
        }
        $path = Str::uuid().'.'.$extension;
        try {
            DB::transaction(function () use ($request, $model, $field, $id, $file, $contents, $path, $mime): void {
                $record = $model::whereIn('organization_id', Organization::allIds())->lockForUpdate()->findOrFail($id);
                Gate::authorize('update', $record);
                if ($record->archived_at) {
                    throw ValidationException::withMessages(['file' => 'Arxivdagi yozuvni avval tiklang.']);
                }
                $active = CatalogFile::where('organization_id', $record->organization_id)->where($field, $id)->whereNull('retired_at');
                if ($replace = $request->validated('replace_id')) {
                    $old = (clone $active)->lockForUpdate()->findOrFail($replace);
                    $this->ensureNotEvidence($old);
                    $old->update(['retired_at' => now()]);
                } elseif ($active->count() >= ($field === 'product_id' ? 10 : 50)) {
                    throw ValidationException::withMessages(['file' => 'Faol fayllar limiti tugagan.']);
                }
                Storage::disk('catalog')->put($path, $contents);
                $entry = CatalogFile::create(['organization_id' => $record->organization_id, $field => $id, 'uploaded_by' => $request->user()->id,
                    'name' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'path' => $path, 'mime' => $mime, 'size' => strlen($contents)]);
                $this->audit($request, $entry, 'catalog_file.uploaded');
            });
        } catch (\Throwable $error) {
            if (Storage::disk('catalog')->exists($path)) {
                Storage::disk('catalog')->delete($path);
            }
            throw $error;
        }

        return to_route($isProduct ? 'products.show' : 'suppliers.show', $id)->with('success', 'Fayl xavfsiz saqlandi.');
    }

    public function download(Request $request, int $file): BinaryFileResponse
    {
        $record = $this->record($request, $file);
        $owner = $record->product_id ? Product::findOrFail($record->product_id) : Supplier::findOrFail($record->supplier_id);
        Gate::authorize('view', $owner);
        $this->audit($request, $record, 'catalog_file.accessed');
        abort_unless(Storage::disk('catalog')->exists($record->path), 404);
        $response = response()->file(Storage::disk('catalog')->path($record->path), ['Content-Type' => $record->mime, 'X-Content-Type-Options' => 'nosniff']);
        $response->setContentDisposition($record->product_id ? 'inline' : 'attachment', 'catalog-file-'.$record->id.match ($record->mime) {
            'application/pdf' => '.pdf', 'text/csv', 'text/plain', 'application/csv' => '.csv', 'application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx', 'image/jpeg' => '.jpg', default => '.png'
        });

        return $response;
    }

    public function retire(Request $request, int $file): RedirectResponse
    {
        $ownerReference = $this->record($request, $file);
        DB::transaction(function () use ($request, $file): void {
            $record = $this->record($request, $file);
            $model = $record->product_id ? Product::class : Supplier::class;
            $owner = $model::whereKey($record->product_id ?? $record->supplier_id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $owner);
            if ($owner->archived_at) {
                throw ValidationException::withMessages(['file' => 'Avval yozuvni arxivdan tiklang.']);
            }
            $record = CatalogFile::where('organization_id', $record->organization_id)->whereNull('retired_at')->lockForUpdate()->findOrFail($record->id);
            $this->ensureNotEvidence($record);
            $record->update(['retired_at' => now()]);
            $this->audit($request, $record, 'catalog_file.retired');
        });

        return to_route($ownerReference->product_id ? 'products.show' : 'suppliers.show', $ownerReference->product_id ?? $ownerReference->supplier_id)->with('success', 'Faylga kirish bekor qilindi; audit uchun original saqlanadi.');
    }

    private function record(Request $request, int $id): CatalogFile
    {
        return CatalogFile::whereIn('organization_id', Organization::allIds())->whereNull('retired_at')->findOrFail($id);
    }

    private function ensureNotEvidence(CatalogFile $file): void
    {
        if (PriceReview::where('organization_id', $file->organization_id)->where('evidence_file_id', $file->id)->exists() || Offer::where('organization_id', $file->organization_id)->where('evidence_file_id', $file->id)->exists()) {
            throw ValidationException::withMessages(['file' => 'Narx tarixi dalilini olib tashlab bo‘lmaydi.']);
        }
        if ($file->supplier_id && SupplierVerification::where('supplier_id', $file->supplier_id)->whereJsonContains('evidence_ids', $file->id)->exists()) {
            throw ValidationException::withMessages(['file' => 'Tekshiruv dalili sifatida qayd etilgan faylni olib tashlab bo‘lmaydi.']);
        }
    }

    private function audit(Request $request, CatalogFile $file, string $action): void
    {
        AuditLog::create(['organization_id' => $file->organization_id, 'user_id' => $request->user()->id, 'action' => $action, 'subject_type' => 'catalog_file', 'subject_id' => $file->id]);
    }
}
