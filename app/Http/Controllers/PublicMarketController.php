<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Services\AccountMail;
use App\Services\AssistantFailure;
use App\Services\OpenRouterAssistant;
use App\Services\PriceTrendHistory;
use App\Services\ProductVision;
use App\Services\PublicMarketCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PublicMarketController extends Controller
{
    public function index(Request $request, PublicMarketCatalog $catalog, OpenRouterAssistant $assistant, PriceTrendHistory $trends): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'terms' => ['sometimes', 'array', 'max:20'], 'terms.*' => ['string', 'max:80'],
            'department' => ['nullable', Rule::in(['all', 'grocery', 'construction', 'general'])],
            'source' => ['nullable', Rule::in(['all', 'korzinka', 'tezqur', 'kslux', 'alstroy', 'asaxiy', 'statuz'])]]);
        $query = $filters['q'] ?? '';
        $searchTerms = array_values(array_filter($filters['terms'] ?? []));
        $deal = $query === '' ? null : $trends->deal($query);
        $user = $request->user();
        $verified = $user->hasVerifiedEmail();
        $canUseVerifiedFeatures = $verified || $user->is_platform_admin;
        $watchSubscription = $query === '' ? null : AppNotification::where('user_id', $user->id)
            ->where('event_type', 'market_watch_subscribed')
            ->where('dedupe_key', 'watch-sub:'.AppNotification::priceWatchQueryKey($query))->first();

        return Inertia::render('PublicMarket', [
            'verification' => ['verified' => $verified, 'required' => ! $user->is_platform_admin, 'canUse' => $canUseVerifiedFeatures,
                'mailAvailable' => AccountMail::available()], 'filters' => ['q' => $query, 'terms' => $searchTerms,
                    'department' => $filters['department'] ?? 'all', 'source' => $filters['source'] ?? 'all'],
            'deal' => $deal,
            'trend' => $query === '' ? null : ['series' => $trends->series($query), 'change' => $trends->change($query)],
            'watch' => $query === '' ? null : ['watching' => $watchSubscription !== null,
                'threshold' => (string) data_get($watchSubscription?->metadata, 'change_percent', '5'),
                'channels' => data_get($watchSubscription?->metadata, 'channels', ['app'])],
            'market' => $catalog->search($query, $searchTerms, $filters['department'] ?? 'all', $filters['source'] ?? 'all', true),
            'assistant' => $assistant->availability()]);
    }

    public function scan(Request $request, ProductVision $vision, PublicMarketCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096']]);
        try {
            $recognition = $vision->identify($data['image']);
        } catch (AssistantFailure $error) {
            return response()->json(['message' => $error->getMessage()], $error->status);
        }

        $market = $catalog->search($recognition['name'], $recognition['search_terms'], includeOnDemand: true);

        $insight = $market['stale'] || $market['sync_failed'] || $market['partial']
            ? null
            : $catalog->insight($recognition['name'], $market['products']);

        return response()->json(['recognition' => $recognition, 'insight' => $insight, 'market' => $market]);
    }
}
