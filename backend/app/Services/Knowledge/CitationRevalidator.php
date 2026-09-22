<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\DTOs\CitationDTO;
use App\DTOs\GroundedAnswerDTO;
use App\Enums\AnswerState;
use App\Enums\KnowledgeLifecycleStatus;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class CitationRevalidator
{
    /**
     * Drop citations the caller cannot access; block the answer if nothing remains.
     */
    public function revalidate(User $user, GroundedAnswerDTO $answer): GroundedAnswerDTO
    {
        $accessibleCommunityIds = $user->accessibleCommunityIds();
        $sources = KnowledgeSource::query()
            ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
            ->whereIn('community_id', $accessibleCommunityIds)
            ->get(['id', 'uri']);

        $allowedLookup = array_fill_keys(
            $sources->pluck('uri')->filter()->map(fn ($u) => (string) $u)->all(),
            true,
        );
        $allowedIds = array_fill_keys(
            $sources->pluck('id')->map(fn ($id) => (string) $id)->all(),
            true,
        );

        $filtered = array_values(array_filter(
            $answer->citations,
            fn (CitationDTO $citation): bool => $this->citationIsAccessible(
                $citation->sourceUri,
                $allowedLookup,
                $allowedIds,
            )
        ));

        if ($filtered === $answer->citations) {
            return $answer;
        }

        if ($filtered === [] && $answer->answer !== '') {
            Log::warning('citation.revalidation.blocked', [
                'citation_uris' => array_values(array_unique(array_map(
                    fn (CitationDTO $c): string => $c->sourceUri,
                    $answer->citations,
                ))),
                'allowed_uri_sample' => array_slice(array_keys($allowedLookup), 0, 5),
                'answer_len' => mb_strlen($answer->answer),
            ]);

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

    /**
     * @param  array<string, true>  $allowedLookup
     * @param  array<string, true>  $allowedIds
     */
    private function citationIsAccessible(string $uri, array $allowedLookup, array $allowedIds): bool
    {
        if (isset($allowedLookup[$uri])) {
            return true;
        }

        // Empty / mock: retrieval already scoped by community_id.
        if ($uri === '' || str_starts_with($uri, 'mock://')) {
            return true;
        }

        // Chunked ingest: AI stores `{base}/part-N` while Laravel keeps `{base}`.
        foreach (array_keys($allowedLookup) as $allowed) {
            if ($allowed === '') {
                continue;
            }
            $base = rtrim($allowed, '/');
            if (str_starts_with($uri, $base.'/part-') || str_starts_with($uri, $base.'#')) {
                return true;
            }
        }

        // Legacy placeholder scheme — accept when id is a published accessible source.
        if (preg_match('#^community://sources/([A-Za-z0-9_-]+)$#', $uri, $m) === 1) {
            return isset($allowedIds[$m[1]]);
        }

        return false;
    }
}
