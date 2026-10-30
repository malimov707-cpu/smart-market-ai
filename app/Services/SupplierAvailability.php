<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SupplierVerification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SupplierAvailability
{
    /** Historical status events, never a later current status, determine historical eligibility. */
    public function at(Collection $offers, CarbonImmutable $asOf): array
    {
        if ($offers->isEmpty()) {
            return [];
        }
        $tenant = $offers->first()->organization_id;
        $ids = $offers->pluck('supplier_id')->unique()->all();
        $cutoff = $asOf->utc()->format('Y-m-d H:i:s.u');
        $ranked = SupplierVerification::where('organization_id', $tenant)->whereIn('supplier_id', $ids)->where('created_at', '<=', $cutoff)
            ->select('supplier_verifications.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY supplier_id, scope ORDER BY created_at DESC, id DESC) AS state_rank');
        $states = SupplierVerification::fromSub($ranked, 'supplier_verifications')->where('state_rank', 1)->get()->groupBy('supplier_id');
        $audit = AuditLog::where('organization_id', $tenant)->where('subject_type', 'supplier')->whereIn('subject_id', $ids)
            ->whereIn('action', ['supplier.archived', 'supplier.restored'])->where('created_at', '<=', $cutoff)
            ->select('audit_logs.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY subject_id ORDER BY created_at DESC, id DESC) AS state_rank');
        $archives = AuditLog::fromSub($audit, 'audit_logs')->where('state_rank', 1)->get()->keyBy('subject_id');
        $result = [];
        foreach ($offers as $offer) {
            $supplier = $offer->supplier;
            $event = $archives[$supplier->id] ?? null;
            $result[$supplier->id] = ['archived' => $event ? $event->action === 'supplier.archived' : ($supplier->archived_at && $supplier->archived_at->lte($asOf)),
                'suspended' => ($states[$supplier->id] ?? collect())->contains('to_status', 'suspended')];
        }

        return $result;
    }
}
