<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Models\Community;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Model-gated auto-publish of durable admin messages into community knowledge.
 * Never indexes chit-chat / questions / commands — the AI decides.
 */
final class AdminMessageKnowledgeIndexer
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly ChannelConversationService $conversation,
    ) {}

    /**
     * @return array{indexed: bool, knowledge_id: string|null, reply: string|null}
     */
    public function maybeIndex(
        string $channel,
        string $text,
        User $user,
        Community $community,
        string $from,
    ): array {
        $text = trim($text);
        // Structural only: never auto-index slash/JOIN command lines.
        if ($text === ''
            || str_starts_with($text, '/')
            || str_starts_with(strtoupper($text), 'JOIN-')) {
            return ['indexed' => false, 'knowledge_id' => null, 'reply' => null];
        }

        // Thin offline guard only — meaning stays with the model.
        if (mb_strlen($text) < 12) {
            return ['indexed' => false, 'knowledge_id' => null, 'reply' => null];
        }

        $ctx = $this->conversation->communityModelContext(
            $community->name,
            $community->description,
        );

        $indexable = $this->aiClient->isAdminMessageIndexable(
            $text,
            $ctx['name'],
            $ctx['scope'],
        );

        if ($indexable !== true) {
            return ['indexed' => false, 'knowledge_id' => null, 'reply' => null];
        }

        try {
            $source = $this->lifecycle->import($user, [
                'tenant_id' => $community->tenant_id,
                'community_id' => $community->id,
                'name' => 'Admin update '.now()->format('Y-m-d H:i'),
                'uri' => $channel.'://admin-auto/'.(string) Str::ulid(),
                'source_type' => 'markdown',
                'authority_tier' => 'official_announcement',
                'content' => $text,
                'metadata' => [
                    'channel' => $channel,
                    'from' => $from,
                    'origin' => 'admin_auto_index',
                ],
            ]);
            $this->lifecycle->submitForReview($user, $source);
            $published = $this->lifecycle->publish($user, $source->fresh() ?? $source);

            Log::info('channel.admin_auto_index.published', [
                'knowledge_id' => $published->id,
                'channel' => $channel,
                'community_id' => $community->id,
            ]);

            return [
                'indexed' => true,
                'knowledge_id' => (string) $published->id,
                'reply' => $this->conversation->adminKnowledgeIndexedAck(),
            ];
        } catch (\Throwable $e) {
            Log::error('channel.admin_auto_index.failed', [
                'channel' => $channel,
                'community_id' => $community->id,
                'error' => $e->getMessage(),
            ]);

            return ['indexed' => false, 'knowledge_id' => null, 'reply' => null];
        }
    }
}
