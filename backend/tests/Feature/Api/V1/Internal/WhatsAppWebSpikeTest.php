<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Internal;

use App\Enums\MembershipRole;
use App\Enums\KnowledgeLifecycleStatus;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppWebSpikeTest extends TestCase
{
    use RefreshDatabase;

    public function test_spike_inbound_is_disabled_by_default(): void
    {
        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'hello',
        ])->assertNotFound();
    }

    public function test_spike_rejects_invalid_secret(): void
    {
        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'correct-secret',
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'hello',
        ], [
            'X-Spike-Secret' => 'wrong',
        ])->assertUnauthorized();
    }

    public function test_spike_ask_returns_cited_reply(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response([
                'intent' => 'knowledge',
                'link_mode' => 'none',
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
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'When is clinic open?',
            'message_id' => 'wamid.abc',
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp_web_spike')
            ->assertJsonPath('data.reply', 'Saturday 9am.'."\n\n".'(From clinic hours notes.)');
    }

    public function test_spike_export_creates_draft(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.admin_phones' => ['15551234567'],
            'whatsapp_web_spike.process_sync' => true,
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => "EXPORT UniPods / Wadhwani programme resource pack\n\nWater off tomorrow 8am–12pm.",
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp_web_spike');

        $this->assertDatabaseHas('knowledge_documents', [
            'community_id' => $community->id,
            'source_type' => 'whatsapp',
            'lifecycle_status' => 'draft',
            'name' => 'UniPods / Wadhwani programme resource pack',
        ]);
    }

    public function test_admin_swipe_reply_publishes_draft(): void
    {
        Http::fake([
            '*/ingestion/sync' => Http::response([
                'source_id' => '11111111-1111-1111-1111-111111111111',
                'version_id' => '22222222-2222-2222-2222-222222222222',
                'status' => 'completed',
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.admin_phones' => ['15551234567'],
            'whatsapp_web_spike.process_sync' => true,
        ]);

        $source = KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods resource pack',
            'uri' => 'whatsapp-web-spike://import/swipe-test',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'schedules and slides',
            'content_sha256' => hash('sha256', 'schedules and slides'),
            'metadata' => ['origin' => 'admin_import'],
        ]);
        $short = strtoupper(substr((string) $source->id, -6));

        $reply = $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'publish',
            'reply_to_bot' => true,
            'quoted_text' => "*Imported.*\n\n*UniPods resource pack*\nID: `{$short}`\n\nSwipe-reply with *publish*",
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->json('data.reply');

        $this->assertIsString($reply);
        $this->assertStringContainsString('Published', $reply);
        $this->assertStringContainsString('UniPods resource pack', $reply);
        $this->assertStringNotContainsString('AI index', $reply);

        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $source->id,
            'lifecycle_status' => 'published',
        ]);
    }

    public function test_non_admin_import_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.admin_phones' => ['2348011111111'],
            'whatsapp_web_spike.process_sync' => true,
        ]);

        $deny = app(\App\Services\Channels\ChannelCommandAccess::class)->adminOnlyDenial('whatsapp');

        foreach (['/import should not work for members', '/export should not work for members'] as $text) {
            $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
                'from' => '15551234567',
                'text' => $text,
            ], [
                'X-Spike-Secret' => 'spike-test-secret',
            ])
                ->assertOk()
                ->assertJsonPath('data.reply', $deny);
        }

        $this->assertDatabaseMissing('knowledge_documents', [
            'community_id' => $community->id,
            'name' => 'UniPods / Wadhwani programme resource pack',
        ]);
    }

    public function test_group_silent_without_mention(): void
    {
        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.bot_aliases' => ['zak'],
            'whatsapp_web_spike.group_listen' => 'mention_or_command',
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'random group chatter',
            'chat_type' => 'group',
            'is_group' => true,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.reply', null);
    }

    public function test_group_listens_on_mention(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response([
                'intent' => 'knowledge',
                'link_mode' => 'none',
            ], 200),
            '*/conversation/reply' => Http::response([
                'reply' => "Sorry, I can't help with that one yet. Ask about community schedules anytime.",
            ], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'hours?',
                'detected_language' => 'en',
                'execution_time_ms' => 1.0,
                'total_chunks_retrieved' => 0,
                'validated_payload' => [
                    'state' => 'INSUFFICIENT',
                    'answer' => '',
                    'confidence_score' => 0.1,
                    'needs_escalation' => true,
                    'escalation_reason' => 'no sources',
                    'citations' => [],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.bot_aliases' => ['zak'],
            'whatsapp_web_spike.group_listen' => 'mention_or_command',
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => '@zak hours?',
            'chat_type' => 'group',
            'is_group' => true,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp_web_spike');

        $reply = $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => '@zak hours?',
            'chat_type' => 'group',
            'is_group' => true,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])->json('data.reply');

        $this->assertNotNull($reply);
        $this->assertNotSame('', $reply);
    }

    public function test_group_listens_when_bot_mentioned_flag_set(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response([
                'intent' => 'knowledge',
                'link_mode' => 'none',
            ], 200),
            '*/conversation/reply' => Http::response([
                'reply' => "Sorry, I can't help with that one yet. Ask about community schedules anytime.",
            ], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'meetings today?',
                'detected_language' => 'en',
                'execution_time_ms' => 1.0,
                'total_chunks_retrieved' => 0,
                'validated_payload' => [
                    'state' => 'INSUFFICIENT',
                    'answer' => '',
                    'confidence_score' => 0.1,
                    'needs_escalation' => true,
                    'escalation_reason' => 'no sources',
                    'citations' => [],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.bot_aliases' => ['zak_bot'],
            'whatsapp_web_spike.group_listen' => 'mention_or_command',
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => '@100696296808461 What are the meetings scheduled for today?',
            'chat_type' => 'group',
            'is_group' => true,
            'bot_mentioned' => true,
            'bot_lid' => '100696296808461',
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk();

        $reply = $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => '@100696296808461 What are the meetings scheduled for today?',
            'chat_type' => 'group',
            'is_group' => true,
            'bot_mentioned' => true,
            'bot_lid' => '100696296808461',
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])->json('data.reply');

        $this->assertNotNull($reply);
        $this->assertNotSame('', $reply);
    }

    public function test_bare_bot_lid_quote_reply_is_not_silenced(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response([
                'intent' => 'knowledge',
                'link_mode' => 'none',
            ], 200),
            '*/conversation/reply' => Http::response([
                'reply' => 'Social',
            ], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'When did the hackathon start?',
                'detected_language' => 'en',
                'execution_time_ms' => 1.0,
                'total_chunks_retrieved' => 1,
                'validated_payload' => [
                    'state' => 'GROUNDED',
                    'answer' => 'The hackathon started on Monday.',
                    'confidence_score' => 0.9,
                    'needs_escalation' => false,
                    'escalation_reason' => null,
                    'citations' => [],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.bot_lid' => '100696296808461',
            'whatsapp_web_spike.bot_aliases' => ['zak_bot'],
            'whatsapp_web_spike.group_listen' => 'mention_or_command',
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
        ]);

        $reply = $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '265721070268441',
            'text' => '@100696296808461',
            'chat_type' => 'group',
            'is_group' => true,
            'bot_mentioned' => true,
            'bot_lid' => '100696296808461',
            'quoted_text' => 'When did the hackathon start?',
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->json('data.reply');

        $this->assertNotNull($reply);
        $this->assertNotSame('', $reply);
        $this->assertStringContainsString('hackathon', mb_strtolower((string) $reply));
    }

    public function test_spike_join_token_and_link(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => User::query()->where('email', 'demo@zak.test')->value('id'),
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => null,
        ]);

        $mint = $this->postJson('/api/v1/internal/whatsapp-web-spike/join-token', [
            'community_id' => $community->id,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])->assertOk();

        $joinMessage = $mint->json('data.join_message');
        $this->assertNotEmpty($joinMessage);
        $this->assertStringStartsWith('JOIN-', $joinMessage);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15559876543',
            'text' => $joinMessage,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.reply', 'Linked to community '.$community->id.'. Ask me a question about community knowledge.');
    }

    public function test_admin_swipe_reply_to_escalation_card_delivers_answer(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);
        User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => User::query()->where('email', 'demo@zak.test')->value('id'),
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.bot_number' => '2347041131371',
            'whatsapp_web_spike.admin_phones' => ['2347041131371', '2348117084647'],
            'whatsapp_web_spike.outbound_url' => 'http://127.0.0.1:3101',
            'whatsapp_web_spike.process_sync' => true,
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            '127.0.0.1:3101/*' => Http::response(['ok' => true], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-1',
                'version_id' => 'ai-ver-1',
            ], 200),
        ]);

        $escId = '01ADMINSWIPECARD1';
        $ref = '9D5GWS';
        \Illuminate\Support\Facades\Cache::put('spike_escalation:'.$escId, [
            'id' => $escId,
            'type' => 'ask',
            'ref' => $ref,
            'question' => "Who's @80599524048943 ?",
            'from' => '265721070268441',
            'from_phone' => '2348011111111',
            'from_name' => 'Abdulsamad',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'insufficient_evidence',
            'channel' => 'whatsapp_web_spike',
        ], now()->addDay());
        \Illuminate\Support\Facades\Cache::put('spike_escalation_ref:'.$ref, $escId, now()->addDay());

        $card = "*Zak Bot needs a quick hand.*\n\nRequest ID: {$ref}\n\nName: Abdulsamad\n\n"
            ."Member ID: 265721070268441\n\nHow to act\nSwipe-reply to this card";

        $response = $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '265721070268441',
            'text' => 'Joy is a member of the group',
            'chat_type' => 'private',
            'is_group' => false,
            'reply_to_bot' => true,
            'quoted_text' => $card,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])->assertOk();

        $reply = (string) $response->json('data.reply');
        $this->assertStringContainsString('sent that to', $reply);
        $this->assertStringNotContainsString('Zak Bot needs a quick hand', $reply);
        $this->assertStringNotContainsString("don't have a solid answer", $reply);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '127.0.0.1:3101/send')
                && $request['to'] === '2348011111111'
                && str_contains((string) $request['text'], 'Joy is a member of the group')
                && str_contains((string) $request['text'], "Who's @80599524048943");
        });
    }

    public function test_admin_swipe_reply_via_quoted_message_id_when_quote_text_truncated(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);
        User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => User::query()->where('email', 'demo@zak.test')->value('id'),
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
            'whatsapp_web_spike.bot_number' => '2347041131371',
            'whatsapp_web_spike.admin_phones' => ['2347041131371', '2349137374124'],
            'whatsapp_web_spike.outbound_url' => 'http://127.0.0.1:3101',
            'whatsapp_web_spike.process_sync' => true,
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            '127.0.0.1:3101/*' => Http::response(['ok' => true], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-1',
                'version_id' => 'ai-ver-1',
            ], 200),
        ]);

        $escId = '01ADMINSWIPEMSGID1';
        $ref = 'W7X1YT';
        $waMsgId = 'true_2349137374124@c.us_3EB0CARD01';
        \Illuminate\Support\Facades\Cache::put('spike_escalation:'.$escId, [
            'id' => $escId,
            'type' => 'ask',
            'ref' => $ref,
            'question' => 'Who created the universe?',
            'from' => '265721070268441',
            'from_phone' => '2348011111111',
            'from_name' => 'Abdulsamad',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'insufficient_evidence',
            'channel' => 'whatsapp_web_spike',
        ], now()->addDay());
        \Illuminate\Support\Facades\Cache::put('spike_escalation_ref:'.$ref, $escId, now()->addDay());
        app(\App\Services\Channels\SpikeEscalationNotifier::class)
            ->rememberWhatsAppEscalationMessage($waMsgId, $escId);

        // WhatsApp UI quote often shows only the title — no Request ID in quoted_text.
        $response = $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '2349137374124',
            'text' => 'God is the creator of the universe.',
            'chat_type' => 'private',
            'is_group' => false,
            'reply_to_bot' => true,
            'quoted_text' => "Zak Bot needs a quick hand.\n...",
            'quoted_message_id' => $waMsgId,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])->assertOk();

        $reply = (string) $response->json('data.reply');
        $this->assertStringContainsString('sent that to', $reply);
        $this->assertStringNotContainsString('Include the Request ID', $reply);
        $this->assertStringNotContainsString("couldn't read the Request ID", $reply);
    }
}
