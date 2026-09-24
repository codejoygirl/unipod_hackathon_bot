<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Contracts\Channels\MemberChannelHost;
use App\DTOs\Channels\InboundMessage;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shared slash + admin command handling for WhatsApp-class channels (Zavu, spike).
 */
final class MemberChannelPipeline
{
    public function __construct(
        private readonly ChannelListenGate $listenGate,
        private readonly ChannelCommandAccess $commandAccess,
        private readonly AdminKnowledgeDesk $knowledgeDesk,
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly SpikeEscalationNotifier $escalationNotifier,
        private readonly ChannelConversationService $conversation,
        private readonly AdminMessageKnowledgeIndexer $adminIndexer,
        private readonly WhatsAppKnowledgeImportService $whatsAppImport,
    ) {}

    /**
     * Admin-only flows before generic slash routing (escalation cards, /reply, etc.).
     */
    public function tryAdminInbound(MemberChannelHost $host, InboundMessage $message, bool $isAdmin): ?string
    {
        if ($isAdmin) {
            $cardReply = $this->tryAdminEscalationCardSwipe($host, $message);
            if ($cardReply !== null) {
                return $cardReply;
            }

            $draftPublish = $this->tryAdminDraftPublishSwipe($host, $message);
            if ($draftPublish !== null) {
                return $draftPublish;
            }

            $adminCmd = $this->escalationNotifier->tryAdminCommand(
                $message->text,
                $host->channelName(),
                ...$host->adminActor($message),
            );
            if ($adminCmd !== null) {
                return (string) ($adminCmd['reply'] ?? 'Done.');
            }
        } elseif ($this->commandAccess->isAdminOnlyCommand($message->text)) {
            return $this->commandAccess->adminOnlyDenial('whatsapp');
        }

        return null;
    }

    /**
     * Member + admin slash commands. Null = not a command (continue to free text).
     */
    public function trySlashInbound(
        MemberChannelHost $host,
        InboundMessage $message,
        bool $isAdmin,
    ): ?string {
        if ($this->listenGate->startsWithHelpOrStart($message->text)) {
            return $this->conversation->helpTextFor(
                'whatsapp',
                'whatsapp',
                $isAdmin,
                $host->chatType($message),
                $host->memberPhoneForWeb($message),
                $host->channelName(),
            );
        }

        if ($this->listenGate->startsWithImportOrExport($message->text)) {
            return $this->handleAdminImport($host, $message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'share')) {
            return $this->handleShare($host, $message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'features')) {
            return $this->handleAdminKnowledgeDesk($host, $message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'feature')) {
            return $this->handleFeature($host, $message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'assets')) {
            return $this->handleMemberAssets($host, $message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'asset')) {
            return $this->handleAdminAsset($host, $message);
        }

        if ($this->listenGate->startsWithSlashCommand($message->text, 'publish')
            || $this->listenGate->startsWithSlashCommand($message->text, 'unpublish')
            || $this->listenGate->startsWithSlashCommand($message->text, 'archive')
            || $this->listenGate->startsWithSlashCommand($message->text, 'knowledge')
            || $this->listenGate->startsWithSlashCommand($message->text, 'kb')) {
            return $this->handleAdminKnowledgeDesk($host, $message);
        }

        return null;
    }

    public function tryAdminAutoIndex(MemberChannelHost $host, InboundMessage $message): ?string
    {
        $user = $host->resolveUser();
        $community = $host->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return null;
        }

        $result = $this->adminIndexer->maybeIndex(
            $host->channelName(),
            $message->text,
            $user,
            $community,
            $message->externalUserId,
        );

        return ($result['indexed'] ?? false) ? ($result['reply'] ?? null) : null;
    }

    private function handleMemberAssets(MemberChannelHost $host, InboundMessage $message): string
    {
        $community = $host->resolveLinkedCommunity($message);
        if ($community === null) {
            return 'Please link a community first with /join, then try /assets again.';
        }

        $arg = $this->listenGate->slashCommandBody($message->text, 'assets');

        return app(ProgramAssetRegistrar::class)->memberCatalogReply($community, $arg, 'whatsapp');
    }

    private function handleShare(MemberChannelHost $host, InboundMessage $message): string
    {
        $user = $host->resolveUser();
        $community = $host->resolveLinkedCommunity($message);

        if ($user === null || $community === null) {
            return 'Please link a community first with /join, then try /share again.';
        }

        if (! $user->belongsToCommunity($community->id)) {
            return 'You are not a member of that community in '.$this->conversation->botDisplayName().'.';
        }

        $body = $this->listenGate->slashCommandBody($message->text, 'share');

        if ($body === '') {
            return "Share something the community should know, like:\n"
                ."/share Water off tomorrow morning\n\n"
                .'An admin will review it before '
                .$this->conversation->botDisplayName().' can use it in answers.';
        }

        $source = $this->lifecycle->import($user, [
            'tenant_id' => $community->tenant_id,
            'community_id' => $community->id,
            'name' => 'Shared WhatsApp note',
            'uri' => $host->knowledgeShareUriPrefix().Str::ulid(),
            'source_type' => 'whatsapp',
            'content' => $body,
            'metadata' => [
                'channel' => $host->channelName(),
                'from' => $message->externalUserId,
            ],
        ]);

        $this->lifecycle->submitForReview($user, $source);

        $fromName = trim((string) ($message->raw['from_name'] ?? ''));
        $fromPhone = trim((string) ($message->raw['from_phone'] ?? ''));
        $notify = $this->escalationNotifier->notifyShareReview(
            channel: $host->channelName(),
            from: $message->externalUserId,
            content: $body,
            knowledgeSourceId: (string) $source->id,
            communityId: $community->id,
            communityName: $community->name,
            fromName: $fromName !== '' ? $fromName : null,
            fromPhone: $fromPhone !== '' ? $fromPhone : null,
        );

        Log::info('member_channel.share_draft', [
            'channel' => $host->channelName(),
            'knowledge_id' => $source->id,
            'notified' => $notify['notified'],
        ]);

        return $this->conversation->shareQueuedReply((bool) ($notify['notified'] ?? false));
    }

    private function handleFeature(MemberChannelHost $host, InboundMessage $message): string
    {
        $community = $host->resolveLinkedCommunity($message);
        if ($community === null) {
            return 'Please link a community first with '
                .$this->conversation->highlightCommand('/join', 'whatsapp')
                .', then try '
                .$this->conversation->highlightCommand('/feature', 'whatsapp')
                .' again.';
        }

        $body = $this->listenGate->slashCommandBody($message->text, 'feature');

        if ($body === '') {
            return $this->conversation->featureUsageReply('whatsapp');
        }

        $fromName = trim((string) ($message->raw['from_name'] ?? ''));
        $fromPhone = trim((string) ($message->raw['from_phone'] ?? ''));
        $mentionRows = is_array($message->raw['mentions'] ?? null) ? $message->raw['mentions'] : [];
        $body = $this->conversation->preferGreenMentionTags($body, $mentionRows);
        $notify = $this->escalationNotifier->notifyFeatureRequest(
            channel: $host->channelName(),
            from: $message->externalUserId,
            content: $body,
            communityId: $community->id,
            communityName: $community->name,
            fromName: $fromName !== '' ? $fromName : null,
            fromPhone: $fromPhone !== '' ? $fromPhone : null,
            chatType: $host->chatType($message),
            chatId: trim((string) ($message->raw['chat_id'] ?? '')) ?: null,
            messageId: $message->messageId,
        );

        Log::info('member_channel.feature_request', [
            'channel' => $host->channelName(),
            'ref' => $notify['ref'] ?? null,
            'notified' => $notify['notified'],
        ]);

        return $this->conversation->featureQueuedReply((bool) ($notify['notified'] ?? false));
    }

    private function handleAdminImport(MemberChannelHost $host, InboundMessage $message): string
    {
        if (! $this->commandAccess->isAdmin($host->channelName(), $message->externalUserId)) {
            return $this->commandAccess->adminOnlyDenial('whatsapp');
        }

        $user = $host->resolveUser();
        $community = $host->resolveLinkedCommunity($message);

        if ($user === null || $community === null) {
            return 'Link a community with a minted JOIN token before /import.';
        }

        if (! $user->belongsToCommunity($community->id)) {
            return 'You are not a member of that community in '.$this->conversation->botDisplayName().'.';
        }

        return $this->whatsAppImport->adminImportFromInbound(
            $host->channelName(),
            $message,
            $user,
            $community,
            $host->knowledgeImportUriPrefix(),
        );
    }

    /**
     * Admin sent /import (or legacy EXPORT) with only a attachment — import before voice/image routing.
     */
    public function isAdminImportWithAttachment(MemberChannelHost $host, InboundMessage $message): bool
    {
        return $this->whatsAppImport->isAdminImportWithAttachment(
            $host->channelName(),
            $message,
            $this->commandAccess,
            $this->listenGate,
        );
    }

    public function handleAdminImportWithAttachment(MemberChannelHost $host, InboundMessage $message): string
    {
        $user = $host->resolveUser();
        $community = $host->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return 'Link a community with a minted JOIN token before /import.';
        }

        if (! $user->belongsToCommunity($community->id)) {
            return 'You are not a member of that community in '.$this->conversation->botDisplayName().'.';
        }

        return $this->whatsAppImport->adminImportFromInbound(
            $host->channelName(),
            $message,
            $user,
            $community,
            $host->knowledgeImportUriPrefix(),
        );
    }

    private function handleAdminKnowledgeDesk(MemberChannelHost $host, InboundMessage $message): string
    {
        if (! $this->commandAccess->isAdmin(
            $host->channelName(),
            $message->externalUserId,
            array_merge(is_array($message->raw) ? $message->raw : [], ['text' => $message->text]),
        )) {
            return $this->commandAccess->adminOnlyDenial('whatsapp');
        }

        $user = $host->resolveUser();
        $community = $host->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return 'Link a community with a minted JOIN token before using knowledge admin commands.';
        }

        $result = $this->knowledgeDesk->tryHandle($message->text, $user, $community, 'whatsapp');
        if ($result === null) {
            return 'Try /publish, /knowledge, or /features.';
        }

        return (string) ($result['reply'] ?? 'Done.');
    }

    private function handleAdminAsset(MemberChannelHost $host, InboundMessage $message): string
    {
        if (! $this->commandAccess->isAdmin(
            $host->channelName(),
            $message->externalUserId,
            array_merge(is_array($message->raw) ? $message->raw : [], ['text' => $message->text]),
        )) {
            return $this->commandAccess->adminOnlyDenial('whatsapp');
        }

        $user = $host->resolveUser();
        $community = $host->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return 'Link a community with a minted JOIN token before '
                .$this->conversation->highlightCommand('/asset', 'whatsapp').'.';
        }

        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            $host->channelName(),
            $message->text,
            $user,
            $community,
            'whatsapp',
        );

        return (string) ($result['reply'] ?? 'Done.');
    }

    /**
     * Swipe-reply on an escalation / share / feature card (same flow as WhatsApp web spike).
     */
    private function tryAdminEscalationCardSwipe(MemberChannelHost $host, InboundMessage $message): ?string
    {
        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
        $quotedMsgId = trim((string) ($message->raw['quoted_message_id'] ?? ''));
        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $replyToBot) {
            return null;
        }
        if ($quoted === '' && $quotedMsgId === '') {
            return null;
        }

        $ref = null;
        if ($quotedMsgId !== '') {
            $escId = $this->escalationNotifier->findEscalationIdByWhatsAppMessageId($quotedMsgId);
            if (is_string($escId) && $escId !== '') {
                $record = Cache::get('spike_escalation:'.$escId);
                if (is_array($record)) {
                    $ref = strtoupper(trim((string) ($record['ref'] ?? '')));
                }
            }
        }
        if ($ref === null || $ref === '') {
            $ref = $this->escalationNotifier->extractRefFromCardText($quoted) ?? '';
        }

        $looksLikeCard = $this->commandAccess->looksLikeEscalationCardReply(
            is_array($message->raw) ? $message->raw : [],
        );

        if ($ref === '') {
            if ($looksLikeCard) {
                return "I couldn't read the Request ID from that swipe-reply (WhatsApp often truncates the quote).\n\n"
                    ."Copy it from the card and send:\n"
                    ."```\n"
                    ."/reply W7X1YT Your answer here\n"
                    .'```';
            }

            return null;
        }

        $body = trim($message->text);
        if ($body === '') {
            return null;
        }

        [$actorId, $actorPhone] = $host->adminActor($message);
        $channel = $host->channelName();

        if (preg_match('/^\/?(approve|decline|reject)(?:\s+(\S+))?$/iu', $body, $m) === 1) {
            $action = strtolower($m[1]);
            $cmd = $this->escalationNotifier->tryAdminCommand(
                "/{$action} {$ref}",
                $channel,
                $actorId,
                $actorPhone,
            );

            return is_array($cmd) ? (string) ($cmd['reply'] ?? 'Done.') : null;
        }

        if (preg_match('/^\/?(blacklist|unblacklist)(?:\s+(\S+))?$/iu', $body, $m) === 1) {
            $action = strtolower($m[1]);
            $cmd = $this->escalationNotifier->tryAdminCommand(
                "/{$action} {$ref}",
                $channel,
                $actorId,
                $actorPhone,
            );

            return is_array($cmd) ? (string) ($cmd['reply'] ?? 'Done.') : null;
        }

        if (preg_match('/^\/?reply\b/iu', $body) === 1) {
            $rest = trim((string) preg_replace('/^\/?reply\s+/iu', '', $body));
            if ($rest === '') {
                return "Type the answer in this swipe-reply, or:\n```\n/reply {$ref} Your answer here\n```";
            }
            if (str_starts_with(strtoupper($rest), $ref.' ')) {
                return null;
            }
            $cmd = $this->escalationNotifier->tryAdminCommand(
                "/reply {$ref} {$rest}",
                $channel,
                $actorId,
                $actorPhone,
            );

            return is_array($cmd) ? (string) ($cmd['reply'] ?? 'Done.') : null;
        }

        if (preg_match('/^\/[a-z]+/iu', $body) === 1) {
            return null;
        }

        $cmd = $this->escalationNotifier->tryAdminCommand(
            "/reply {$ref} {$body}",
            $channel,
            $actorId,
            $actorPhone,
        );

        return is_array($cmd) ? (string) ($cmd['reply'] ?? 'Done.') : null;
    }

    private function tryAdminDraftPublishSwipe(MemberChannelHost $host, InboundMessage $message): ?string
    {
        $replyToBot = filter_var($message->raw['reply_to_bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $replyToBot) {
            return null;
        }

        $quoted = trim((string) ($message->raw['quoted_text'] ?? ''));
        if ($quoted === '' || ! $this->knowledgeDesk->isDraftCardText($quoted)) {
            return null;
        }

        if (! $this->knowledgeDesk->isPublishConfirmText($message->text)) {
            return null;
        }

        $short = $this->knowledgeDesk->extractShortIdFromDraftCard($quoted);
        if ($short === null || $short === '') {
            return "I couldn't read the draft ID from that swipe-reply.\n\n"
                ."Send:\n```\n/publish latest\n```";
        }

        $user = $host->resolveUser();
        $community = $host->resolveLinkedCommunity($message);
        if ($user === null || $community === null) {
            return 'Link a community with a minted JOIN token before publishing.';
        }

        $result = $this->knowledgeDesk->tryHandle('/publish '.$short, $user, $community, 'whatsapp');

        return (string) ($result['reply'] ?? 'Published.');
    }
}
