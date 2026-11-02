<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\AuditLog;
use App\Models\CatalogFile;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\SupplierDispute;
use App\Models\SupplierReview;
use App\Models\SupplierVerification;
use App\Services\SupplierTrust;
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

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'verification' => ['nullable', Rule::in(['unverified', 'pending', 'verified', 'rejected', 'suspended'])],
            'sort' => ['nullable', Rule::in(['name', 'city', 'created_at'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])]]);
        $query = $this->query($request);
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') {
            $status === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }
        if (! empty($filters['verification'])) {
            $query->where('verification_status', $filters['verification']);
        }
        $items = $query->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')->orderBy('id')->paginate(20)->withQueryString();

        return Inertia::render('Workspace', ['section' => 'suppliers', 'suppliers' => $items, 'catalog' => ['kind' => 'suppliers', 'items' => $items,
            'filters' => $filters, 'canManage' => $request->user()->can('catalog', $request->attributes->get('organization')), 'categories' => []]]);
    }

    private function query(Request $request): Builder
    {
        return Supplier::whereIn('organization_id', Organization::allIds())
            ->when($request->input('q'), fn ($query, $q) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']));
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->query($request)->whereNull('archived_at')->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name']));
    }

    public function show(Request $request, int $supplier): Response
    {
        $record = $this->record($request, $supplier);
        Gate::authorize('view', $record);
        $reviews = SupplierReview::where('supplier_id', $record->id)->whereNull('hidden_at')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('supplier_disputes')->whereColumn('review_id', 'supplier_reviews.id')->whereNull('resolved_at'));
        $count = (clone $reviews)->count();
        $canVerify = $request->user()->can('verify', $record);

        return Inertia::render('Supplier', [
            'supplier' => $record, 'canManage' => $request->user()->can('update', $record), 'canVerify' => $request->user()->can('verify', $record),
            'canPlatformVerify' => $request->user()->can('platformVerify', $record), 'canReview' => $request->user()->can('review', $record),
            'documents' => CatalogFile::where('organization_id', $record->organization_id)->where('supplier_id', $record->id)->whereNull('retired_at')->get(),
            'history' => SupplierVerification::where('supplier_id', $record->id)->latest('id')->paginate(10, ['*'], 'history_page'),
            'evidenceOffers' => Offer::where('organization_id', $record->organization_id)->where('supplier_id', $record->id)->latest('id')->paginate(20, ['id', 'product_id', 'price', 'currency', 'observed_at'], 'offer_page'),
            'reviews' => SupplierReview::where('supplier_id', $record->id)->latest('id')->paginate(10, ['*'], 'review_page')->through(function ($review) use ($request, $canVerify) {
                if ($review->hidden_at && $review->user_id !== $request->user()->id && ! $canVerify) {
                    $review->body = 'Moderatsiya sababli yashirilgan';
                }

                return $review;
            }),
            'disputes' => SupplierDispute::whereIn('review_id', SupplierReview::where('supplier_id', $record->id)->select('id'))->latest('id')->paginate(10, ['*'], 'dispute_page'),
            'activity' => AuditLog::where('organization_id', $record->organization_id)->whereIn('subject_type', ['supplier', 'suppliers'])->where('subject_id', $record->id)->latest('id')->limit(20)->get(),
            'reliability' => ['count' => $count, 'score' => $count >= 3 ? round((float) (clone $reviews)->avg('rating'), 2) : null, 'basis' => 'Offer engagement reviews; completed purchase emas'],
        ]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(SupplierRequest $request, int $supplier): RedirectResponse
    {
        return $this->save($request, $supplier);
    }

    private function save(SupplierRequest $request, ?int $id = null): RedirectResponse
    {
        DB::transaction(function () use ($request, $id): void {
            $record = $id ? $this->record($request, $id) : null;
            if ($record) {
                if ($record->archived_at) {
                    throw ValidationException::withMessages(['supplier' => 'Avval yetkazib beruvchini tiklang.']);
                }
                Gate::authorize('update', $record);
                $record->update($request->validated());
            } else {
                $record = Supplier::create([...$request->validated(), 'organization_id' => $request->attributes->get('organization')->id]);
            }
            $this->audit($request, $record, $id ? 'supplier.updated' : 'supplier.created');
        });

        return ($id ? to_route('suppliers.show', $id) : to_route('suppliers'))->with('success', $id ? 'Yetkazib beruvchi yangilandi.' : 'Yetkazib beruvchi qo‘shildi.');
    }

    public function archive(Request $request, int $supplier): RedirectResponse
    {
        return $this->status($request, $supplier, true);
    }

    public function restore(Request $request, int $supplier): RedirectResponse
    {
        return $this->status($request, $supplier, false);
    }

    private function status(Request $request, int $id, bool $archive): RedirectResponse
    {
        DB::transaction(function () use ($request, $id, $archive): void {
            $record = Supplier::whereIn('organization_id', Organization::allIds())->lockForUpdate()->findOrFail($id);
            Gate::authorize('update', $record);
            if (($record->archived_at !== null) === $archive) {
                return;
            }
            $record->update(['archived_at' => $archive ? now() : null]);
            $this->audit($request, $record, $archive ? 'supplier.archived' : 'supplier.restored');
        });

        return to_route('suppliers.show', $id)->with('success', $archive ? 'Yetkazib beruvchi arxivlandi.' : 'Yetkazib beruvchi tiklandi.');
    }

    public function verification(Request $request, int $supplier, SupplierTrust $service): RedirectResponse
    {
        $service->transition($request->user(), $this->record($request, $supplier), $request->only('scope', 'action', 'reason', 'evidence_ids'));

        return to_route('suppliers.show', $supplier)->with('success', 'Tekshiruv holati va dalillari saqlandi.');
    }

    public function review(Request $request, int $supplier): RedirectResponse
    {
        $record = $this->record($request, $supplier);
        Gate::authorize('review', $record);
        $data = $request->validate(['offer_id' => ['required', 'integer', Rule::exists('offers', 'id')->where('organization_id', $record->organization_id)->where('supplier_id', $record->id)],
            'rating' => ['required', 'integer', 'min:1', 'max:5'], 'body' => ['required', 'string', 'min:20', 'max:3000']]);
        DB::transaction(function () use ($request, $record, $data): void {
            Supplier::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (SupplierReview::where('supplier_id', $record->id)->where('user_id', $request->user()->id)->exists()) {
                throw ValidationException::withMessages(['body' => 'Siz bu yetkazib beruvchi uchun allaqachon fikr bildirgansiz.']);
            }
            $review = SupplierReview::create([...$data, 'organization_id' => $record->organization_id, 'supplier_id' => $record->id, 'user_id' => $request->user()->id]);
            $this->audit($request, $record, 'supplier.review_created', ['review_id' => $review->id]);
        });

        return to_route('suppliers.show', $supplier)->with('success', 'Taklifga asoslangan fikr saqlandi.');
    }

    public function dispute(Request $request, int $supplier, int $review): RedirectResponse
    {
        $record = $this->record($request, $supplier);
        $entry = SupplierReview::where('organization_id', $record->organization_id)->where('supplier_id', $record->id)->findOrFail($review);
        abort_unless($entry->user_id === $request->user()->id || $request->user()->can('update', $record), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        DB::transaction(function () use ($request, $record, $entry, $data): void {
            Supplier::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (SupplierDispute::where('review_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Ushbu fikr bo‘yicha dispute mavjud.']);
            }
            SupplierDispute::create([...$data, 'review_id' => $entry->id, 'opened_by' => $request->user()->id]);
            $this->audit($request, $record, 'supplier.dispute_opened', ['review_id' => $entry->id]);
        });

        return to_route('suppliers.show', $supplier)->with('success', 'Dispute ochildi.');
    }

    public function resolve(Request $request, int $supplier, int $dispute): RedirectResponse
    {
        $record = $this->record($request, $supplier);
        Gate::authorize('verify', $record);
        $data = $request->validate(['resolution' => ['required', 'string', 'min:20', 'max:3000'], 'hide_review' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $record, $dispute, $data): void {
            Supplier::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $entry = SupplierDispute::whereIn('review_id', SupplierReview::where('supplier_id', $record->id)->select('id'))->lockForUpdate()->findOrFail($dispute);
            $review = SupplierReview::findOrFail($entry->review_id);
            abort_if($entry->opened_by === $request->user()->id || $review->user_id === $request->user()->id, 403);
            if ($entry->resolved_at) {
                throw ValidationException::withMessages(['resolution' => 'Dispute allaqachon hal qilingan.']);
            }
            $entry->update(['resolution' => $data['resolution'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
            $review->update(['hidden_at' => $data['hide_review'] ? now() : null]);
            $this->audit($request, $record, 'supplier.dispute_resolved', ['review_id' => $review->id, 'hide_review' => (bool) $data['hide_review']]);
        });

        return to_route('suppliers.show', $supplier)->with('success', 'Dispute qarori saqlandi.');
    }

    private function record(Request $request, int $id): Supplier
    {
        return Supplier::whereIn('organization_id', Organization::allIds())->findOrFail($id);
    }

    private function audit(Request $request, Supplier $record, string $action, array $metadata = []): void
    {
        AuditLog::create(['organization_id' => $record->organization_id, 'user_id' => $request->user()->id, 'action' => $action, 'subject_type' => 'supplier', 'subject_id' => $record->id, 'metadata' => $metadata]);
    }
}
