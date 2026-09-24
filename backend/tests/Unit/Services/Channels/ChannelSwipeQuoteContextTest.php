<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\DTOs\Channels\InboundMessage;
use App\Services\Channels\ChannelCommandAccess;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\ChannelSwipeQuoteContext;
use Tests\TestCase;

class ChannelSwipeQuoteContextTest extends TestCase
{
    public function test_folds_quoted_member_message_into_knowledge_query(): void
    {
        $ctx = new ChannelSwipeQuoteContext(
            new ChannelCommandAccess,
            new ChannelConversationService,
        );

        $message = new InboundMessage(
            channel: 'whatsapp_zavu',
            externalUserId: '+1',
            text: 'When is that?',
            raw: [
                'reply_to_bot' => false,
                'quoted_text' => 'Victor tests Thursday morning',
            ],
        );

        $folded = $ctx->foldIntoKnowledgeQuery($message, 'When is that?');

        $this->assertStringContainsString('Victor tests Thursday morning', $folded);
        $this->assertStringContainsString('When is that?', $folded);
    }

    public function test_skips_fold_when_replying_to_bot_follow_up(): void
    {
        $ctx = new ChannelSwipeQuoteContext(
            new ChannelCommandAccess,
            new ChannelConversationService,
        );

        $message = new InboundMessage(
            channel: 'whatsapp_zavu',
            externalUserId: '+1',
            text: 'Are you sure?',
            raw: [
                'reply_to_bot' => true,
                'quoted_text' => 'Testing is tomorrow.',
            ],
        );

        $folded = $ctx->foldIntoKnowledgeQuery($message, 'Are you sure?');

        $this->assertSame('Are you sure?', $folded);
    }
}
