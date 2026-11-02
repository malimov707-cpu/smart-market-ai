<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UploadImportRequest;
use App\Models\AuditLog;
use App\Models\ExtractionAttempt;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Services\DocumentExtractor;
use App\Services\ImportReconciliation;
use App\Services\ManageImports;
use App\Services\ProductCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagedImportController extends Controller
{
    public function index(Request $request, DocumentExtractor $extractor): Response
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('view', $organization);
        $filters = $request->validate(['status' => ['nullable', 'in:uploaded,validating,preview_ready,awaiting_confirmation,queued,processing,completed,completed_with_errors,failed,cancelled'],
            'type' => ['nullable', 'in:products,suppliers,offers']]);

        return Inertia::render('ManagedImports', ['batches' => ImportBatch::whereIn('organization_id', Organization::allIds())
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))->orderByDesc('id')->paginate(20)->withQueryString(),
            'queueStatus' => ['driver' => config('imports.queue_connection'), 'queue' => config('imports.queue'), 'worker' => 'unknown', 'pending_imports' => ImportBatch::whereIn('organization_id', Organization::allIds())->whereIn('status', ['uploaded', 'queued', 'validating', 'processing'])->count(), 'failed_imports' => ImportBatch::whereIn('organization_id', Organization::allIds())->where('status', 'failed')->count(), 'last_completed_at' => ImportBatch::whereIn('organization_id', Organization::allIds())->max('completed_at')],
            'filters' => $filters, 'provider' => $extractor->availability(), 'canCatalog' => $request->user()->can('catalog', $organization),
            'canOffer' => $request->user()->can('manage', $organization)]);
    }

    public function store(UploadImportRequest $request, ManageImports $imports): RedirectResponse
    {
        $batch = $imports->upload($request->user(), $request->attributes->get('organization'), $request->file('file'), $request->safe()->except('file'));

        return to_route('managed-imports.show', $batch->id)->with('success', 'Fayl private saqlandi; preview tayyorlanmoqda. Hali biznes yozuvlari yaratilmagan.');
    }

    public function show(Request $request, int $import, ImportReconciliation $reconciliation, DocumentExtractor $extractor): Response
    {
        $batch = $this->record($request, $import);
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:10000'], 'events_page' => ['nullable', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('ManagedImport', ['batch' => $batch, 'columns' => $reconciliation->columns($batch->type),
            'rows' => $batch->rows()->orderBy('row_number')->paginate(25)->withQueryString(),
            'events' => $batch->events()->orderByDesc('id')->paginate(20, ['*'], 'events_page')->withQueryString(),
            'extractions' => ExtractionAttempt::where('organization_id', $batch->organization_id)->where('import_batch_id', $batch->id)->orderByDesc('id')->limit(20)->get(),
            'provider' => $extractor->availability(), 'canManage' => $request->user()->can('update', $batch)]);
    }

    public function mapping(Request $request, int $import, ManageImports $imports): RedirectResponse
    {
        $imports->configure($request->user(), $this->record($request, $import), $request->only(['revision', 'mapping', 'duplicate_policy', 'error_policy']));

        return to_route('managed-imports.show', $import)->with('success', 'Mapping va reconciliation qayta tekshirildi.');
    }

    public function correct(Request $request, int $import, int $row, ManageImports $imports): RedirectResponse
    {
        $imports->correct($request->user(), $this->record($request, $import), $row, $request->only(['revision', 'cells', 'product_id', 'supplier_id']));

        return to_route('managed-imports.show', $import)->with('success', 'Satr tuzatildi; original extraction va audit saqlandi.');
    }

    public function confirm(Request $request, int $import, ManageImports $imports): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $imports->confirm($request->user(), $this->record($request, $import), (int) $data['revision']);

        return to_route('managed-imports.show', $import)->with('success', 'Ko‘rib chiqilgan import navbatga qo‘yildi.');
    }

    public function cancel(Request $request, int $import, ManageImports $imports): RedirectResponse
    {
        $imports->cancel($request->user(), $this->record($request, $import));

        return to_route('managed-imports.show', $import)->with('success', 'Import bekor qilindi. Tasdiqlangan biznes tarixlari o‘zgarmaydi.');
    }

    public function retry(Request $request, int $import, ManageImports $imports): RedirectResponse
    {
        $imports->retry($request->user(), $this->record($request, $import));

        return to_route('managed-imports.show', $import)->with('success', 'Retry navbatga qo‘yildi.');
    }

    public function sheet(Request $request, int $import, ManageImports $imports): RedirectResponse
    {
        $data = $request->validate(['sheet' => ['required', 'integer', 'min:0', 'max:9'], 'header_row' => ['required', 'integer', 'min:1', 'max:20']]);
        $imports->selectSheet($request->user(), $this->record($request, $import), (int) $data['sheet'], (int) $data['header_row']);

        return to_route('managed-imports.show', $import)->with('success', 'Tanlangan sheet qayta tayyorlanmoqda.');
    }

    public function manual(Request $request, int $import, ManageImports $imports): RedirectResponse
    {
        $imports->manualRow($request->user(), $this->record($request, $import));

        return to_route('managed-imports.show', $import)->with('success', 'Qo‘lda ajratish satri qo‘shildi; qiymatlarni tekshirib to‘ldiring.');
    }

    public function file(Request $request, int $import): BinaryFileResponse
    {
        $batch = $this->record($request, $import);
        abort_if($batch->purged_at || $batch->scan_status === 'infected' || (config('imports.scan_required') && $batch->scan_status !== 'clean') || ! Storage::disk('catalog')->exists($batch->file_path), 404);
        AuditLog::create(['organization_id' => $batch->organization_id, 'user_id' => $request->user()->id, 'action' => 'import.source_accessed', 'subject_type' => 'import_batch', 'subject_id' => $batch->id]);
        $response = response()->file(Storage::disk('catalog')->path($batch->file_path), ['Content-Type' => $batch->file_mime,
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
        $response->setContentDisposition('attachment', 'import-'.$batch->id.'.'.$batch->format);

        return $response;
    }

    public function errors(Request $request, int $import): StreamedResponse
    {
        $batch = $this->record($request, $import);

        return response()->streamDownload(function () use ($batch): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['row', 'status', 'errors'], ',', '"', '');
            foreach ($batch->rows()->whereIn('status', ['invalid', 'failed'])->orderBy('id')->lazyById(100) as $row) {
                fputcsv($stream, [$row->row_number, $row->status, ProductCsv::safeCell(json_encode($row->errors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))], ',', '"', '');
            }
            fclose($stream);
        }, 'import-'.$batch->id.'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function record(Request $request, int $id): ImportBatch
    {
        $batch = ImportBatch::whereIn('organization_id', Organization::allIds())->findOrFail($id);
        Gate::authorize('view', $batch);

        return $batch;
    }
}
