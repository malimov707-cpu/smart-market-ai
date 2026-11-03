<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminOverview
{
    public function __construct(private OperationsCheck $operationsCheck) {}

    /**
     * @return array{
     *     stats: array<string, int>,
     *     trends: array{days: list<string>, series: array<string, list<int>>},
     *     health: array{dependencies: array<string, mixed>, sources: list<array<string, mixed>>, scheduler: array<string, string>, notifications: array<string, string>},
     *     activity: list<array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        $now = CarbonImmutable::now();
        $start = $now->startOfDay()->subDays(13);
        $days = collect(range(0, 13))
            ->map(fn (int $offset): string => $start->addDays($offset)->toDateString());
        $activeSince = $now->subDays(30);

        $usersByDay = $this->dailyCounts(DB::table('users'), 'created_at', $start);
        $organizationsByDay = $this->dailyCounts(DB::table('organizations'), 'created_at', $start);
        $productsByDay = $this->dailyCounts(DB::table('products'), 'created_at', $start);
        $suppliersByDay = $this->dailyCounts(DB::table('suppliers'), 'created_at', $start);
        $importsByDay = $this->dailyCounts(
            DB::table('import_batches')->whereIn('status', ['completed', 'completed_with_errors']),
            'completed_at',
            $start,
        );
        $extractionsByDay = $this->dailyCounts(DB::table('extraction_attempts'), 'created_at', $start);

        $latestPlatformReviews = DB::table('price_reviews')
            ->select(['id', 'organization_id', 'offer_id', 'status'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY organization_id, offer_id ORDER BY created_at DESC, id DESC) AS review_rank')
            ->where('scope', 'platform');

        $pendingPriceReviews = DB::query()
            ->fromSub($latestPlatformReviews, 'reviews')
            ->join('offers', function ($join): void {
                $join->on('offers.id', '=', 'reviews.offer_id')
                    ->on('offers.organization_id', '=', 'reviews.organization_id');
            })
            ->where('reviews.review_rank', 1)
            ->where('reviews.status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('offers.expires_at')
                    ->orWhere('offers.expires_at', '>', $now);
            })
            ->count();

        $stats = [
            'users' => User::query()->count(),
            'new_users_30d' => User::query()->where('created_at', '>=', $activeSince)->count(),
            'organizations' => Organization::query()->count(),
            'active_organizations_30d' => DB::table('audit_logs')
                ->whereNotNull('organization_id')
                ->where('created_at', '>=', $activeSince)
                ->distinct('organization_id')
                ->count('organization_id'),
            'products' => Product::query()->count(),
            'suppliers' => Supplier::query()->whereNull('archived_at')->count(),
            'pending_supplier_verifications' => Supplier::query()
                ->where('platform_verification_status', 'pending')
                ->whereNull('archived_at')
                ->count(),
            'offers' => DB::table('offers')->count(),
            'pending_price_reviews' => $pendingPriceReviews,
            'completed_imports_30d' => ImportBatch::query()
                ->whereIn('status', ['completed', 'completed_with_errors'])
                ->where('completed_at', '>=', $activeSince)
                ->count(),
            'failed_imports_30d' => ImportBatch::query()
                ->where('status', 'failed')
                ->where('updated_at', '>=', $activeSince)
                ->count(),
            'import_ai_attempts_30d' => DB::table('extraction_attempts')
                ->where('created_at', '>=', $activeSince)
                ->count(),
            'import_ai_failures_30d' => DB::table('extraction_attempts')
                ->where('created_at', '>=', $activeSince)
                ->whereIn('status', ['failed', 'unavailable'])
                ->count(),
        ];

        return [
            'stats' => $stats,
            'trends' => [
                'days' => $days->all(),
                'series' => [
                    'users' => $this->seriesFor($days, $usersByDay),
                    'organizations' => $this->seriesFor($days, $organizationsByDay),
                    'products' => $this->seriesFor($days, $productsByDay),
                    'suppliers' => $this->seriesFor($days, $suppliersByDay),
                    'completed_imports' => $this->seriesFor($days, $importsByDay),
                    'import_ai_attempts' => $this->seriesFor($days, $extractionsByDay),
                ],
            ],
            'health' => [
                'dependencies' => $this->operationsCheck->dependencies(),
                'sources' => $this->sourceStatus(),
                'scheduler' => [
                    'status' => 'unknown',
                    'detail' => 'Scheduler heartbeat is not recorded by the application.',
                ],
                'notifications' => [
                    'status' => 'unavailable',
                    'detail' => 'Platform notification delivery is not implemented.',
                ],
            ],
            'activity' => $this->recentActivity(),
        ];
    }

    /**
     * @return Collection<string, int>
     */
    private function dailyCounts(Builder $query, string $column, CarbonInterface $start): Collection
    {
        return $query
            ->where($column, '>=', $start)
            ->selectRaw("DATE({$column}) AS day, COUNT(*) AS total")
            ->groupByRaw("DATE({$column})")
            ->pluck('total', 'day')
            ->map(fn (int|string $total): int => (int) $total);
    }

    /**
     * @param  Collection<int, string>  $days
     * @param  Collection<string, int>  $counts
     * @return list<int>
     */
    private function seriesFor(Collection $days, Collection $counts): array
    {
        return $days->map(fn (string $day): int => (int) ($counts->get($day) ?? 0))->values()->all();
    }

    /**
     * @return list<array{source: string, status: string, detail: string, last_success_at: ?string, last_error_at: ?string}>
     */
    private function sourceStatus(): array
    {
        $sources = [
            [
                'source' => 'Korzinka',
                'snapshot_key' => 'public-market:korzinka:tashkent:v1',
                'error_key' => 'public-market:korzinka:tashkent:v1:error',
                'enabled' => true,
            ],
            [
                'source' => 'StatUz',
                'snapshot_key' => 'public-market:statuz:tashkent-market-averages:v1',
                'error_key' => 'public-market:statuz:tashkent-market-averages:v1:error',
                'enabled' => true,
            ],
            [
                'source' => 'Asaxiy',
                'snapshot_key' => 'public-market:asaxiy:index:v1',
                'error_key' => 'public-market:asaxiy:index:v1:error',
                'enabled' => (bool) config('services.asaxiy.catalog_enabled', false),
            ],
            [
                'source' => 'TezQur',
                'snapshot_key' => 'public-market:construction:tezqur:v1',
                'error_key' => 'public-market:construction:tezqur:v1:error',
                'enabled' => true,
            ],
            [
                'source' => 'KSLux',
                'snapshot_key' => 'public-market:construction:kslux:v1',
                'error_key' => 'public-market:construction:kslux:v1:error',
                'enabled' => true,
            ],
            [
                'source' => 'Alstroy',
                'snapshot_key' => 'public-market:construction:alstroy:v1',
                'error_key' => 'public-market:construction:alstroy:v1:error',
                'enabled' => true,
            ],
        ];

        return collect($sources)->map(function (array $source): array {
            $snapshot = Cache::get($source['snapshot_key']);
            $lastError = Cache::get($source['error_key']);
            $lastSuccessAt = is_array($snapshot) && is_string($snapshot['checked_at'] ?? null)
                ? $snapshot['checked_at']
                : null;

            if (! $source['enabled']) {
                $status = 'disabled';
                $detail = 'Source is disabled by configuration.';
            } elseif ($lastError !== null) {
                $status = 'failed';
                $detail = 'A recent synchronization failure is recorded.';
            } elseif ($snapshot === null) {
                $status = 'unknown';
                $detail = 'No cached synchronization result is available.';
            } else {
                $status = 'cached';
                $detail = 'Showing cached source data; this is not a live connectivity check.';
            }

            return [
                'source' => $source['source'],
                'status' => $status,
                'detail' => $detail,
                'last_success_at' => $lastSuccessAt,
                'last_error_at' => is_string($lastError) ? $lastError : null,
            ];
        })->all();
    }

    /**
     * @return list<array{type: string, action: string, actor: ?string, organization: ?string, occurred_at: string}>
     */
    private function recentActivity(): array
    {
        $events = collect();

        $feeds = [
            ['security_events', 'Xavfsizlik', 'user_id', 'action', false],
            ['audit_logs', 'Audit', 'user_id', 'action', true],
            ['import_events', 'Import', 'actor_id', 'action', true],
            ['price_reviews', 'Narx tekshiruvi', 'actor_id', 'status', true],
            ['supplier_verifications', 'Yetkazib beruvchi', 'actor_id', 'to_status', true],
        ];

        foreach ($feeds as [$table, $type, $actorColumn, $actionColumn, $hasOrganization]) {
            $query = DB::table($table)->leftJoin('users', 'users.id', '=', "{$table}.{$actorColumn}");
            if ($hasOrganization) {
                $query->leftJoin('organizations', 'organizations.id', '=', "{$table}.organization_id");
            }

            $query->select([
                "{$table}.created_at as occurred_at",
                'users.name as actor',
                "{$table}.{$actionColumn} as action",
            ])
                ->addSelect($hasOrganization ? 'organizations.name as organization' : DB::raw('NULL as organization'))
                ->orderByDesc("{$table}.created_at")
                ->limit(12);

            foreach ($query->get() as $event) {
                $events->push([
                    'type' => $type,
                    'action' => (string) $event->action,
                    'actor' => $event->actor,
                    'organization' => $event->organization,
                    'occurred_at' => (string) $event->occurred_at,
                ]);
            }
        }

        return $events
            ->sortByDesc('occurred_at')
            ->take(12)
            ->values()
            ->all();
    }
}
