<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\Models\Community;
use App\Models\Tenant;
use App\Services\Channels\SpikeEscalationNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpikeEscalationNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_escalation_notifies_telegram_admin_when_configured(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'UniPods Cohort',
        ]);

        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
            'telegram_spike.admin_contact' => '08117084647',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 4242],
            ], 200),
        ]);

        $result = (new SpikeEscalationNotifier)->escalate([
            'question' => "Who's God?",
            'from' => '7216526143',
            'from_name' => '@devabdulsamad245',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'Retrieved sources did not contain enough information to answer the question.',
            'channel' => 'telegram_spike',
        ]);

        $this->assertTrue($result['notified']);
        $this->assertNotEmpty($result['id']);
        $this->assertSame($result['id'], Cache::get('spike_escalation_tg_msg:4242'));
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage')
                && $request['chat_id'] === '123456'
                && ($request['parse_mode'] ?? null) === 'HTML'
                && str_contains($text, "Name: @devabdulsamad245")
                && str_contains($text, 'Member ID: 7216526143')
                && str_contains($text, 'Channel: Telegram')
                && ! str_contains($text, 'telegram_spike')
                && str_contains($text, 'Request ID:')
                && str_contains($text, 'Community: UniPods Cohort')
                && str_contains($text, "Question:\nWho's God?")
                && str_contains($text, "Why:\nI couldn't find enough")
                && str_contains($text, '<code>/reply</code>')
                && str_contains($text, '<code>/blacklist</code>')
                && ! str_contains($text, 'Admin contact')
                && ! str_contains($text, '01m2y2cc');
        });
        $this->assertNotEmpty($result['ref'] ?? null);
        $this->assertSame($result['id'], Cache::get('spike_escalation_ref:'.$result['ref']));
    }

    public function test_web_chat_channel_is_labeled_for_admins(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'UniPods Cohort',
        ]);

        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
            'zak_presence.web_chat_label' => 'Web Chat',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 4243],
            ], 200),
        ]);

        (new SpikeEscalationNotifier)->escalate([
            'question' => 'When is clinic?',
            'from' => '2347041131371',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'insufficient_evidence',
            'channel' => 'web_chat',
        ]);

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, 'Channel: Web Chat')
                && ! str_contains($text, 'web_chat');
        });
    }

    public function test_whatsapp_admin_card_for_telegram_member_does_not_fake_phone_mention(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'UniPods Cohort',
        ]);

        config([
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
            'whatsapp_web_spike.outbound_url' => 'http://127.0.0.1:3101',
            'whatsapp_web_spike.bot_number' => '2347041131371',
            'whatsapp_web_spike.admin_phones' => ['2349137374124'],
        ]);

        Http::fake([
            '127.0.0.1:3101/*' => Http::response(['ok' => true, 'message_id' => 'wa-card-1'], 200),
        ]);

        $result = (new SpikeEscalationNotifier)->escalate([
            'question' => "Who's God?",
            'from' => '7216526143',
            'from_name' => '@devabdulsamad245',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'insufficient_evidence',
            'channel' => 'telegram_spike',
        ]);

        $this->assertTrue($result['notified']);
        Http::assertSent(function ($request) {
            $text = (string) ($request['text'] ?? '');

            return str_contains($request->url(), '127.0.0.1:3101/send')
                && str_contains($text, 'Name: @devabdulsamad245')
                && str_contains($text, 'Channel: Telegram')
                && ! str_contains($text, 'telegram_spike')
                && ! str_contains($text, 'Name: @7216526143')
                && ! str_contains($text, '@+7')
                && ($request['mention'] ?? null) === null;
        });
    }

    public function test_escalation_notifies_whatsapp_admins_via_outbound(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);

        config([
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
            'whatsapp_web_spike.outbound_url' => 'http://127.0.0.1:3101',
            'whatsapp_web_spike.bot_number' => '2347041131371',
            'whatsapp_web_spike.admin_phones' => ['2347041131371', '2348117084647'],
        ]);

        Http::fake([
            '127.0.0.1:3101/*' => Http::response(['ok' => true], 200),
        ]);

        $result = (new SpikeEscalationNotifier)->escalate([
            'question' => 'Are we going to India?',
            'from' => '276694879498269',
            'from_name' => 'Abdulsamad Balogun',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'member_ask',
            'channel' => 'whatsapp_web_spike',
        ]);

        $this->assertTrue($result['notified']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '127.0.0.1:3101/send')
                && $request['secret'] === 'spike-secret'
                && $request['to'] === '2348117084647'
                && str_contains((string) $request['text'], 'Are we going to India?')
                && str_contains((string) $request['text'], 'Name: @276694879498269')
                && ! str_contains((string) $request['text'], 'Name: Abdulsamad Balogun')
                && ($request['mention'] ?? null) === '276694879498269@lid';
        });
        Http::assertNotSent(function ($request) {
            return ($request['to'] ?? null) === '2347041131371';
        });
    }

    public function test_member_ask_notifies_and_can_be_blacklisted(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);

        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 5555],
            ], 200),
        ]);

        $notifier = new SpikeEscalationNotifier;
        $ask = $notifier->handleMemberAsk(
            channel: 'telegram_spike',
            from: '7216526143',
            question: 'Can someone approve my form?',
            communityId: $community->id,
            communityName: $community->name,
            fromName: 'B A',
        );

        $this->assertTrue($ask['ok']);
        $this->assertStringContainsString('passed that to an admin', $ask['reply']);
        $this->assertSame(
            (string) Cache::get('spike_escalation_tg_msg:5555'),
            (string) Cache::get('spike_escalation_tg_msg:5555')
        );
        $this->assertNotEmpty(Cache::get('spike_escalation_tg_msg:5555'));
        $block = $notifier->forwardAdminReply('5555', '/blacklist');
        $this->assertTrue($block['ok']);
        $this->assertTrue($notifier->isAskBlocked('telegram_spike', '7216526143'));

        $denied = $notifier->handleMemberAsk(
            channel: 'telegram_spike',
            from: '7216526143',
            question: 'Another spam ask',
            communityId: $community->id,
            communityName: $community->name,
            fromName: 'B A',
        );
        $this->assertFalse($denied['ok']);
        $this->assertStringContainsString("can't pass this along", $denied['reply']);
    }

    public function test_admin_reply_forwards_answer_to_asker(): void
    {
        Cache::flush();
        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
        ]);

        Cache::put('spike_escalation_tg_msg:4242', 'esc-1', now()->addDay());
        Cache::put('spike_escalation:esc-1', [
            'id' => 'esc-1',
            'question' => 'How many bots are currently added to the group?',
            'from' => '999888',
            'from_name' => 'B A',
            'community_name' => 'Demo Community',
        ], now()->addDay());

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $result = (new SpikeEscalationNotifier)->forwardAdminReply(
            '4242',
            'There are currently 2 bots in the group.'
        );

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString("I've sent that to B A", $result['reply']);
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return $request['chat_id'] === '999888'
                && ($request['parse_mode'] ?? null) === 'HTML'
                && str_contains($text, 'B A,')
                && str_contains($text, 'How many bots are currently added to the group?')
                && str_contains($text, 'There are currently 2 bots in the group.')
                && str_contains($text, '<b>You asked:</b>')
                && str_contains($text, "<b>Here's an update from an admin:</b>");
        });
    }

    public function test_escalation_skips_notify_without_chat_id(): void
    {
        Cache::flush();
        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '',
            'telegram_spike.admin_contact' => '08117084647',
            // Isolate: notifyAdmin also tries WhatsApp when outbound/admin phones exist.
            'whatsapp_web_spike.outbound_url' => '',
            'whatsapp_web_spike.shared_secret' => '',
            'whatsapp_web_spike.admin_phones' => [],
        ]);

        Http::fake();

        $result = (new SpikeEscalationNotifier)->escalate([
            'question' => 'Who runs onboarding?',
            'from' => 'tg-user-2',
            'community_id' => '01community',
            'reason' => 'insufficient_evidence',
            'channel' => 'telegram_spike',
        ]);

        $this->assertFalse($result['notified']);
        Http::assertNothingSent();
        $this->assertNotNull(Cache::get('spike_escalation:'.$result['id']));
    }

    public function test_share_review_notifies_and_approve_publishes(): void
    {
        Cache::flush();
        $tenant = \App\Models\Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);
        $user = \App\Models\User::factory()->create(['email' => 'demo@zak.test']);

        $source = \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Shared Telegram note',
            'uri' => 'telegram-spike://forward/test',
            'source_type' => 'telegram',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'pending_review',
            'language' => 'en',
            'content' => 'Clinic closed Friday afternoon',
            'content_sha256' => hash('sha256', 'Clinic closed Friday afternoon'),
        ]);

        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);

        // Rebuild AI client with test config.
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);
        Http::fake([
            'api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7777]], 200)
                ->push(['ok' => true], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-1',
                'version_id' => 'ai-ver-1',
            ], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $notify = $notifier->notifyShareReview(
            channel: 'telegram_spike',
            from: '999888',
            content: 'Clinic closed Friday afternoon',
            knowledgeSourceId: (string) $source->id,
            communityId: $community->id,
            communityName: $community->name,
            fromName: 'B A',
        );

        $this->assertTrue($notify['notified']);
        $this->assertSame($notify['id'], Cache::get('spike_escalation_tg_msg:7777'));
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === '123456'
                && ($request['parse_mode'] ?? null) === 'HTML'
                && str_contains($text, 'Member shared a note')
                && str_contains($text, 'Clinic closed Friday afternoon')
                && str_contains($text, 'Request ID:')
                && str_contains($text, '<code>/approve</code>')
                && str_contains($text, '<code>/decline</code>');
        });

        $ref = $notify['ref'];
        $this->assertNotEmpty($ref);

        $decision = $notifier->tryAdminCommand("/approve {$ref}");
        $this->assertTrue($decision['ok']);
        $this->assertStringContainsString('Approved', $decision['reply']);
        $this->assertSame('published', $source->fresh()->lifecycle_status->value);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === '999888'
                && str_contains((string) $request['text'], 'I can use it in answers now')
                && str_contains((string) $request['text'], 'Clinic closed Friday afternoon');
        });
    }

    public function test_feature_request_approve_notifies_origin_channel(): void
    {
        Cache::flush();
        $tenant = \App\Models\Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);

        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 8888]], 200)
                ->push(['ok' => true], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $notify = $notifier->notifyFeatureRequest(
            channel: 'telegram_spike',
            from: '999888',
            content: 'Add calendar reminders before each session',
            communityId: $community->id,
            communityName: $community->name,
            fromName: 'Ada',
            chatType: 'group',
            chatId: '-100555',
            messageId: '42',
        );

        $this->assertTrue($notify['notified']);
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === '123456'
                && ($request['parse_mode'] ?? null) === 'HTML'
                && str_contains($text, 'Member requested a new feature or an improvement')
                && str_contains($text, 'Add calendar reminders')
                && str_contains($text, '<code>/approve</code>');
        });

        $ref = $notify['ref'];
        $decision = $notifier->tryAdminCommand("/approve {$ref}");
        $this->assertTrue($decision['ok']);
        $this->assertStringContainsString('Approved', $decision['reply']);
        $this->assertStringContainsString('Ada', $decision['reply']);
        $this->assertStringNotContainsString('the group', $decision['reply']);
        $this->assertStringNotContainsString('@Ada', $decision['reply']);

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === '-100555'
                && str_contains($text, 'approved your feature request')
                && str_contains($text, 'Add calendar reminders');
        });

        $log = Cache::get('spike_feature_decisions', []);
        $this->assertIsArray($log);
        $this->assertSame('approved', $log[0]['decision'] ?? null);
    }

    public function test_feature_admin_ack_uses_whatsapp_mention_not_plain_name(): void
    {
        Cache::flush();
        $community = Community::factory()->create(['name' => 'Demo Community']);

        config([
            'whatsapp_web_spike.outbound_url' => 'http://wa-out.test',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
        ]);

        Http::fake([
            'http://wa-out.test/*' => Http::response(['ok' => true], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $notify = $notifier->notifyFeatureRequest(
            channel: 'whatsapp_web_spike',
            from: '265721070268441',
            content: 'Add voice notes',
            communityId: $community->id,
            fromName: 'Ada',
            fromPhone: '2348011111111',
            chatType: 'group',
            chatId: '120363411674252738@g.us',
        );

        $decision = $notifier->tryAdminCommand('/approve '.$notify['ref']);
        $this->assertTrue($decision['ok']);
        $this->assertStringContainsString('@2348011111111', $decision['reply']);
        $this->assertStringNotContainsString('Ada', $decision['reply']);
        $this->assertStringNotContainsString('the group', $decision['reply']);
    }

    public function test_feature_admin_ack_uses_lid_mention_when_phone_missing(): void
    {
        Cache::flush();
        $community = Community::factory()->create(['name' => 'Demo Community']);

        config([
            'whatsapp_web_spike.outbound_url' => 'http://wa-out.test',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
        ]);

        Http::fake([
            'http://wa-out.test/*' => Http::response(['ok' => true], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $notify = $notifier->notifyFeatureRequest(
            channel: 'whatsapp_web_spike',
            from: '265721070268441',
            content: 'Add voice notes',
            communityId: $community->id,
            fromName: 'Abdulsamad Balogun',
            fromPhone: null,
            chatType: 'group',
            chatId: '120363411674252738@g.us',
        );

        $decision = $notifier->tryAdminCommand('/approve '.$notify['ref']);
        $this->assertTrue($decision['ok']);
        $this->assertStringContainsString('@265721070268441', $decision['reply']);
        $this->assertStringNotContainsString('Abdulsamad Balogun', $decision['reply']);
        $this->assertStringNotContainsString('the group', $decision['reply']);
    }

    public function test_whatsapp_admin_card_name_uses_mention_and_cc_mentions_array(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);

        config([
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
            'whatsapp_web_spike.outbound_url' => 'http://127.0.0.1:3101',
            'whatsapp_web_spike.bot_number' => '2347041131371',
            'whatsapp_web_spike.admin_phones' => ['2348117084647'],
        ]);

        Http::fake([
            '127.0.0.1:3101/*' => Http::response(['ok' => true], 200),
        ]);

        $result = (new SpikeEscalationNotifier)->notifyFeatureRequest(
            channel: 'whatsapp_web_spike',
            from: '265721070268441',
            content: 'we would like voice replies. Cc: @80599524048943',
            communityId: $community->id,
            communityName: $community->name,
            fromName: 'Abdulsamad Balogun',
            fromPhone: null,
            chatType: 'group',
            chatId: '120363411674252738@g.us',
        );

        $this->assertTrue($result['notified']);
        Http::assertSent(function ($request) {
            $text = (string) ($request['text'] ?? '');
            $mentions = $request['mentions'] ?? null;

            return str_contains($request->url(), '127.0.0.1:3101/send')
                && str_contains($text, 'Name: @265721070268441')
                && ! str_contains($text, 'Name: Abdulsamad Balogun')
                && str_contains($text, 'Cc: @80599524048943')
                && is_array($mentions)
                && in_array('265721070268441@lid', $mentions, true)
                && in_array('80599524048943@lid', $mentions, true);
        });
    }

    public function test_feature_request_decline_notifies_member(): void
    {
        Cache::flush();
        $community = Community::factory()->create(['name' => 'Demo Community']);

        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 8899]], 200)
                ->push(['ok' => true], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $notify = $notifier->notifyFeatureRequest(
            channel: 'telegram_spike',
            from: '111222',
            content: 'Add a dancing emoji bot',
            communityId: $community->id,
            fromName: 'Bo',
            chatType: 'private',
            chatId: '111222',
        );

        $ref = $notify['ref'];
        $decision = $notifier->tryAdminCommand("/decline {$ref}");
        $this->assertTrue($decision['ok']);
        $this->assertStringContainsString('Declined', $decision['reply']);
        $this->assertStringContainsString('Bo', $decision['reply']);

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === '111222'
                && str_contains($text, "won't take it forward")
                && (str_contains($text, '/feature') || str_contains($text, '<code>/feature</code>'));
        });
    }

    public function test_zavu_escalation_notifies_admins_and_delivers_member_reply(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);
        \App\Models\User::factory()->create(['email' => 'demo@zak.test']);

        config([
            'whatsapp_zavu.enabled' => true,
            'whatsapp_zavu.api_key' => 'zv_test',
            'whatsapp_zavu.api_base' => 'https://api.zavu.dev/v1',
            'whatsapp_zavu.admin_phones' => '2348011111111',
            'whatsapp_zavu.phone_number' => '2348117084647',
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'whatsapp_web_spike.outbound_url' => '',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            'https://api.zavu.dev/v1/messages' => Http::sequence()
                ->push(['id' => 'zavu_admin_card'], 200)
                ->push(['id' => 'zavu_member_reply'], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-zavu',
                'version_id' => 'ai-ver-zavu',
            ], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $created = $notifier->escalate([
            'question' => 'When is clinic?',
            'from' => '2349012345678',
            'from_phone' => '2349012345678',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'member_ask',
            'channel' => 'whatsapp_zavu',
        ]);

        $this->assertTrue($created['notified']);

        $ref = $created['ref'];
        $result = $notifier->tryAdminCommand("/reply {$ref} Clinic is Friday at 3pm CAT.");
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('sent that to', $result['reply']);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.zavu.dev/v1/messages') {
                return false;
            }
            $to = (string) ($request['to'] ?? '');
            $text = (string) ($request['text'] ?? '');

            return $to === '+2348011111111'
                && str_contains($text, 'When is clinic?');
        });

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.zavu.dev/v1/messages') {
                return false;
            }
            $to = (string) ($request['to'] ?? '');
            $text = (string) ($request['text'] ?? '');

            return $to === '+2349012345678'
                && str_contains($text, 'Clinic is Friday at 3pm CAT.');
        });
    }

    public function test_ask_reply_by_request_id(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);
        \App\Models\User::factory()->create(['email' => 'demo@zak.test']);

        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            'api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9001]], 200)
                ->push(['ok' => true], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-admin',
                'version_id' => 'ai-ver-admin',
            ], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $created = $notifier->escalate([
            'question' => 'Are we going to London?',
            'from' => '999888',
            'from_name' => 'B A',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'member_ask',
            'channel' => 'telegram_spike',
        ]);

        $ref = $created['ref'];
        $result = $notifier->tryAdminCommand("/reply {$ref} Not this cohort - London is next year.");
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('sent that to B A', $result['reply']);
        $this->assertStringContainsString('knowledge base', $result['reply']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }
            $data = $request->data();
            if (($data['chat_id'] ?? null) !== '999888') {
                return false;
            }
            $text = (string) ($data['text'] ?? '');

            return str_contains($text, 'Are we going to London?')
                && str_contains($text, 'Not this cohort')
                && str_contains($text, "Here's an update from an admin")
                && str_contains($text, '<b>You asked:</b>')
                && ($request['parse_mode'] ?? null) === 'HTML';
        });

        $stored = \App\Models\KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('lifecycle_status', 'published')
            ->where('content', 'like', '%Are we going to London?%')
            ->first();
        $this->assertNotNull($stored);
        $this->assertStringContainsString('Not this cohort', (string) $stored->content);
        $this->assertSame('official_announcement', $stored->authority_tier->value);
    }

    public function test_second_approve_is_polite_already_handled(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = \App\Models\User::factory()->create(['email' => 'demo@zak.test']);
        $source = \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Shared note',
            'uri' => 'telegram-spike://share/test',
            'source_type' => 'telegram',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'pending_review',
            'language' => 'en',
            'content' => 'Water off Friday',
            'content_sha256' => hash('sha256', 'Water off Friday'),
        ]);

        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '123456',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-1',
                'version_id' => 'ai-ver-1',
            ], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $notify = $notifier->notifyShareReview(
            channel: 'telegram_spike',
            from: '999888',
            content: 'Water off Friday',
            knowledgeSourceId: (string) $source->id,
            communityId: $community->id,
            communityName: $community->name,
            fromName: 'Member',
        );

        $ref = $notify['ref'];
        $first = $notifier->tryAdminCommand("/approve {$ref}", 'telegram_spike', '123456');
        $this->assertTrue($first['ok']);
        $this->assertStringContainsString('Approved', $first['reply']);

        $second = $notifier->tryAdminCommand("/approve {$ref}", 'telegram_spike', '123456');
        $this->assertTrue($second['ok']);
        $this->assertStringContainsString('already approved', $second['reply']);
        $this->assertStringContainsString($ref, $second['reply']);
    }

    public function test_ask_reply_delivers_to_whatsapp_member_with_details(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);
        \App\Models\User::factory()->create(['email' => 'demo@zak.test']);

        config([
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
            'whatsapp_web_spike.outbound_url' => 'http://127.0.0.1:3101',
            'whatsapp_web_spike.admin_phones' => ['2348117084647'],
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            '127.0.0.1:3101/*' => Http::response(['ok' => true], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-wa',
                'version_id' => 'ai-ver-wa',
            ], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $created = $notifier->escalate([
            'question' => 'Is the clinic open Friday?',
            'from' => '276694879498269',
            'from_phone' => '2348011111111',
            'from_name' => 'Abdulsamad Balogun',
            'chat_type' => 'private',
            'chat_id' => '2348011111111@c.us',
            'message_id' => 'true_2348011111111@c.us_ASKMSG01',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'member_ask',
            'channel' => 'whatsapp_web_spike',
        ]);

        $ref = $created['ref'];
        $result = $notifier->tryAdminCommand(
            "/reply {$ref} Yes, open until 4pm.",
            'whatsapp_web_spike',
            '265721070268441',
            '2348117084647',
        );
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('sent that to Abdulsamad Balogun', $result['reply']);
        $this->assertStringContainsString('(request *', $result['reply']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '127.0.0.1:3101/send')) {
                return false;
            }
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return ($data['to'] ?? null) === '2348011111111@c.us'
                && ($data['mention'] ?? null) === null
                && ($data['quoted_message_id'] ?? null) === 'true_2348011111111@c.us_ASKMSG01'
                && is_array($data['mentions'] ?? null)
                && in_array('2348117084647@c.us', $data['mentions'], true)
                && ! in_array('2348011111111@c.us', $data['mentions'], true)
                && str_starts_with(ltrim($text), '*You asked:*')
                && ! str_starts_with(ltrim($text), 'Hi,')
                && str_contains($text, 'Is the clinic open Friday?')
                && str_contains($text, 'Yes, open until 4pm.')
                && str_contains($text, "*Here's an update from an admin* (@2348117084647):")
                && str_contains($text, '*You asked:*');
        });
    }

    public function test_ask_reply_uses_lid_chat_jid_not_fake_phone(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);
        \App\Models\User::factory()->create(['email' => 'demo@zak.test']);

        config([
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
            'whatsapp_web_spike.outbound_url' => 'http://127.0.0.1:3101',
            'whatsapp_web_spike.admin_phones' => ['2348117084647'],
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            '127.0.0.1:3101/*' => Http::response(['ok' => true], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-lid',
                'version_id' => 'ai-ver-lid',
            ], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        // LID echoed as from_phone (bug from WA contact.number) + real chat JID.
        $created = $notifier->escalate([
            'question' => 'Where is heaven?',
            'from' => '265721070268441',
            'from_phone' => '265721070268441',
            'from_name' => 'Member',
            'chat_type' => 'private',
            'chat_id' => '265721070268441@lid',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'member_ask',
            'channel' => 'whatsapp_web_spike',
        ]);

        $result = $notifier->tryAdminCommand('/reply '.$created['ref'].' It is a metaphor.');
        $this->assertTrue($result['ok']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '127.0.0.1:3101/send')
                && $request['to'] === '265721070268441@lid'
                && ($request['mention'] ?? null) === null
                && str_contains((string) $request['text'], 'It is a metaphor.');
        });
    }

    public function test_ask_reply_delivers_to_whatsapp_group_when_ask_was_in_group(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Community',
        ]);
        \App\Models\User::factory()->create(['email' => 'demo@zak.test']);

        config([
            'telegram_spike.bot_token' => '',
            'telegram_spike.admin_chat_id' => '',
            'telegram_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
            'whatsapp_web_spike.outbound_url' => 'http://127.0.0.1:3101',
            'whatsapp_web_spike.admin_phones' => ['2348117084647'],
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            '127.0.0.1:3101/*' => Http::response(['ok' => true], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-wa-g',
                'version_id' => 'ai-ver-wa-g',
            ], 200),
        ]);

        $notifier = app(SpikeEscalationNotifier::class);
        $created = $notifier->escalate([
            'question' => "Who's Joy?",
            'from' => '265721070268441',
            'from_phone' => '2348011111111',
            'from_name' => 'Abdulsamad',
            'chat_type' => 'group',
            'chat_id' => '120363431285960149@g.us',
            'message_id' => 'true_120363431285960149@g.us_ABCDEF',
            'community_id' => $community->id,
            'community_name' => $community->name,
            'reason' => 'member_ask',
            'channel' => 'whatsapp_web_spike',
        ]);

        $ref = $created['ref'];
        // Admin often arrives as LID; phone must be resolved for a green @mention.
        $result = $notifier->tryAdminCommand(
            "/reply {$ref} Joy is a METI member.",
            'whatsapp_web_spike',
            '265721070268441',
            '2348117084647',
        );
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('sent that to the group', $result['reply']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '127.0.0.1:3101/send')) {
                return false;
            }
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return ($data['to'] ?? null) === '120363431285960149@g.us'
                && ($data['mention'] ?? null) === '2348011111111@c.us'
                && is_array($data['mentions'] ?? null)
                && in_array('2348011111111@c.us', $data['mentions'], true)
                && in_array('2348117084647@c.us', $data['mentions'], true)
                && str_starts_with(ltrim($text), '*You asked:*')
                && ! str_starts_with(ltrim($text), 'Hi,')
                && str_contains($text, "Who's Joy?")
                && str_contains($text, 'Joy is a METI member.')
                && str_contains($text, "*Here's an update from an admin* (@2348117084647):")
                && ! str_contains($text, '@265721070268441');
        });
    }

    public function test_reply_with_unknown_request_id_says_not_found(): void
    {
        Cache::flush();
        $notifier = app(SpikeEscalationNotifier::class);

        $result = $notifier->tryAdminCommand('/reply W7X1YT God is the creator of the universe.');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("couldn't find request W7X1YT", $result['reply']);
        $this->assertStringNotContainsString('Include the Request ID', $result['reply']);
    }

    public function test_reply_without_request_id_prompts_for_it(): void
    {
        Cache::flush();
        $notifier = app(SpikeEscalationNotifier::class);

        $result = $notifier->tryAdminCommand('/reply God is the creator of the universe.');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Include the Request ID', $result['reply']);
    }

    public function test_whatsapp_card_message_id_maps_back_to_escalation(): void
    {
        Cache::flush();
        $notifier = app(SpikeEscalationNotifier::class);
        $notifier->rememberWhatsAppEscalationMessage(
            'true_2349137374124@c.us_3EB0ABCDEF',
            '01TESTWAESCMSG1',
        );

        $this->assertSame(
            '01TESTWAESCMSG1',
            $notifier->findEscalationIdByWhatsAppMessageId('true_2349137374124@c.us_3EB0ABCDEF'),
        );
        $this->assertSame(
            '01TESTWAESCMSG1',
            $notifier->findEscalationIdByWhatsAppMessageId('3EB0ABCDEF'),
        );
        $this->assertSame('W7X1YT', $notifier->extractRefFromCardText(
            "*Zak Bot needs a quick hand.*\n\nRequest ID: W7X1YT\n\nQuestion:\nHi",
        ));
        $this->assertSame('W7X1YT', $notifier->extractRefFromCardText(
            "How to act:\n```\n/reply W7X1YT Your answer here\n```",
        ));
    }

    public function test_already_resolved_reply_formats_actor_and_timezone(): void
    {
        config(['app.timezone' => 'Africa/Lagos']);
        config(['whatsapp_web_spike.admin_phones' => ['2348117084647']]);

        $notifier = app(SpikeEscalationNotifier::class);
        $reply = $notifier->alreadyResolvedReply([
            'ref' => 'HQWP73',
            'resolved_at' => '2026-09-22T20:43:00+00:00',
            'resolved_by' => '265721070268441@lid',
            'resolved_by_phone' => '2348117084647',
            'admin_answer' => 'Sallah is next year',
        ]);

        $this->assertNotNull($reply);
        $this->assertTrue($reply['ok']);
        $this->assertStringContainsString('Request HQWP73 was already answered by @2348117084647', $reply['reply']);
        $this->assertStringNotContainsString('@lid', $reply['reply']);
        $this->assertStringNotContainsString('2026-09-22T20:43:00', $reply['reply']);
        $this->assertStringContainsString('WAT', $reply['reply']);
        $this->assertStringContainsString('UTC+1', $reply['reply']);
        $this->assertMatchesRegularExpression('/Sep 22, 2026 at \d{1,2}:\d{2} [AP]M/', $reply['reply']);
    }

    public function test_already_resolved_hides_raw_lid_when_phone_unknown(): void
    {
        config(['app.timezone' => 'UTC']);
        config(['whatsapp_web_spike.admin_phones' => []]);

        $notifier = app(SpikeEscalationNotifier::class);
        $reply = $notifier->alreadyResolvedReply([
            'ref' => 'ABC123',
            'resolved_at' => '2026-09-22T20:43:00+00:00',
            'resolved_by' => '265721070268441@lid',
            'admin_answer' => 'done',
        ]);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('by an admin', $reply['reply']);
        $this->assertStringNotContainsString('265721070268441', $reply['reply']);
        $this->assertStringContainsString('UTC+0', $reply['reply']);
    }
}
