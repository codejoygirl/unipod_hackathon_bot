<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\DTOs\Channels\InboundMessage;
use App\Services\AI\AiServiceClient;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\VoiceNoteNormalizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceNoteNormalizerTest extends TestCase
{
    public function test_leaves_plain_text_unchanged(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);
        Http::fake();

        $msg = new InboundMessage(
            channel: 'whatsapp_web_spike',
            externalUserId: '2348011111111',
            text: 'When is clinic?',
            raw: [],
        );

        $out = app(VoiceNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertSame('When is clinic?', $out['message']->text);
        Http::assertNothingSent();
    }

    public function test_transcribes_voice_and_sets_language(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/transcribe' => Http::response([
                'text' => 'Bawo ni? Igbimo wo ni ejo yi?',
                'language' => 'yo',
                'duration_seconds' => 4.2,
            ], 200),
        ]);

        $msg = new InboundMessage(
            channel: 'whatsapp_web_spike',
            externalUserId: '2348011111111',
            text: '',
            raw: [
                'media' => [
                    'kind' => 'voice',
                    'mime_type' => 'audio/ogg; codecs=opus',
                    'filename' => 'voice.ogg',
                    'data_base64' => base64_encode(str_repeat('x', 64)),
                ],
            ],
        );

        $out = app(VoiceNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertSame('Bawo ni? Igbimo wo ni ejo yi?', $out['message']->text);
        $this->assertSame('yo', $out['message']->raw['whisper_language'] ?? null);
        $this->assertArrayNotHasKey('target_language', $out['message']->raw);
        $this->assertSame('voice', $out['message']->raw['input_modality'] ?? null);
        $this->assertArrayNotHasKey('media', $out['message']->raw);
    }

    public function test_failed_transcript_returns_polite_error(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/transcribe' => Http::response([
                'text' => '',
                'language' => null,
            ], 200),
        ]);

        $msg = new InboundMessage(
            channel: 'whatsapp_web_spike',
            externalUserId: '2348011111111',
            text: '',
            raw: [
                'media' => [
                    'kind' => 'ptt',
                    'data_base64' => base64_encode(str_repeat('y', 64)),
                ],
            ],
        );

        $out = app(VoiceNoteNormalizer::class)->normalize($msg);
        $this->assertNotNull($out['error']);
        $this->assertStringContainsString('voice note', (string) $out['error']);
    }

    public function test_bare_bot_ping_caption_does_not_prefix_transcript(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/transcribe' => Http::response([
                'text' => 'When is the next session?',
                'language' => 'en',
            ], 200),
        ]);

        $msg = new InboundMessage(
            channel: 'whatsapp_web_spike',
            externalUserId: '2348011111111',
            text: '@zak_bot',
            raw: [
                'quoted_voice' => true,
                'media' => [
                    'kind' => 'voice',
                    'data_base64' => base64_encode(str_repeat('z', 64)),
                ],
            ],
        );

        $out = app(VoiceNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertSame('When is the next session?', $out['message']->text);
        $this->assertStringNotContainsString('zak', mb_strtolower($out['message']->text));
    }
}
