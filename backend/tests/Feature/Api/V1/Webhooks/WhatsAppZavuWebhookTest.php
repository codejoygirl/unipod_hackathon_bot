<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Webhooks;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\Zavu\ZavuWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppZavuWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_disabled_by_default(): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'message.inbound', 'data' => []], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp-zavu',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertNotFound();
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        config([
            'whatsapp_zavu.enabled' => true,
            'whatsapp_zavu.webhook_secret' => 'whsec_test',
            'whatsapp_zavu.api_key' => 'zv_test',
        ]);

        $body = json_encode([
            'id' => 'evt_bad_sig',
            'type' => 'message.inbound',
            'data' => [
                'from' => '+15551234567',
                'text' => 'hello',
                'channel' => 'whatsapp',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp-zavu',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_ZAVU_SIGNATURE' => 't='.time().',v2=deadbeef',
            ],
            $body,
        )->assertUnauthorized();
    }

    public function test_webhook_ask_sends_cited_reply_via_zavu(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response([
                'intent' => 'knowledge',
                'link_mode' => 'none',
                'follow_up' => false,
                'link_focus' => 'na',
                'needs_temporal_resolution' => false,
            ], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'When is clinic open?',
                'detected_language' => 'en',
                'execution_time_ms' => 12.0,
                'total_chunks_retrieved' => 1,
                'validated_payload' => [
                    'state' => 'VERIFIED',
                    'answer' => 'Saturday 9am [E1].',
                    'confidence_score' => 0.9,
                    'needs_escalation' => false,
                    'escalation_reason' => null,
                    'citations' => [[
                        'evidence_id' => 'E1',
                        'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                        'source_name' => 'Clinic',
                        'source_uri' => 'doc://clinic-hours',
                        'authority_tier' => 'official_announcement',
                        'exact_quote' => 'Saturday 9am',
                        'context_snippet' => 'Clinic opens Saturday 9am.',
                        'page_number' => 1,
                        'timestamp_seconds' => null,
                        'is_verified' => true,
                    ]],
                    'conflicts' => [],
                ],
            ], 200),
            'https://api.zavu.dev/v1/messages' => Http::response(['id' => 'msg_out_1'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Clinic',
            'uri' => 'doc://clinic-hours',
            'source_type' => 'markdown',
            'authority_tier' => 'official_announcement',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'Clinic opens Saturday 9am.',
            'content_sha256' => hash('sha256', 'Clinic opens Saturday 9am.'),
            'published_at' => now(),
        ]);

        config([
            'whatsapp_zavu.enabled' => true,
            'whatsapp_zavu.webhook_secret' => 'whsec_test',
            'whatsapp_zavu.api_key' => 'zv_test',
            'whatsapp_zavu.default_user_email' => 'demo@zak.test',
            'whatsapp_zavu.default_community_id' => $community->id,
        ]);

        $body = json_encode([
            'id' => 'evt_ask_1',
            'type' => 'message.inbound',
            'timestamp' => (int) (microtime(true) * 1000),
            'senderId' => 'snd_test',
            'projectId' => 'prj_test',
            'data' => [
                'messageId' => 'msg_in_1',
                'from' => '+15551234567',
                'to' => '+15559876543',
                'channel' => 'whatsapp',
                'messageType' => 'text',
                'text' => 'When is clinic open?',
            ],
        ], JSON_THROW_ON_ERROR);

        $sig = ZavuWebhookSignature::signV2($body, 'whsec_test');

        $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp-zavu',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_ZAVU_SIGNATURE' => $sig,
            ],
            $body,
        )->assertOk()->assertSee('OK');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zavu.dev/v1/messages'
                && $request['to'] === '+15551234567'
                && $request['channel'] === 'whatsapp'
                && str_contains((string) $request['text'], 'Saturday 9am');
        });
    }

    public function test_mint_join_requires_admin_secret(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);

        config([
            'whatsapp_zavu.enabled' => true,
            'whatsapp_zavu.admin_secret' => 'admin-secret',
            'whatsapp_zavu.phone_number' => '+15559876543',
        ]);

        $this->postJson('/api/v1/internal/whatsapp-zavu/join-token', [
            'community_id' => $community->id,
        ])->assertUnauthorized();

        $response = $this->postJson('/api/v1/internal/whatsapp-zavu/join-token', [
            'community_id' => $community->id,
        ], [
            'X-Zavu-Admin-Secret' => 'admin-secret',
        ])->assertOk();

        $join = $response->json('data.join_message');
        $this->assertIsString($join);
        $this->assertStringStartsWith('JOIN-', $join);
        $this->assertStringContainsString('wa.me/15559876543', (string) $response->json('data.wa_me_link'));
    }

    public function test_webhook_voice_note_resolves_media_and_transcribes(): void
    {
        Http::fake([
            'https://api.zavu.dev/v1/messages/msg_voice_in' => Http::response([
                'messageId' => 'msg_voice_in',
                'content' => [
                    'mediaUrl' => 'https://cdn.zavu.dev/inbound.ogg',
                    'mimeType' => 'audio/ogg',
                ],
            ], 200),
            'https://cdn.zavu.dev/inbound.ogg' => Http::response('voice-bytes', 200),
            '*/conversation/transcribe' => Http::response([
                'text' => 'When is the next session?',
                'language' => 'en',
            ], 200),
            '*/conversation/classify' => Http::response([
                'intent' => 'knowledge',
                'link_mode' => 'none',
                'follow_up' => false,
                'link_focus' => 'na',
                'needs_temporal_resolution' => false,
            ], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'When is the next session?',
                'detected_language' => 'en',
                'execution_time_ms' => 5.0,
                'total_chunks_retrieved' => 0,
                'validated_payload' => [
                    'state' => 'INSUFFICIENT_EVIDENCE',
                    'answer' => '',
                    'confidence_score' => 0.0,
                    'needs_escalation' => true,
                    'escalation_reason' => 'gap',
                    'citations' => [],
                    'conflicts' => [],
                ],
            ], 200),
            'https://api.zavu.dev/v1/messages' => Http::response(['id' => 'msg_out_voice'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'whatsapp_zavu.enabled' => true,
            'whatsapp_zavu.webhook_secret' => 'whsec_test',
            'whatsapp_zavu.api_key' => 'zv_test',
            'whatsapp_zavu.default_user_email' => 'demo@zak.test',
            'whatsapp_zavu.default_community_id' => $community->id,
        ]);

        $body = json_encode([
            'id' => 'evt_voice_1',
            'type' => 'message.inbound',
            'data' => [
                'messageId' => 'msg_voice_in',
                'from' => '+15551234567',
                'channel' => 'whatsapp',
                'messageType' => 'audio',
                'text' => '',
                'content' => [
                    'mediaId' => 'wa_audio_1',
                    'mimeType' => 'audio/ogg',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $sig = ZavuWebhookSignature::signV2($body, 'whsec_test');

        $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp-zavu',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_ZAVU_SIGNATURE' => $sig,
            ],
            $body,
        )->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/conversation/transcribe'));
    }

    public function test_spikes_still_disabled_independently(): void
    {
        config([
            'whatsapp_zavu.enabled' => true,
            'whatsapp_zavu.webhook_secret' => 'whsec_test',
            'whatsapp_web_spike.enabled' => false,
            'telegram_spike.enabled' => false,
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '1',
            'text' => 'hi',
        ])->assertNotFound();

        $this->postJson('/api/v1/internal/telegram-spike/inbound', [
            'from' => '1',
            'text' => 'hi',
        ])->assertNotFound();
    }
}
