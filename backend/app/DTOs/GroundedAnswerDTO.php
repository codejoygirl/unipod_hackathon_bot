<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AnswerState;

final class CitationDTO
{
    public function __construct(
        public readonly string $evidenceId,
        public readonly ?string $chunkId,
        public readonly string $sourceName,
        public readonly string $sourceUri,
        public readonly string $exactQuote,
        public readonly string $contextSnippet,
        public readonly ?int $pageNumber,
        public readonly ?float $timestampSeconds,
        public readonly string $authorityTier,
        public readonly bool $isVerified = true,
    ) {}
}

final class ConflictDTO
{
    public function __construct(
        public readonly string $topic,
        /** @var list<string> */
        public readonly array $conflictingClaims,
        public readonly string $recommendedAction,
    ) {}
}

final class GroundedAnswerDTO
{
    /**
     * @param  list<CitationDTO>  $citations
     * @param  list<ConflictDTO>  $conflicts
     */
    public function __construct(
        public readonly string $query,
        public readonly string $detectedLanguage,
        public readonly AnswerState $state,
        public readonly string $answer,
        public readonly float $confidenceScore,
        public readonly array $citations,
        public readonly array $conflicts,
        public readonly bool $needsEscalation,
        public readonly ?string $escalationReason,
        public readonly float $executionTimeMs,
        public readonly int $totalChunksRetrieved,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiResponse(array $payload): self
    {
        $validated = $payload['validated_payload'] ?? [];

        $citations = [];
        foreach ($validated['citations'] ?? [] as $citation) {
            $citations[] = new CitationDTO(
                evidenceId: (string) ($citation['evidence_id'] ?? ''),
                chunkId: isset($citation['chunk_id']) ? (string) $citation['chunk_id'] : null,
                sourceName: (string) ($citation['source_name'] ?? ''),
                sourceUri: (string) ($citation['source_uri'] ?? ''),
                exactQuote: (string) ($citation['exact_quote'] ?? $citation['evidence_snippet'] ?? ''),
                contextSnippet: (string) ($citation['context_snippet'] ?? $citation['evidence_snippet'] ?? ''),
                pageNumber: isset($citation['page_number']) ? (int) $citation['page_number'] : null,
                timestampSeconds: isset($citation['timestamp_seconds']) ? (float) $citation['timestamp_seconds'] : null,
                authorityTier: (string) ($citation['authority_tier'] ?? 'community_discussion'),
                isVerified: (bool) ($citation['is_verified'] ?? true),
            );
        }

        $conflicts = [];
        foreach ($validated['conflicts'] ?? [] as $conflict) {
            $conflicts[] = new ConflictDTO(
                topic: (string) ($conflict['topic'] ?? 'conflict'),
                conflictingClaims: array_values($conflict['conflicting_claims'] ?? $conflict['claims'] ?? []),
                recommendedAction: (string) ($conflict['recommended_action'] ?? $conflict['action'] ?? 'Escalate to an administrator.'),
            );
        }

        $stateValue = (string) ($validated['state'] ?? AnswerState::INSUFFICIENT_EVIDENCE->value);

        return new self(
            query: (string) ($payload['query'] ?? ''),
            detectedLanguage: (string) ($payload['detected_language'] ?? 'en'),
            state: AnswerState::tryFrom($stateValue) ?? AnswerState::INSUFFICIENT_EVIDENCE,
            answer: (string) ($validated['answer'] ?? ''),
            confidenceScore: (float) ($validated['confidence_score'] ?? 0),
            citations: $citations,
            conflicts: $conflicts,
            needsEscalation: (bool) ($validated['needs_escalation'] ?? false),
            escalationReason: isset($validated['escalation_reason']) ? (string) $validated['escalation_reason'] : null,
            executionTimeMs: (float) ($payload['execution_time_ms'] ?? 0),
            totalChunksRetrieved: (int) ($payload['total_chunks_retrieved'] ?? 0),
        );
    }
}
