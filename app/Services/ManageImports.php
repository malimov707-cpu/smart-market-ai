<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CommitImport;
use App\Jobs\PrepareImport;
use App\Models\AuditLog;
use App\Models\CatalogFile;
use App\Models\ExtractionAttempt;
use App\Models\ImportBatch;
use App\Models\ImportEvent;
use App\Models\ImportRow;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageImports
{
    public function __construct(private SpreadsheetReader $reader, private ImportReconciliation $reconciliation, private DocumentExtractor $extractor, private ExtractionSchema $schema, private FileScanner $scanner, private SaveProduct $products, private RecordOffer $offers) {}

    public function upload(User $actor, Organization $organization, UploadedFile $file, array $input): ImportBatch
    {
        $input = Validator::make($input, ['type' => ['required', Rule::in(['products', 'suppliers', 'offers'])],
            'timezone' => ['required', Rule::in(['UTC', 'Asia/Tashkent'])], 'header_row' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'idempotency_key' => ['nullable', 'regex:/^[a-f0-9]{64}$/']])->validate();
        Gate::forUser($actor)->authorize($input['type'] === 'offers' ? 'manage' : 'catalog', $organization);
        $extension = strtolower($file->getClientOriginalExtension());
        $format = $extension === 'jpeg' ? 'jpg' : $extension;
        $mime = $file->getMimeType();
        $allowed = ['csv' => ['text/plain', 'text/csv', 'application/csv'], 'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg']];
        if ($file->getSize() > config('imports.max_bytes') || ! in_array($mime, $allowed[$format] ?? [], true)) {
            throw ValidationException::withMessages(['file' => 'CSV/XLSX/PDF/PNG/JPEG, mos MIME va 5 MB gacha fayl kerak.']);
        }
        if (in_array($format, ['png', 'jpg'], true)) {
            $size = @getimagesize($file->getRealPath());
            if (! $size || $size[0] > 4096 || $size[1] > 4096 || $size[0] * $size[1] > 12000000) {
                throw ValidationException::withMessages(['file' => 'Rasm 4096×4096 va 12 MP limitida bo‘lsin.']);
            }
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $key = $input['idempotency_key'] ?? hash('sha256', $actor->id.'|'.$input['type'].'|'.$hash);
        if ($existing = ImportBatch::where('organization_id', $organization->id)->where('idempotency_key', $key)->first()) {
            if ($existing->file_hash !== $hash || $existing->type !== $input['type']) {
                abort(409, 'Idempotency key boshqa faylga tegishli.');
            }

            return $existing;
        }
        $path = $organization->id.'/imports/'.Str::uuid().'.'.$format;
        Storage::disk('catalog')->put($path, file_get_contents($file->getRealPath()));
        try {
            $batch = DB::transaction(function () use ($actor, $organization, $input, $file, $format, $mime, $hash, $key, $path): ImportBatch {
                Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
                $existing = ImportBatch::where('organization_id', $organization->id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    return $existing;
                }
                $batch = ImportBatch::create(['organization_id' => $organization->id, 'user_id' => $actor->id, 'type' => $input['type'], 'format' => $format,
                    'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'file_path' => $path, 'file_mime' => $mime, 'file_size' => $file->getSize(),
                    'file_hash' => $hash, 'idempotency_key' => $key, 'timezone' => $input['timezone'], 'header_row' => $input['header_row'] ?? 1,
                    'expires_at' => now()->addDays((int) config('imports.unconfirmed_retention_days'))]);
                $batch->forceFill(['duplicate_policy' => $batch->type === 'offers' ? 'immutable' : 'skip'])->save();
                $this->event($batch, 'uploaded', $actor->id);

                return $batch;
            });
        } catch (\Throwable $error) {
            Storage::disk('catalog')->delete($path);
            throw $error;
        }
        if ($batch->file_path !== $path) {
            Storage::disk('catalog')->delete($path);
        }
        if ($batch->wasRecentlyCreated) {
            $this->dispatch($batch);
        }

        return $batch;
    }

    public function dispatch(ImportBatch $batch): void
    {
        $job = $batch->stage === 'commit' ? new CommitImport($batch->id, $batch->generation) : new PrepareImport($batch->id, $batch->generation);
        dispatch($job->onConnection(config('imports.queue_connection'))->onQueue(config('imports.queue'))->afterCommit());
    }

    /** A database lease and token fence protect duplicate deliveries, including expired worker reservations. */
    public function claim(int $id, string $stage, int $generation = 0): ?string
    {
        return DB::transaction(function () use ($id, $stage, $generation): ?string {
            $batch = ImportBatch::lockForUpdate()->findOrFail($id);
            if ($batch->generation !== $generation || $batch->stage !== $stage || ! in_array($batch->status, ['uploaded', 'queued', 'validating', 'processing'], true) || $batch->purged_at) {
                return null;
            }
            if ($batch->lease_expires_at?->isFuture()) {
                return null;
            }
            $actor = User::find($stage === 'commit' ? $batch->reviewed_by : $batch->user_id);
            if (! $actor || ! $actor->can('update', $batch)) {
                $this->fail($batch, 'permission_changed');

                return null;
            }
            if ($batch->expires_at->isPast() && ! $batch->reviewed_at) {
                $this->fail($batch, 'preview_expired');

                return null;
            }
            $token = (string) Str::uuid();
            $batch->forceFill(['lease_token' => $token, 'lease_expires_at' => now()->addSeconds(90), 'started_at' => now(), 'attempts' => $batch->attempts + 1, 'error_code' => null])->save();
            $this->transition($batch, $stage === 'commit' ? 'processing' : 'validating', 'worker_claimed');

            return $token;
        });
    }

    public function prepare(int $id, string $token): void
    {
        $batch = ImportBatch::findOrFail($id);
        if ($batch->lease_token !== $token || $batch->status !== 'validating') {
            return;
        }
        $path = Storage::disk('catalog')->path($batch->file_path);
        if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_hash) {
            throw new ImportFailure('source_missing_or_changed');
        }
        $scan = $this->scanner->scan($path);
        if (! in_array($scan, ['clean', 'unavailable', 'infected'], true)) {
            throw new ImportFailure('scan_invalid_result');
        }
        $batch->forceFill(['scan_status' => $scan])->save();
        if ($scan === 'infected' || ($scan === 'unavailable' && config('imports.scan_required'))) {
            throw new ImportFailure($scan === 'infected' ? 'malware_detected' : 'scanner_unavailable');
        }
        if (in_array($batch->format, ['csv', 'xlsx'], true)) {
            $parsed = $this->reader->read($path, $batch->format, $batch->sheet, $batch->header_row, $batch->timezone);
            $extractions = [];
        } else {
            [$parsed, $extractions] = $this->extract($batch, $path);
        }
        DB::transaction(function () use ($id, $token, $parsed, $extractions): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($id);
            if ($batch->lease_token !== $token || $batch->status !== 'validating') {
                return;
            }
            $batch->rows()->delete();
            $mapping = [];
            foreach ($this->reconciliation->columns($batch->type) as $field) {
                $position = array_search($field, $parsed['headers'], true);
                $mapping[$field] = $position === false ? null : $position;
            }
            foreach ($parsed['rows'] as $number => $raw) {
                ImportRow::create(['organization_id' => $batch->organization_id, 'import_batch_id' => $batch->id, 'row_number' => $number,
                    'raw' => $raw, 'extraction' => $extractions[$number] ?? null]);
            }
            $batch->forceFill(['sheets' => $parsed['sheets'], 'headers' => $parsed['headers'], 'header_row' => $parsed['header'], 'mapping' => $mapping,
                'row_count' => count($parsed['rows']), 'processed_count' => count($parsed['rows']), 'revision' => $batch->revision + 1])->save();
            $this->transition($batch, 'preview_ready', 'parsed');
            $this->validateRows($batch);
            $batch->forceFill(['lease_token' => null, 'lease_expires_at' => null])->save();
            $this->transition($batch, 'awaiting_confirmation', 'preview_validated');
        });
    }

    private function extract(ImportBatch $batch, string $path): array
    {
        $availability = $this->extractor->availability();
        $requestKey = hash('sha256', $batch->id.'|'.$batch->file_hash.'|'.($availability['provider'] ?? 'unavailable').'|'.($availability['model'] ?? ''));
        $attempt = ExtractionAttempt::firstOrCreate(['request_key' => $requestKey], ['organization_id' => $batch->organization_id, 'import_batch_id' => $batch->id,
            'provider' => $availability['provider'], 'model' => $availability['model'], 'status' => 'pending']);
        if (! $availability['available']) {
            $attempt->forceFill(['status' => 'unavailable', 'error_code' => 'provider_unavailable'])->save();
            throw new ImportFailure('provider_unavailable');
        }
        $attempt->forceFill(['status' => 'processing', 'transport_attempts' => (int) $attempt->transport_attempts + 1])->save();
        try {
            $output = $this->schema->validate($this->extractor->extract(file_get_contents($path), $batch->file_mime, $requestKey), $this->reconciliation->columns($batch->type));
            $usage = $output['usage'] ?? [];
            $attempt->forceFill(['status' => 'completed', 'error_code' => null, 'input_tokens' => $usage['input_tokens'] ?? null, 'output_tokens' => $usage['output_tokens'] ?? null,
                'cost' => $usage['cost'] ?? null, 'cost_currency' => $usage['currency'] ?? null])->save();
        } catch (ImportFailure $error) {
            $attempt->forceFill(['status' => 'failed', 'error_code' => $error->errorCode])->save();
            throw $error;
        }
        $headers = $this->reconciliation->columns($batch->type);
        $rows = [];
        $extractions = [];
        foreach ($output['rows'] as $index => $row) {
            $rows[$index + 2] = array_map(fn (string $field): ?string => $row['fields'][$field]['value'] ?? null, $headers);
            $extractions[$index + 2] = $row;
        }

        return [['headers' => $headers, 'sheets' => ['Document'], 'header' => 1, 'rows' => $rows], $extractions];
    }

    public function validateRows(ImportBatch $batch): void
    {
        $this->reconciliation->reset();
        $errors = 0;
        foreach ($batch->rows()->orderBy('row_number')->lazyById(100) as $row) {
            $result = $this->reconciliation->row($batch, $row);
            $row->forceFill($result)->save();
            if ($result['status'] === 'invalid') {
                $errors++;
            }
        }
        $batch->forceFill(['error_count' => $errors])->save();
    }

    public function configure(User $actor, ImportBatch $batch, array $input): void
    {
        Gate::forUser($actor)->authorize('update', $batch);
        $columns = $this->reconciliation->columns($batch->type);
        $data = Validator::make($input, ['revision' => ['required', 'integer'], 'mapping' => ['required', 'array:'.implode(',', $columns)],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:'.max(0, count($batch->headers ?? []) - 1)],
            'duplicate_policy' => ['required', Rule::in($batch->type === 'offers' ? ['skip', 'immutable'] : ['skip', 'update'])],
            'error_policy' => ['required', Rule::in(['atomic', 'valid_rows'])]])->validate();
        DB::transaction(function () use ($batch, $data, $actor): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->editable($batch, (int) $data['revision']);
            $batch->forceFill([...$data, 'revision' => $batch->revision + 1, 'reviewed_at' => null, 'reviewed_by' => null, 'stage' => 'prepare'])->save();
            $this->validateRows($batch);
            $this->transition($batch, 'awaiting_confirmation', 'mapping_changed', $actor->id, ['mapping' => $data['mapping'], 'duplicate_policy' => $data['duplicate_policy'], 'error_policy' => $data['error_policy']]);
        });
    }

    public function correct(User $actor, ImportBatch $batch, int $rowId, array $input): void
    {
        Gate::forUser($actor)->authorize('update', $batch);
        $data = Validator::make($input, ['revision' => ['required', 'integer'], 'cells' => ['required', 'array', 'list', 'size:'.count($batch->headers ?? [])], 'cells.*' => ['nullable', 'string', 'max:5000'], 'product_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer']])->validate();
        DB::transaction(function () use ($batch, $rowId, $data, $actor): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->editable($batch, (int) $data['revision']);
            $row = $batch->rows()->findOrFail($rowId);
            $before = $row->raw;
            $beforeOverrides = $row->overrides;
            $overrides = $batch->type === 'offers' ? ['product_id' => $data['product_id'] ?? null, 'supplier_id' => $data['supplier_id'] ?? null] : null;
            $row->update(['raw' => $data['cells'], 'overrides' => $overrides]);
            $batch->forceFill(['revision' => $batch->revision + 1, 'reviewed_at' => null, 'reviewed_by' => null, 'stage' => 'prepare'])->save();
            $this->validateRows($batch);
            $this->transition($batch, 'awaiting_confirmation', 'row_corrected', $actor->id, ['row' => $row->row_number, 'before' => $before, 'after' => $data['cells'], 'before_matches' => $beforeOverrides, 'after_matches' => $overrides]);
        });
    }

    public function confirm(User $actor, ImportBatch $batch, int $revision): void
    {
        Gate::forUser($actor)->authorize('update', $batch);
        $dispatch = DB::transaction(function () use ($actor, $batch, $revision): bool {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            if (in_array($batch->status, ['queued', 'processing', 'completed', 'completed_with_errors'], true) && $batch->stage === 'commit' && $batch->revision === $revision) {
                return false;
            }
            $this->editable($batch, $revision);
            if (! $batch->row_count || $batch->scan_status === 'infected' || (config('imports.scan_required') && $batch->scan_status !== 'clean')) {
                abort(409);
            }
            if ($batch->error_count > 0 && $batch->error_policy === 'atomic') {
                throw ValidationException::withMessages(['import' => 'Xato satrlar bor; tuzating yoki explicit valid_rows siyosatini tanlang.']);
            }
            $batch->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'stage' => 'commit', 'generation' => $batch->generation + 1, 'processed_count' => 0, 'lease_token' => null, 'lease_expires_at' => null])->save();
            $this->transition($batch, 'queued', 'human_confirmed', $actor->id, ['revision' => $revision, 'errors' => $batch->error_count, 'error_policy' => $batch->error_policy]);

            return true;
        });
        if ($dispatch) {
            $this->dispatch($batch->fresh());
        }
    }

    private function editable(ImportBatch $batch, int $revision): void
    {
        abort_unless(in_array($batch->status, ['awaiting_confirmation', 'failed'], true) && $batch->revision === $revision && ! $batch->purged_at && ! $batch->completed_at && $batch->expires_at->isFuture(), 409, 'Preview holati yoki versiyasi o‘zgargan.');
    }

    public function commit(int $id, string $token): void
    {
        $batch = ImportBatch::findOrFail($id);
        if ($batch->status !== 'processing' || $batch->lease_token !== $token) {
            return;
        }
        $path = Storage::disk('catalog')->path($batch->file_path);
        if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_hash) {
            throw new ImportFailure('source_missing_or_changed');
        }
        $this->reconciliation->reset();
        if ($batch->error_policy === 'atomic') {
            DB::transaction(function () use ($id, $token): void {
                $batch = $this->lockedCommit($id, $token);
                foreach ($batch->rows()->orderBy('id')->lazyById(100) as $row) {
                    $this->writeRow($batch, $row);
                }
                $this->complete($batch);
            }, 3);
        } else {
            foreach ($batch->rows()->select('id')->orderBy('id')->lazyById(100) as $reference) {
                try {
                    DB::transaction(function () use ($id, $token, $reference): void {
                        $batch = $this->lockedCommit($id, $token);
                        $this->writeRow($batch, $batch->rows()->findOrFail($reference->id));
                    }, 3);
                } catch (ImportFailure|ValidationException $error) {
                    DB::transaction(function () use ($id, $token, $reference, $error): void {
                        $batch = $this->lockedCommit($id, $token);
                        $row = $batch->rows()->findOrFail($reference->id);
                        if (in_array($row->status, ['created', 'updated', 'skipped', 'failed'], true)) {
                            return;
                        }
                        $row->forceFill(['status' => 'failed', 'errors' => ['commit' => [$error instanceof ImportFailure ? $error->errorCode : 'Tasdiqdan keyingi validatsiya mos emas.']]])->save();
                        $batch->forceFill(['processed_count' => $batch->processed_count + 1])->save();
                    });
                }
            }
            DB::transaction(function () use ($id, $token): void {
                $this->complete($this->lockedCommit($id, $token));
            });
        }
    }

    private function lockedCommit(int $id, string $token): ImportBatch
    {
        $reference = ImportBatch::findOrFail($id);
        Organization::whereKey($reference->organization_id)->lockForUpdate()->firstOrFail();
        $batch = ImportBatch::lockForUpdate()->findOrFail($id);
        if ($batch->lease_token !== $token || $batch->status !== 'processing' || ! $batch->reviewed_by || ! $batch->reviewed_at) {
            throw new ImportFailure('lease_lost');
        }
        $actor = User::find($batch->reviewed_by);
        if (! $actor || ! $actor->can('update', $batch)) {
            throw new ImportFailure('permission_changed');
        }
        $batch->forceFill(['lease_expires_at' => now()->addSeconds(90)])->save();

        return $batch;
    }

    private function writeRow(ImportBatch $batch, ImportRow $row): void
    {
        if (in_array($row->status, ['created', 'updated', 'skipped', 'failed'], true)) {
            return;
        }
        if ($row->status === 'invalid') {
            throw new ImportFailure('row_validation_failed');
        }
        if ($row->status === 'duplicate') {
            $row->forceFill(['status' => 'skipped', 'result_type' => $batch->type, 'result_id' => $row->match['id'] ?? null])->save();
            $batch->forceFill(['processed_count' => $batch->processed_count + 1])->save();

            return;
        }
        $previewMatch = $row->match;
        $this->reconciliation->reset();
        $fresh = $this->reconciliation->row($batch, $row);
        if ($fresh['status'] === 'invalid') {
            throw new ImportFailure('row_validation_changed');
        }
        if (($previewMatch['snapshot'] ?? null) !== ($fresh['match']['snapshot'] ?? null)) {
            throw new ImportFailure('matching_record_changed');
        }
        $data = $fresh['data'];
        $actor = User::findOrFail($batch->reviewed_by);
        $organization = Organization::findOrFail($batch->organization_id);
        $existingId = $fresh['match']['id'] ?? null;
        if ($batch->type === 'products') {
            if ($existingId) {
                $existing = Product::where('organization_id', $batch->organization_id)->findOrFail($existingId);
                $data = [...$existing->only(['name', 'sku', 'unit', 'barcode', 'category', 'description', 'aliases', 'variant_attributes', 'parent_product_id', 'package_quantity', 'package_unit']),
                    ...array_intersect_key($data, array_flip(['name', 'category', 'description', 'aliases', 'package_quantity', 'package_unit']))];
            }
            $result = $this->products->execute($actor, $organization, $data, $existingId);
        } elseif ($batch->type === 'suppliers') {
            Gate::forUser($actor)->authorize('catalog', $organization);
            if ($existingId) {
                $result = Supplier::where('organization_id', $batch->organization_id)->lockForUpdate()->findOrFail($existingId);
                $result->update(array_intersect_key($data, array_flip(['contact', 'city', 'email', 'description'])));
            } else {
                $result = Supplier::create([...$data, 'organization_id' => $batch->organization_id]);
            }
            AuditLog::create(['organization_id' => $batch->organization_id, 'user_id' => $actor->id, 'action' => $existingId ? 'supplier.updated' : 'supplier.created',
                'subject_type' => 'supplier', 'subject_id' => $result->id, 'metadata' => ['import_batch_id' => $batch->id]]);
        } else {
            $evidence = CatalogFile::firstOrCreate(['import_batch_id' => $batch->id, 'supplier_id' => $data['supplier_id']], ['organization_id' => $batch->organization_id,
                'uploaded_by' => $batch->user_id, 'name' => $batch->file_name, 'path' => $batch->file_path, 'mime' => $batch->file_mime, 'size' => $batch->file_size]);
            $ai = ExtractionAttempt::where('import_batch_id', $batch->id)->where('status', 'completed')->exists();
            $type = match ($batch->format) {
                'csv' => 'csv', 'xlsx' => 'excel', default => $ai ? 'ocr' : 'manual'
            };
            $result = $this->offers->execute($actor, $organization, [...$data, 'source_type' => $type, 'source_identifier' => $batch->file_hash,
                'task_reference' => 'import:'.$batch->id, 'evidence_file_id' => $evidence->id], $batch->format === 'csv' ? 'csv' : 'manual', $batch);
        }
        $row->forceFill(['status' => $existingId ? 'updated' : 'created', 'result_type' => $batch->type, 'result_id' => $result->id])->save();
        $batch->forceFill(['processed_count' => $batch->processed_count + 1])->save();
        if ($batch->type === 'offers' && $row->status === 'created' && $result instanceof Offer) {
            app(RecordOffer::class)->afterRecorded($organization, $result->refresh());
        }
    }

    private function complete(ImportBatch $batch): void
    {
        $counts = $batch->rows()->selectRaw('status, count(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $summary = ['created' => (int) ($counts['created'] ?? 0), 'updated' => (int) ($counts['updated'] ?? 0), 'skipped' => (int) ($counts['skipped'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0)];
        $batch->forceFill(['summary' => $summary, 'error_count' => $summary['failed'], 'processed_count' => $batch->row_count, 'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null])->save();
        $this->transition($batch, $summary['failed'] > 0 ? 'completed_with_errors' : 'completed', 'finished', $batch->reviewed_by, $summary);
        app(ManageAlerts::class)->dispatch(Organization::find($batch->organization_id), match (true) {
            $summary['failed'] > 0 => 'import_completed_with_errors', default => 'import_completed'
        },
            'batch:'.$batch->id.':'.$batch->generation, $summary['failed'] > 0 ? 'Import xatolar bilan tugadi' : 'Import to‘liq yakunlandi',
            $batch->file_name.': '.$summary['created'].' yaratildi, '.$summary['updated'].' yangilandi, '.$summary['failed'].' xato',
            ['scope_id' => null], '/import-center/'.$batch->id);
    }

    public function cancel(User $actor, ImportBatch $batch): void
    {
        Gate::forUser($actor)->authorize('update', $batch);
        DB::transaction(function () use ($actor, $batch): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            abort_if(in_array($batch->status, ['completed', 'completed_with_errors', 'cancelled'], true) || ($batch->stage === 'commit' && $batch->status === 'processing'), 409, 'Boshlangan commit bekor qilinmaydi; natijani kuting.');
            $batch->forceFill(['cancelled_at' => now(), 'lease_token' => null, 'lease_expires_at' => null])->save();
            $this->transition($batch, 'cancelled', 'cancelled', $actor->id);
        });
    }

    public function retry(User $actor, ImportBatch $batch): void
    {
        Gate::forUser($actor)->authorize('update', $batch);
        DB::transaction(function () use ($actor, $batch): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($batch->status === 'failed' && ! $batch->purged_at && ($batch->reviewed_at || $batch->expires_at->isFuture()), 409);
            $batch->forceFill(['lease_token' => null, 'lease_expires_at' => null, 'error_code' => null, 'generation' => $batch->generation + 1])->save();
            $this->transition($batch, 'queued', 'manual_retry', $actor->id);
        });
        $this->dispatch($batch->fresh());
    }

    public function selectSheet(User $actor, ImportBatch $batch, int $sheet, int $header): void
    {
        Gate::forUser($actor)->authorize('update', $batch);
        abort_unless($batch->format === 'xlsx', 409);
        DB::transaction(function () use ($actor, $batch, $sheet, $header): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->editable($batch, $batch->revision);
            $batch->forceFill(['sheet' => $sheet, 'header_row' => $header, 'stage' => 'prepare', 'generation' => $batch->generation + 1, 'revision' => $batch->revision + 1,
                'reviewed_at' => null, 'reviewed_by' => null, 'processed_count' => 0])->save();
            $this->transition($batch, 'queued', 'sheet_selected', $actor->id, ['sheet' => $sheet, 'header' => $header]);
        });
        $this->dispatch($batch->fresh());
    }

    public function manualRow(User $actor, ImportBatch $batch): void
    {
        Gate::forUser($actor)->authorize('update', $batch);
        DB::transaction(function () use ($actor, $batch): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->editable($batch, $batch->revision);
            abort_unless(in_array($batch->format, ['pdf', 'png', 'jpg'], true) && $batch->scan_status !== 'infected' && (! config('imports.scan_required') || $batch->scan_status === 'clean'), 409);
            abort_if($batch->rows()->count() >= config('imports.max_rows'), 409);
            $headers = $batch->headers ?: $this->reconciliation->columns($batch->type);
            $number = (int) ($batch->rows()->max('row_number') ?? 1) + 1;
            ImportRow::create(['organization_id' => $batch->organization_id, 'import_batch_id' => $batch->id, 'row_number' => $number,
                'raw' => array_fill(0, count($headers), null), 'extraction' => ['fields' => [], 'warnings' => ['Qo‘lda ajratilgan; AI natijasi emas.'], 'missing_fields' => $headers]]);
            $batch->forceFill(['headers' => $headers, 'mapping' => array_flip($headers), 'revision' => $batch->revision + 1, 'row_count' => $batch->rows()->count(), 'stage' => 'prepare', 'reviewed_at' => null, 'reviewed_by' => null])->save();
            $this->validateRows($batch);
            $this->transition($batch, 'awaiting_confirmation', 'manual_row_added', $actor->id);
        });
    }

    public function release(int $id, string $token, string $code): void
    {
        DB::transaction(function () use ($id, $token, $code): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($id);
            if ($batch->lease_token !== $token) {
                return;
            }
            $batch->forceFill(['lease_token' => null, 'lease_expires_at' => null, 'error_code' => $code])->save();
            $this->transition($batch, 'queued', 'retry_scheduled', null, ['error_code' => $code]);
        });
    }

    public function fail(ImportBatch $batch, string $code, ?string $token = null): void
    {
        DB::transaction(function () use ($batch, $code, $token): void {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            if (in_array($batch->status, ['completed', 'completed_with_errors', 'cancelled'], true) || ($token !== null && $batch->lease_token !== $token)) {
                return;
            }
            $batch->forceFill(['error_code' => $code, 'lease_token' => null, 'lease_expires_at' => null])->save();
            $this->transition($batch, 'failed', 'failed', null, ['error_code' => $code]);
        });
    }

    public function transition(ImportBatch $batch, string $next, string $action, ?int $actor = null, array $metadata = []): void
    {
        $from = $batch->status;
        $batch->forceFill(['status' => $next])->save();
        $this->event($batch, $action, $actor, $metadata, $from);
    }

    private function event(ImportBatch $batch, string $action, ?int $actor = null, array $metadata = [], ?string $from = null): void
    {
        ImportEvent::create(['organization_id' => $batch->organization_id, 'import_batch_id' => $batch->id, 'actor_id' => $actor,
            'action' => $action, 'from_status' => $from, 'to_status' => $batch->status, 'metadata' => $metadata]);
        if ($actor !== null) {
            AuditLog::create(['organization_id' => $batch->organization_id, 'user_id' => $actor, 'action' => 'import.'.$action,
                'subject_type' => 'import_batch', 'subject_id' => $batch->id, 'metadata' => ['status' => $batch->status, 'revision' => $batch->revision]]);
        }
    }

    /** Phase 6 hook: small recent-import feed for analytics dashboard (no secrets). */
    public static function recentFor(int $limit = 5): array
    {
        return ImportBatch::whereIn('organization_id', Organization::allIds())->orderByDesc('id')->limit($limit)
            ->get(['id', 'type', 'format', 'file_name', 'status', 'row_count', 'error_count', 'completed_at', 'created_at'])
            ->map(fn (ImportBatch $batch): array => ['id' => $batch->id, 'type' => $batch->type, 'format' => $batch->format,
                'file_name' => $batch->file_name, 'status' => $batch->status, 'row_count' => $batch->row_count,
                'error_count' => $batch->error_count, 'completed_at' => $batch->completed_at?->toISOString(),
                'created_at' => $batch->created_at->toISOString()])->all();
    }
}
