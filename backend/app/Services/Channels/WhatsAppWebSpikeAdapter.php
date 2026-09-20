<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Contracts\Channels\ChannelAdapter;
use App\DTOs\Channels\InboundMessage;
use App\Models\Community;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use App\Services\Knowledge\CitationRevalidator;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class WhatsAppWebSpikeAdapter implements ChannelAdapter
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly CitationRevalidator $citationRevalidator,
        private readonly KnowledgeLifecycleService $lifecycle,
    ) {}

    public function channelName(): string
    {
        return 'whatsapp_web_spike';
    }

    public function handleInbound(InboundMessage $message): ?string
    {
        if ($message->text === '') {
            return null;
        }

        $upper = strtoupper($message->text);

        if (str_starts_with($upper, 'JOIN-')) {
            return $this->handleJoin($message);
        }

        if (str_starts_with($upper, 'EXPORT')) {
            return $this->handleExportStub($message);
        }

        return $this->handleAsk($message);
    }

    private function handleJoin(InboundMessage $message): string
    {
        // Strip "JOIN-" case-insensitively.
        $token = trim(substr($message->text, 5));
        $communityId = Cache::pull('wa_web_spike_join:'.strtolower($token))
            ?? (str_starts_with($token, '01') ? $token : null);

        if ($communityId === null || ! Community::query()->whereKey($communityId)->exists()) {
            return 'JOIN failed: invalid or expired token. Ask an admin for a new JOIN link.';
        }

        $user = $this->resolveUser($message->externalUserId);
        if ($user === null) {
            return 'JOIN failed: no spike user configured (set WHATSAPP_WEB_SPIKE_DEFAULT_USER_EMAIL).';
        }

        Cache::forever($this->linkCacheKey($message->externalUserId), [
            'user_id' => $user->id,
            'community_id' => $communityId,
        ]);

        return 'Linked to community '.$communityId.'. Ask me a question about community knowledge.';
    }

    private function handleAsk(InboundMessage $message): string
    {
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $user = $this->resolveUser($message->externalUserId);
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('whatsapp_web_spike.default_community_id');

        if ($user === null) {
            return 'Spike identity missing. Set WHATSAPP_WEB_SPIKE_DEFAULT_USER_EMAIL to demo@zak.test.';
        }

        if ($communityId === '') {
            return 'No community linked. Send JOIN-{communityId} first (or set WHATSAPP_WEB_SPIKE_DEFAULT_COMMUNITY_ID).';
        }

        if (! $user->belongsToCommunity($communityId)) {
            return 'You are not a member of that community in Zak.';
        }

        $community = Community::query()->find($communityId);
        if ($community === null) {
            return 'Community not found.';
        }

        $result = $this->aiClient->askGroundedQuestion(
            query: $message->text,
            tenantId: $community->tenant_id,
            communityIds: [$communityId],
            targetLanguage: 'en',
        );

        $result = $this->citationRevalidator->revalidate($user, $result);

        if ($result->answer === '') {
            return $result->escalationReason
                ?? ('['.$result->state->value.'] No grounded answer.');
        }

        $footer = '';
        if ($result->citations !== []) {
            $c = $result->citations[0];
            $footer = "\n\n— ".$c->sourceName.' ('.$result->state->value.')';
        }

        return $result->answer.$footer;
    }

    private function handleExportStub(InboundMessage $message): string
    {
        $user = $this->resolveUser($message->externalUserId);
        $link = Cache::get($this->linkCacheKey($message->externalUserId));
        $communityId = is_array($link)
            ? (string) ($link['community_id'] ?? '')
            : (string) config('whatsapp_web_spike.default_community_id');

        if ($user === null || $communityId === '') {
            return 'Link a community with JOIN-… before EXPORT.';
        }

        $community = Community::query()->findOrFail($communityId);
        $body = $message->text;
        if (str_starts_with(strtoupper($body), 'EXPORT')) {
            $body = trim(substr($body, strlen('EXPORT')));
        }

        if ($body === '') {
            return 'Usage: EXPORT <pasted chat text>';
        }

        $source = $this->lifecycle->import($user, [
            'tenant_id' => $community->tenant_id,
            'community_id' => $communityId,
            'name' => 'WA Web spike forward',
            'uri' => 'whatsapp-web-spike://forward/'.Str::ulid(),
            'source_type' => 'whatsapp',
            'content' => $body,
            'metadata' => [
                'channel' => 'whatsapp_web_spike',
                'from' => $message->externalUserId,
            ],
        ]);

        Log::info('whatsapp_web_spike.export_draft', ['knowledge_id' => $source->id]);

        return 'Saved as draft knowledge '.$source->id.' (submit-review → publish in Laravel).';
    }

    private function resolveUser(string $phoneOrJid): ?User
    {
        $email = (string) config('whatsapp_web_spike.default_user_email');
        if ($email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    private function linkCacheKey(string $externalUserId): string
    {
        return 'wa_web_spike_link:'.sha1($externalUserId);
    }

    /**
     * Admin helper: mint a JOIN token for a community (spike).
     */
    public static function mintJoinToken(string $communityId, ?int $ttlHours = null): string
    {
        $ttl = $ttlHours ?? (int) config('whatsapp_web_spike.join_token_ttl_hours', 72);
        $token = Str::lower((string) Str::ulid());
        Cache::put('wa_web_spike_join:'.$token, $communityId, now()->addHours($ttl));

        return 'JOIN-'.$token;
    }
}
