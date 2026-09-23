<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnswerState;
use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiServiceClient;
use App\Services\Channels\ChannelConversationService;
use App\Services\Knowledge\CitationRevalidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssistantController extends Controller
{
    public function __construct(
        protected AiServiceClient $aiClient,
        protected CitationRevalidator $citationRevalidator,
        protected ChannelConversationService $conversationContext,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'community_ids' => ['nullable', 'array'],
            'community_ids.*' => ['ulid'],
            'target_language' => ['nullable', 'string', 'max:10'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
        ]);

        $user = $request->user();
        $requested = array_values(array_unique($validated['community_ids'] ?? []));
        $accessible = $user->accessibleCommunityIds();

        // DEV FALLBACK — remove before any multi-tenant deployment.
        // A fresh account that has not been placed in a community yet can still chat, so
        // scope widens to every community that exists. This deliberately ignores
        // membership; it exists so signing up does not dead-end the chat.
        if ($accessible === [] && $requested === []) {
            $accessible = Community::query()->pluck('id')->all();
        }

        // Nothing in the UI asks a member to pick a community any more, so an omitted
        // scope means "everything this member can read". An explicit scope is still
        // intersected with their access: the caller can narrow, never widen.
        $allowed = $requested === []
            ? $accessible
            : array_values(array_intersect($requested, $accessible));

        abort_if($allowed === [], 403, 'No communities exist yet, so there is nothing to answer from.');

        $communities = Community::query()->whereIn('id', $allowed)->get();
        $tenantIds = $communities->pluck('tenant_id')->unique()->values();
        abort_unless($tenantIds->count() === 1, 422, 'Communities must belong to a single tenant.');

        $tenantId = (string) $tenantIds->first();

        $conversation = $this->resolveConversation(
            $request,
            $validated['conversation_id'] ?? null,
            $tenantId,
        );

        // Prior turns give retrieval the context a follow-up like "and where?" needs.
        // Reuses the channel envelope the model prompt already parses, so no new
        // prompt surface is introduced. Loaded before this turn is stored.
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(static fn (Message $message): array => [
                'role' => (string) $message->role,
                'text' => (string) $message->content,
            ])
            ->all();

        $retrievalQuery = $history === []
            ? $validated['query']
            : $this->conversationContext->buildKnowledgeQuery($validated['query'], $history);

        $result = $this->aiClient->askGroundedQuestion(
            query: $retrievalQuery,
            tenantId: $tenantId,
            communityIds: $allowed,
            targetLanguage: $validated['target_language'] ?? null,
            // Web chat wants the signal, not the full channel-style write-up.
            responseStyle: 'concise',
        );

        // Citation revalidation can be switched off. When on, it drops any citation the
        // member cannot be proven to reach and BLOCKS the whole answer if nothing survives —
        // which is correct for production, and a dead end while the corpus is still thin.
        if (config('ai_service.revalidate_citations')) {
            $result = $this->citationRevalidator->revalidate($user, $result);
        }

        $needsEscalation = $result->needsEscalation || $result->state->requiresAdminEscalation();
        $answerText = $result->answer;

        // Retrieval sometimes has nothing to stand on: a greeting, or a question the corpus
        // does not cover. The channel adapters answer conversationally in that case rather
        // than leaving a dead end, so the web does the same when this is enabled.
        if ($answerText === '' && config('ai_service.conversational_fallback')) {
            $reply = $this->aiClient->conversationalReply(
                message: $validated['query'],
                mode: $result->state === AnswerState::UNKNOWN ? 'out_of_scope' : 'social',
                targetLanguage: $validated['target_language'] ?? null,
            );

            if ($reply !== '') {
                $answerText = $reply;
                $needsEscalation = false;
            }
        }

        $answer = [
            'state' => $result->state->value,
            'answer' => $answerText,
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
        ];

        // Store the bare question, never the retrieval envelope: the thread shows
        // what the member typed, and the envelope would poison future history.
        $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => $validated['query'],
        ]);

        $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => $answerText,
            'answer' => $answer,
        ]);

        $conversation->forceFill([
            'title' => $conversation->title ?? Str::limit($validated['query'], 60),
            'last_message_at' => now(),
        ])->save();

        return response()->json([
            'data' => $answer,
            'meta' => [
                'latency_ms' => $result->executionTimeMs,
                'chunks_evaluated' => $result->totalChunksRetrieved,
                'tenant_id' => $tenantId,
                'community_ids' => $allowed,
                'conversation_id' => $conversation->id,
            ],
        ]);
    }

    /**
     * Continue the given thread, or start one for this member.
     */
    private function resolveConversation(Request $request, ?string $conversationId, string $tenantId): Conversation
    {
        if ($conversationId === null || $conversationId === '') {
            return Conversation::query()->create([
                'user_id' => $request->user()->id,
                'tenant_id' => $tenantId,
            ]);
        }

        $conversation = Conversation::query()
            ->where('user_id', $request->user()->id)
            ->find($conversationId);

        abort_if($conversation === null, 404, 'Conversation not found.');

        return $conversation;
    }
}
