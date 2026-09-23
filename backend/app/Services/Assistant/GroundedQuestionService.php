<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\Community;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use App\Services\Knowledge\CitationRevalidator;

final class GroundedQuestionService
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly CitationRevalidator $citationRevalidator,
    ) {}

    /**
     * @param  list<string>  $communityIds
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function ask(User $user, string $query, array $communityIds, ?string $targetLanguage = null): array
    {
        $requested = array_values(array_unique($communityIds));
        $accessible = $user->accessibleCommunityIds();
        $allowed = array_values(array_intersect($requested, $accessible));
        abort_if($allowed === [], 403, 'No accessible communities in the request.');

        $communities = Community::query()->whereIn('id', $allowed)->get();
        $tenantIds = $communities->pluck('tenant_id')->unique()->values();
        abort_unless($tenantIds->count() === 1, 422, 'Communities must belong to a single tenant.');

        $tenantId = (string) $tenantIds->first();

        $result = $this->aiClient->askGroundedQuestion(
            query: $query,
            tenantId: $tenantId,
            communityIds: $allowed,
            targetLanguage: $targetLanguage,
        );

        $result = $this->citationRevalidator->revalidate($user, $result);

        $needsEscalation = $result->needsEscalation || $result->state->requiresAdminEscalation();

        return [
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
        ];
    }
}
