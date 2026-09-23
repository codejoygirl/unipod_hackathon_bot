<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Assistant\GroundedQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(
        protected GroundedQuestionService $groundedAsk,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'community_ids' => ['required', 'array', 'min:1'],
            'community_ids.*' => ['ulid'],
            'target_language' => ['nullable', 'string', 'max:10'],
        ]);

        $payload = $this->groundedAsk->ask(
            user: $request->user(),
            query: $validated['query'],
            communityIds: $validated['community_ids'],
            targetLanguage: $validated['target_language'] ?? null,
        );

        return response()->json($payload);
    }
}
