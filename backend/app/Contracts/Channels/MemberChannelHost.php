<?php

declare(strict_types=1);

namespace App\Contracts\Channels;

use App\DTOs\Channels\InboundMessage;
use App\Models\Community;
use App\Models\User;

/**
 * Adapter-specific hooks for shared member/admin WhatsApp command handling.
 */
interface MemberChannelHost
{
    public function channelName(): string;

    public function resolveUser(): ?User;

    public function resolveLinkedCommunity(InboundMessage $message): ?Community;

    public function memberPhoneForWeb(InboundMessage $message): ?string;

    public function chatType(InboundMessage $message): string;

    /**
     * @return array{0: string, 1: string|null, 2: string|null} channel, adminPhone, adminName
     */
    public function adminActor(InboundMessage $message): array;

    public function knowledgeImportUriPrefix(): string;

    public function knowledgeShareUriPrefix(): string;
}
