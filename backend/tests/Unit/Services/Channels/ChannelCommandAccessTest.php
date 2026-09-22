<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\Services\Channels\ChannelCommandAccess;
use Tests\TestCase;

class ChannelCommandAccessTest extends TestCase
{
    public function test_whatsapp_admin_phone_match(): void
    {
        config(['whatsapp_web_spike.admin_phones' => ['2348011111111', '08022222222']]);
        $access = new ChannelCommandAccess;

        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '2348011111111'));
        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '8022222222'));
        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '2348022222222')); // intl form of 08022222222
        $this->assertFalse($access->isAdmin('whatsapp_web_spike', '15551234567'));
    }

    public function test_whatsapp_admin_matches_via_from_phone_when_sender_is_lid(): void
    {
        config(['whatsapp_web_spike.admin_phones' => ['2348117084647']]);
        $access = new ChannelCommandAccess;

        $this->assertFalse($access->isAdmin('whatsapp_web_spike', '276694879498269'));
        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '275694879498269', [
            'from_phone' => '2348117084647',
        ]));
        // LID remembered for later commands without from_phone
        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '275694879498269'));
    }

    public function test_whatsapp_admin_lid_accepted_for_private_admin_command_with_single_admin(): void
    {
        config([
            'whatsapp_web_spike.admin_phones' => ['2347041131371', '2348117084647'],
            'whatsapp_web_spike.bot_number' => '2347041131371',
        ]);
        $access = new ChannelCommandAccess;

        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '999888777666555', [
            'chat_type' => 'private',
            'text' => '/reply W7X1YT yes we are',
        ]));
    }

    public function test_whatsapp_admin_lid_accepted_for_swipe_reply_to_escalation_card(): void
    {
        config([
            'whatsapp_web_spike.admin_phones' => ['2347041131371', '2348117084647'],
            'whatsapp_web_spike.bot_number' => '2347041131371',
        ]);
        $access = new ChannelCommandAccess;

        $card = "*Zak Bot needs a quick hand.*\n\nRequest ID: 9D5GWS\n\nName: Abdulsamad\n\n"
            ."How to act\nSwipe-reply to this card";

        $this->assertFalse($access->isAdmin('whatsapp_web_spike', '265721070268441', [
            'chat_type' => 'private',
            'text' => 'Joy is a member of the group',
        ]));

        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '265721070268441', [
            'chat_type' => 'private',
            'text' => 'Joy is a member of the group',
            'reply_to_bot' => true,
            'quoted_text' => $card,
        ]));

        // LID remembered for later plain /reply without quote
        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '265721070268441', [
            'chat_type' => 'private',
            'text' => '/reply 9D5GWS follow up',
        ]));
    }

    public function test_whatsapp_admin_lid_accepted_for_swipe_reply_with_multiple_admins(): void
    {
        config([
            'whatsapp_web_spike.admin_phones' => ['2348117084647', '2349137374124'],
            'whatsapp_web_spike.bot_number' => '2347041131371',
        ]);
        $access = new ChannelCommandAccess;

        $card = "*Zak Bot needs a quick hand.*\n\nRequest ID: Y2D27R\n\nHow to act\nSwipe-reply";

        $this->assertTrue($access->isAdmin('whatsapp_web_spike', '265721070268441', [
            'chat_type' => 'private',
            'text' => '/blacklist',
            'reply_to_bot' => true,
            'quoted_text' => $card,
        ]));
    }

    public function test_admin_only_commands(): void
    {
        $access = new ChannelCommandAccess;
        $this->assertTrue($access->isAdminOnlyCommand('/import notes'));
        $this->assertTrue($access->isAdminOnlyCommand('/export notes'));
        $this->assertTrue($access->isAdminOnlyCommand('/approve K2M9P7'));
        $this->assertFalse($access->isAdminOnlyCommand('/ask hello'));
        $this->assertFalse($access->isAdminOnlyCommand('/share note'));
        $this->assertFalse($access->isAdminOnlyCommand('/feature Add dark mode'));
        $this->assertStringContainsString('/share', $access->adminOnlyDenial());
        $this->assertStringContainsString('/share', $access->adminOnlyDenial('whatsapp'));
        $this->assertStringContainsString('*That command is admin-only.*', $access->adminOnlyDenial('whatsapp'));
    }

    public function test_resolve_whatsapp_admin_phone_prefers_msisdn_over_lid(): void
    {
        config([
            'whatsapp_web_spike.admin_phones' => ['2348117084647'],
            'whatsapp_web_spike.bot_number' => '2347041131371',
        ]);
        $access = new ChannelCommandAccess;

        $this->assertSame(
            '2348117084647',
            $access->resolveWhatsAppAdminPhone('265721070268441', [
                'from_phone' => '2348117084647',
            ]),
        );
        // Cached LID → phone mapping for later replies without from_phone.
        $this->assertSame(
            '2348117084647',
            $access->resolveWhatsAppAdminPhone('265721070268441'),
        );
        // Sole admin + LID sender with no phone still maps stably.
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame(
            '2348117084647',
            $access->resolveWhatsAppAdminPhone('265721070268441'),
        );
    }
}
