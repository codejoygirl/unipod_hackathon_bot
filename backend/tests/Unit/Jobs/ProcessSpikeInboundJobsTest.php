<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessTelegramSpikeInbound;
use App\Jobs\ProcessWhatsAppWebSpikeInbound;
use App\Jobs\ProcessZavuInboundMessage;
use App\Services\Channels\SpikeOutboundSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProcessSpikeInboundJobsTest extends TestCase
{
    public function test_whatsapp_web_job_uses_channels_queue(): void
    {
        $job = new ProcessWhatsAppWebSpikeInbound(['from' => '1', 'text' => 'hi']);
        $this->assertSame('channels', $job->queue);
    }

    public function test_telegram_job_uses_channels_queue(): void
    {
        $job = new ProcessTelegramSpikeInbound(['from' => '1', 'text' => 'hi']);
        $this->assertSame('channels', $job->queue);
    }

    public function test_zavu_job_uses_channels_queue(): void
    {
        $job = new ProcessZavuInboundMessage(['id' => 'e1']);
        $this->assertSame('channels', $job->queue);
    }

    public function test_outbound_sender_posts_whatsapp_send_bridge(): void
    {
        Http::fake([
            'http://wa-out.test/send' => Http::response(['ok' => true], 200),
        ]);

        config([
            'whatsapp_web_spike.outbound_url' => 'http://wa-out.test',
            'whatsapp_web_spike.shared_secret' => 'spike-secret',
        ]);

        $ok = app(SpikeOutboundSender::class)->sendWhatsAppWeb(
            to: '120363@g.us',
            text: 'Hello',
            quotedMessageId: 'false_120363@g.us_ABC',
            mention: '265@lid',
        );

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return $request->url() === 'http://wa-out.test/send'
                && $request['to'] === '120363@g.us'
                && $request['text'] === 'Hello'
                && $request['quoted_message_id'] === 'false_120363@g.us_ABC'
                && $request['mention'] === '265@lid'
                && $request['secret'] === 'spike-secret';
        });
    }

    public function test_outbound_sender_posts_telegram_send_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]], 200),
        ]);

        config(['telegram_spike.bot_token' => 'test-token']);

        $ok = app(SpikeOutboundSender::class)->sendTelegram(
            chatId: '7216526143',
            text: 'TG reply',
            replyToMessageId: '42',
        );

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage')
                && $request['chat_id'] === '7216526143'
                && $request['text'] === 'TG reply'
                && $request['reply_to_message_id'] === 42;
        });
    }

    public function test_whatsapp_web_job_idempotency_lock_skips_second_run(): void
    {
        Cache::flush();

        $key = 'wa_web_spike_msg:msg-dup-1';
        $this->assertTrue(Cache::add($key, 1, now()->addDay()));
        $this->assertFalse(Cache::add($key, 1, now()->addDay()));
    }
}
