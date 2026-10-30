<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CatalogFile;
use App\Models\Supplier;
use App\Models\SupplierVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierTrust
{
    public function transition(User $actor, Supplier $supplier, array $input): SupplierVerification
    {
        $data = Validator::make($input, ['scope' => ['required', Rule::in(['organization', 'platform'])],
            'action' => ['required', Rule::in(['submit', 'approve', 'reject', 'suspend'])], 'reason' => ['required', 'string', 'min:10', 'max:3000'],
            'evidence_ids' => ['sometimes', 'array', 'max:10'], 'evidence_ids.*' => ['required', 'integer', 'distinct']])->validate();

        return DB::transaction(function () use ($actor, $supplier, $data): SupplierVerification {
            $supplier = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($data['action'] === 'submit' ? 'update' : ($data['scope'] === 'platform' ? 'platformVerify' : 'verify'), $supplier);
            if ($supplier->archived_at) {
                throw ValidationException::withMessages(['supplier' => 'Arxivdagi yetkazib beruvchini avval tiklang.']);
            }
            $field = $data['scope'] === 'platform' ? 'platform_verification_status' : 'verification_status';
            $current = $supplier->$field;
            $last = SupplierVerification::where('supplier_id', $supplier->id)->where('scope', $data['scope'])->latest('id')->first();
            $next = match ($data['action']) {
                'submit' => 'pending','approve' => 'verified','reject' => 'rejected','suspend' => 'suspended'
            };
            $allowed = match ($data['action']) {
                'submit' => in_array($current, ['unverified', 'rejected', 'suspended'], true),'approve','reject' => $current === 'pending','suspend' => $current === 'verified'
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['action' => 'Ushbu holatda tanlangan o‘tish mumkin emas.']);
            }
            if (in_array($data['action'], ['approve', 'reject'], true) && $last?->actor_id === $actor->id) {
                throw ValidationException::withMessages(['action' => 'Arizani boshqa vakolatli tekshiruvchi ko‘rib chiqishi kerak.']);
            }
            $evidence = $data['action'] === 'submit' ? ($data['evidence_ids'] ?? []) : ($last?->evidence_ids ?? []);
            $evidence = array_map('intval', $evidence);
            if (empty($evidence) || CatalogFile::where('organization_id', $supplier->organization_id)->where('supplier_id', $supplier->id)->whereNull('retired_at')->whereIn('id', $evidence)->count() !== count($evidence)) {
                throw ValidationException::withMessages(['evidence_ids' => 'Shu yetkazib beruvchiga tegishli faol dalil hujjatlarini tanlang.']);
            }
            $event = SupplierVerification::create(['organization_id' => $supplier->organization_id, 'supplier_id' => $supplier->id, 'actor_id' => $actor->id,
                'scope' => $data['scope'], 'from_status' => $current, 'to_status' => $next, 'reason' => $data['reason'], 'evidence_ids' => $evidence]);
            $supplier->forceFill([$field => $next])->save();
            AuditLog::create(['organization_id' => $supplier->organization_id, 'user_id' => $actor->id, 'action' => 'supplier.verification.'.$next, 'subject_type' => 'supplier', 'subject_id' => $supplier->id,
                'metadata' => ['scope' => $data['scope'], 'from' => $current, 'to' => $next, 'verification_id' => $event->id]]);

            return $event;
        });
    }
}
