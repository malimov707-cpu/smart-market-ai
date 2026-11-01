<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AssistantFailure;
use App\Services\KorzinkaCatalog;
use App\Services\OpenRouterAssistant;
use App\Services\ProductVision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicMarketController extends Controller
{
    public function index(Request $request, KorzinkaCatalog $catalog, OpenRouterAssistant $assistant): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('PublicMarket', ['filters' => ['q' => $filters['q'] ?? ''],
            'market' => $catalog->search($filters['q'] ?? ''), 'assistant' => $assistant->availability()]);
    }

    public function scan(Request $request, ProductVision $vision, KorzinkaCatalog $catalog): JsonResponse
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
