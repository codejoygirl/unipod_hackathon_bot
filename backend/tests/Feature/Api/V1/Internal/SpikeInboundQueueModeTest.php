<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Internal;

use App\Jobs\ProcessTelegramSpikeInbound;
use App\Jobs\ProcessWhatsAppWebSpikeInbound;
use App\Models\Community;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SpikeInboundQueueModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_web_async_enqueues_and_acks(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['email' => 'demo@zak.test']);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.process_sync' => false,
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'When is clinic open?',
            'message_id' => 'wa-msg-1',
            'chat_id' => '15551234567@c.us',
            'chat_type' => 'private',
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.reply', null);

        Queue::assertPushed(ProcessWhatsAppWebSpikeInbound::class, function ($job) {
            return ($job->payload['message_id'] ?? null) === 'wa-msg-1'
                && $job->queue === 'channels';
        });
    }

    public function test_telegram_async_enqueues_and_acks(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['email' => 'demo@zak.test']);

        config([
            'telegram_spike.enabled' => true,
            'telegram_spike.shared_secret' => 'tg-secret',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'telegram_spike.default_community_id' => $community->id,
            'telegram_spike.process_sync' => false,
        ]);

        $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '7216526143',
            'chat_id' => '7216526143',
            'text' => 'hello',
            'message_id' => '99',
            'chat_type' => 'private',
        ], [
            'X-Spike-Secret' => 'tg-secret',
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.reply', null);

        Queue::assertPushed(ProcessTelegramSpikeInbound::class, function ($job) {
            return ($job->payload['message_id'] ?? null) === '99'
                && $job->queue === 'channels';
        });
    }

    public function test_whatsapp_web_sync_still_returns_reply(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['email' => 'demo@zak.test']);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.process_sync' => true,
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => '/help',
            'message_id' => 'wa-sync-1',
            'chat_type' => 'private',
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.channel', 'whatsapp_web_spike');
    }
}
