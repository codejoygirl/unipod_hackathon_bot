<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Services\AI\AiServiceClient;
use App\Services\Knowledge\CitationRevalidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(
        protected AiServiceClient $aiClient,
        protected CitationRevalidator $citationRevalidator,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'community_ids' => ['required', 'array', 'min:1'],
            'community_ids.*' => ['ulid'],
            'target_language' => ['nullable', 'string', 'max:10'],
        ]);

        $user = $request->user();
        $requested = array_values(array_unique($validated['community_ids']));
        $accessible = $user->accessibleCommunityIds();

        $allowed = array_values(array_intersect($requested, $accessible));
        abort_if($allowed === [], 403, 'No accessible communities in the request.');

        $communities = Community::query()->whereIn('id', $allowed)->get();
        $tenantIds = $communities->pluck('tenant_id')->unique()->values();
        abort_unless($tenantIds->count() === 1, 422, 'Communities must belong to a single tenant.');

        $tenantId = (string) $tenantIds->first();

        $result = $this->aiClient->askGroundedQuestion(
            query: $validated['query'],
            tenantId: $tenantId,
            communityIds: $allowed,
            targetLanguage: $validated['target_language'] ?? null,
        );

        $result = $this->citationRevalidator->revalidate($user, $result);

        $needsEscalation = $result->needsEscalation || $result->state->requiresAdminEscalation();

        return response()->json([
            'data' => [
                'state' => $result->state->value,
                'answer' => $result->answer,
                'confidence' => $result->confidenceScore,
                'detected_language' => $result->detectedLanguage,
                'evidence_drawer' => array_map(fn ($c) => [
                    'evidence_id' => $c->evidenceId,
                    'source_name' => $c->sourceName,
                    'source_uri' => $c->sourceUri,
                    'exact_quote' => $c->exactQuote,
                    'context' => $c->contextSnippet,
                    'page' => $c->pageNumber,
                    'timestamp' => $c->timestampSeconds,
                    'authority' => $c->authorityTier,
                ], $result->citations),
                'conflicts' => array_map(fn ($conf) => [
                    'topic' => $conf->topic,
                    'claims' => $conf->conflictingClaims,
                    'action' => $conf->recommendedAction,
                ], $result->conflicts),
                'needs_escalation' => $needsEscalation,
                'escalation_reason' => $result->escalationReason
                    ?? ($needsEscalation ? 'Answer requires administrator review.' : null),
            ],
            'meta' => [
                'latency_ms' => $result->executionTimeMs,
                'chunks_evaluated' => $result->totalChunksRetrieved,
                'tenant_id' => $tenantId,
                'community_ids' => $allowed,
            ],
        ]);
    }
}
