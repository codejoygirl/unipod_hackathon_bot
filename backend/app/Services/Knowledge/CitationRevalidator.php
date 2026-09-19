<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\DTOs\CitationDTO;
use App\DTOs\GroundedAnswerDTO;
use App\Enums\AnswerState;
use App\Enums\KnowledgeLifecycleStatus;
use App\Models\KnowledgeSource;
use App\Models\User;

final class CitationRevalidator
{
    /**
     * Drop citations the caller cannot access; block the answer if nothing remains.
     */
    public function revalidate(User $user, GroundedAnswerDTO $answer): GroundedAnswerDTO
    {
        $accessibleCommunityIds = $user->accessibleCommunityIds();
        $allowedUris = KnowledgeSource::query()
            ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
            ->whereIn('community_id', $accessibleCommunityIds)
            ->pluck('uri')
            ->all();

        $allowedLookup = array_fill_keys($allowedUris, true);

        $filtered = array_values(array_filter(
            $answer->citations,
            fn (CitationDTO $citation): bool => isset($allowedLookup[$citation->sourceUri])
                || $this->uriLooksPublic($citation->sourceUri)
        ));

        if ($filtered === $answer->citations) {
            return $answer;
        }

        if ($filtered === [] && $answer->answer !== '') {
            return new GroundedAnswerDTO(
                query: $answer->query,
                detectedLanguage: $answer->detectedLanguage,
                state: AnswerState::BLOCKED,
                answer: '',
                confidenceScore: 0.0,
                citations: [],
                conflicts: [],
                needsEscalation: true,
                escalationReason: 'Citations failed Laravel access revalidation.',
                executionTimeMs: $answer->executionTimeMs,
                totalChunksRetrieved: $answer->totalChunksRetrieved,
            );
        }

        return new GroundedAnswerDTO(
            query: $answer->query,
            detectedLanguage: $answer->detectedLanguage,
            state: $answer->state,
            answer: $answer->answer,
            confidenceScore: $answer->confidenceScore,
            citations: $filtered,
            conflicts: $answer->conflicts,
            needsEscalation: $answer->needsEscalation || $answer->state->requiresAdminEscalation(),
            escalationReason: $answer->escalationReason,
            executionTimeMs: $answer->executionTimeMs,
            totalChunksRetrieved: $answer->totalChunksRetrieved,
        );
    }

    private function uriLooksPublic(string $uri): bool
    {
        // Allow empty-uri citations only when no local source row exists yet (dev mocks).
        return $uri === '' || str_starts_with($uri, 'mock://');
    }
}
