<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AssistantFailure;
use App\Services\OpenRouterAssistant;
use App\Services\WorkspaceInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AiAssistantController extends Controller
{
    public function store(Request $request, OpenRouterAssistant $assistant, WorkspaceInsights $insights): JsonResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('view', $organization);
        $data = $request->validate(['message' => ['required', 'string', 'max:2000'],
            'product_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000000']]);
        if (! $assistant->availability()['configured']) {
            return response()->json(['message' => 'OpenRouter API kaliti hali sozlanmagan.'], 503);
        }
        $context = $insights->summarize($organization, $request->user(), isset($data['product_id']) ? (int) $data['product_id'] : null, (string) ($data['quantity'] ?? '1'));
        $key = 'ai_chat.'.$organization->id;
        $history = $request->session()->get($key, []);
        try {
            $result = $assistant->answer($data['message'], $context, $history);
        } catch (AssistantFailure $error) {
            return response()->json(['message' => $error->getMessage()], $error->status);
        }
        $request->session()->put($key, array_slice([...$history,
            ['role' => 'user', 'content' => $data['message']], ['role' => 'assistant', 'content' => $result['answer']]], -6));
        $sources = collect($context['decisions'])->whereNotNull('offer_id')->map(fn (array $row): array => [
            'id' => $row['offer_id'], 'name' => $row['name'], 'url' => route('prices.show', $row['offer_id'], false),
        ])->values();

        return response()->json([...$result, 'sources' => $sources]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('view', $organization);
        $request->session()->forget('ai_chat.'.$organization->id);

        return response()->json(['cleared' => true]);
    }
}
