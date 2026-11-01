<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountMail;
use App\Services\AssistantFailure;
use App\Services\OpenRouterAssistant;
use App\Services\ProductVision;
use App\Services\PublicMarketCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PublicMarketController extends Controller
{
    public function index(Request $request, PublicMarketCatalog $catalog, OpenRouterAssistant $assistant): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', Rule::in(['all', 'grocery', 'construction'])],
            'source' => ['nullable', Rule::in(['all', 'korzinka', 'tezqur', 'kslux', 'alstroy'])]]);

        return Inertia::render('PublicMarket', [
            'verification' => ['verified' => $request->user()->hasVerifiedEmail(), 'mailAvailable' => AccountMail::available()], 'filters' => ['q' => $filters['q'] ?? '', 'department' => $filters['department'] ?? 'all', 'source' => $filters['source'] ?? 'all'],
            'market' => $catalog->search($filters['q'] ?? '', [], $filters['department'] ?? 'all', $filters['source'] ?? 'all'), 'assistant' => $assistant->availability()]);
    }

    public function scan(Request $request, ProductVision $vision, PublicMarketCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096']]);
        try {
            $recognition = $vision->identify($data['image']);
        } catch (AssistantFailure $error) {
            return response()->json(['message' => $error->getMessage()], $error->status);
        }

        return response()->json(['recognition' => $recognition, 'market' => $catalog->search($recognition['name'], $recognition['search_terms'])]);
    }
}
