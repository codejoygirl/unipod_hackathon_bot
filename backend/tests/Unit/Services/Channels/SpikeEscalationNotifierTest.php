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
                && str_contains($text, "Name: @devabdulsamad245")
                && str_contains($text, 'ID: 7216526143')
                && str_contains($text, 'Community: UniPods Cohort')
                && str_contains($text, "Question:\nWho's God?")
                && str_contains($text, "Why:\nI couldn't find enough")
                && str_contains($text, 'Please reply to this message with the answer.')
                && str_contains($text, "I'll send it to the person who asked.")
                && ! str_contains($text, 'Admin contact')
                && ! str_contains($text, '01m2y2cc');
        });
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
                && str_contains($text, 'Hi B A,')
                && str_contains($text, 'How many bots are currently added to the group?')
                && str_contains($text, 'There are currently 2 bots in the group.');
        });
    }

    public function test_escalation_skips_notify_without_chat_id(): void
    {
        Cache::flush();
        config([
            'telegram_spike.bot_token' => 'test-token',
            'telegram_spike.admin_chat_id' => '',
            'telegram_spike.admin_contact' => '08117084647',
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
}
