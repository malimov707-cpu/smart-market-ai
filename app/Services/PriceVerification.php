<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CatalogFile;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PriceVerification
{
    public function transition(User $actor, Offer $offer, array $input): PriceReview
    {
        $organization = Organization::findOrFail($offer->organization_id);
        Gate::forUser($actor)->authorize('manage', $organization);
        $data = Validator::make($input, ['scope' => ['required', Rule::in(['organization', 'platform'])],
            'status' => ['required', Rule::in(['pending', 'verified', 'rejected', 'disputed'])],
            'reason' => ['required', 'string', 'min:10', 'max:3000'], 'evidence_file_id' => ['required', 'integer']])->validate();
        if (in_array($data['status'], ['verified', 'rejected'], true)) {
            Gate::forUser($actor)->authorize('administer', $organization);
        }
        if ($data['scope'] === 'platform') {
            abort_unless(DB::table('platform_permissions')->where('user_id', $actor->id)->where('permission', 'price.verify')->exists(), 403);
        }

        return DB::transaction(function () use ($actor, $offer, $data): PriceReview {
            $offer = Offer::where('organization_id', $offer->organization_id)->lockForUpdate()->findOrFail($offer->id);
            $evidence = CatalogFile::where('organization_id', $offer->organization_id)->where('supplier_id', $offer->supplier_id)
                ->whereNull('retired_at')->lockForUpdate()->find($data['evidence_file_id']);
            if (! $evidence) {
                throw ValidationException::withMessages(['evidence_file_id' => 'Taklif supplieriga tegishli faol dalil talab qilinadi.']);
            }
            $last = PriceReview::where('organization_id', $offer->organization_id)->where('offer_id', $offer->id)->where('scope', $data['scope'])->latest('id')->first();
            $status = $last?->status ?? 'unverified';
            $allowed = match ($data['status']) {
                'pending' => in_array($status, ['unverified', 'rejected', 'disputed'], true),
                'verified', 'rejected' => $status === 'pending',
                'disputed' => in_array($status, ['pending', 'verified'], true),
            };
            if (! $allowed || ($offer->expires_at && $offer->expires_at->lte(now()))) {
                throw ValidationException::withMessages(['status' => 'Holat o‘tishi mumkin emas yoki taklif muddati tugagan.']);
            }
            if (in_array($data['status'], ['verified', 'rejected'], true) && ($last?->actor_id === $actor->id || $offer->submitted_by === $actor->id || $evidence->uploaded_by === $actor->id || $last?->evidence_file_id !== $evidence->id)) {
                throw ValidationException::withMessages(['status' => 'Mustaqil reviewer aynan yuborilgan dalilni ko‘rib chiqishi kerak.']);
            }
            $event = PriceReview::create([...$data, 'organization_id' => $offer->organization_id, 'offer_id' => $offer->id, 'actor_id' => $actor->id]);
            AuditLog::create(['organization_id' => $offer->organization_id, 'user_id' => $actor->id,
                'action' => 'price.'.$data['status'], 'subject_type' => 'offer', 'subject_id' => $offer->id,
                'metadata' => ['review_id' => $event->id, 'scope' => $data['scope'], 'previous' => $status]]);

            return $event;
        });
    }

    /** Bounded two-scope snapshots; no future review can change historical results. */
    public function snapshots(int $tenant, array $ids, CarbonImmutable $asOf): array
    {
        if ($ids === []) {
            return [];
        }
        $ranked = PriceReview::where('organization_id', $tenant)->whereIn('offer_id', $ids)->where('created_at', '<=', $asOf->utc()->format('Y-m-d H:i:s.u'))
            ->select('price_reviews.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY offer_id, scope ORDER BY created_at DESC, id DESC) AS review_rank');

        return PriceReview::fromSub($ranked, 'price_reviews')->where('review_rank', 1)->get()->groupBy('offer_id')
            ->map(fn ($events): array => $events->keyBy('scope')->toArray())->all();
    }
}
