<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LatestOffers
{
    /** One quote stream per tenant/product/supplier, regardless of source or package. */
    public function query(Product $product, CarbonImmutable $asOf, User $user, int $depth = 1, ?\Closure $filter = null): Builder
    {
        abort_unless($user->can('view', Organization::findOrFail($product->organization_id)), 404);
        $ranked = Offer::query()->where('organization_id', $product->organization_id)->where('product_id', $product->id)
            ->where('observed_at', '<=', $asOf->utc())->whereRaw(self::recordedExpression().' <= ?', [$asOf->utc()->format('Y-m-d H:i:s.u')])
            ->when($filter, fn ($query) => $filter($query))
            ->select('offers.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY supplier_id ORDER BY observed_at DESC, '.self::recordedExpression().' DESC, id DESC) AS observation_rank');

        return Offer::query()->fromSub($ranked, 'offers')->where('observation_rank', '<=', $depth)->orderBy('supplier_id')->orderBy('observation_rank');
    }

    public static function recordedExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "COALESCE(recorded_at, created_at || CASE WHEN length(created_at) = 19 THEN '.000000' ELSE '' END)"
            : 'COALESCE(recorded_at, created_at)';
    }

    public function get(Product $product, CarbonImmutable $asOf, User $user, int $depth = 1, ?\Closure $filter = null): Collection
    {
        $offers = $this->query($product, $asOf, $user, $depth, $filter)->with('supplier')->limit(500 * $depth + 1)->get();
        if ($offers->count() > 500 * $depth) {
            throw ValidationException::withMessages(['product_id' => '500 dan ortiq supplier: global ranking chiqarilmadi. Mahsulot scope’ini toraytiring.']);
        }

        return $offers;
    }
}
