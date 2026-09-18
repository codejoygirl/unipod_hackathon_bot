<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\AiServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(
        protected AiServiceClient $aiClient
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'community_ids' => ['required', 'array', 'min:1'],
            'community_ids.*' => ['uuid'],
            'target_language' => ['nullable', 'string', 'max:10'],
        ]);

        $tenantId = (string) $request->user()->tenant_id;

        $result = $this->aiClient->askGroundedQuestion(
            query: $validated['query'],
            tenantId: $tenantId,
            communityIds: $validated['community_ids'],
            targetLanguage: $validated['target_language'] ?? null,
        );

        return response()->json([
            'data' => [
                'state' => $result->state->value,
                'answer' => $result->answer,
                'confidence' => $result->confidenceScore,
                'evidence_drawer' => array_map(fn($c) => [
                    'evidence_id' => $c->evidenceId,
                    'source_name' => $c->sourceName,
                    'source_uri' => $c->sourceUri,
                    'exact_quote' => $c->exactQuote,
                    'context' => $c->contextSnippet,
                    'page' => $c->pageNumber,
                    'timestamp' => $c->timestampSeconds,
                    'authority' => $c->authorityTier,
                ], $result->citations),
                'conflicts' => array_map(fn($conf) => [
                    'topic' => $conf->topic,
                    'claims' => $conf->conflictingClaims,
                    'action' => $conf->recommendedAction,
                ], $result->conflicts),
                'needs_escalation' => $result->needsEscalation,
                'escalation_reason' => $result->escalationReason,
            ],
            'meta' => [
                'latency_ms' => $result->executionTimeMs,
                'chunks_evaluated' => $result->totalChunksRetrieved,
            ],
        ]);
    }
}