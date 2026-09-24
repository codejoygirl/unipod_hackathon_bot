<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\DTOs\Channels\InboundMessage;
use App\Services\AI\AiServiceClient;
use App\Services\Channels\ImageNoteNormalizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImageNoteNormalizerTest extends TestCase
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

        $out = app(ImageNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertSame('When is clinic?', $out['message']->text);
        Http::assertNothingSent();
    }

    public function test_leaves_voice_media_to_voice_normalizer(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);
        Http::fake();

        $msg = new InboundMessage(
            channel: 'telegram_spike',
            externalUserId: '99',
            text: '',
            raw: [
                'media' => [
                    'kind' => 'voice',
                    'mime_type' => 'audio/ogg',
                    'data_base64' => base64_encode(str_repeat('x', 64)),
                ],
            ],
        );

        $out = app(ImageNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertSame('', $out['message']->text);
        Http::assertNothingSent();
    }

    public function test_understands_image_and_drops_media(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/understand-image' => Http::response([
                'text' => 'When is the next session?',
            ], 200),
        ]);

        $msg = new InboundMessage(
            channel: 'web_chat',
            externalUserId: '2348011111111',
            text: '',
            raw: [
                'media' => [
                    'kind' => 'photo',
                    'mime_type' => 'image/jpeg',
                    'filename' => 'flyer.jpg',
                    'data_base64' => base64_encode(str_repeat('x', 64)),
                ],
            ],
        );

        $out = app(ImageNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertSame('When is the next session?', $out['message']->text);
        $this->assertSame('image', $out['message']->raw['input_modality'] ?? null);
        $this->assertArrayNotHasKey('media', $out['message']->raw);
    }

    public function test_understands_multiple_web_chat_images_in_one_call(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/understand-image' => Http::response([
                'text' => "flyer.jpg: Hackathon ends Friday\n\nschedule.png: Clinic Saturday 9am",
            ], 200),
        ]);

        $msg = new InboundMessage(
            channel: 'web_chat',
            externalUserId: '2348011111111',
            text: 'What do these say?',
            raw: [
                'images' => [
                    [
                        'data_base64' => base64_encode(str_repeat('a', 64)),
                        'mime_type' => 'image/jpeg',
                        'filename' => 'flyer.jpg',
                    ],
                    [
                        'data_base64' => base64_encode(str_repeat('b', 64)),
                        'mime_type' => 'image/png',
                        'filename' => 'schedule.png',
                    ],
                ],
            ],
        );

        $out = app(ImageNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertStringContainsString('Hackathon ends Friday', $out['message']->text);
        Http::assertSentCount(1);
    }

    public function test_merges_caption_when_extract_omits_it(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/understand-image' => Http::response([
                'text' => 'Saturday 9am clinic hours poster',
            ], 200),
        ]);

        $msg = new InboundMessage(
            channel: 'telegram_spike',
            externalUserId: '99',
            text: 'When is this?',
            raw: [
                'media' => [
                    'kind' => 'image',
                    'mime_type' => 'image/png',
                    'data_base64' => base64_encode(str_repeat('z', 64)),
                ],
            ],
        );

        $out = app(ImageNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertStringContainsString('When is this?', $out['message']->text);
        $this->assertStringContainsString('Saturday 9am', $out['message']->text);
    }

    public function test_rejected_mime_returns_polite_error(): void
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
            text: '',
            raw: [
                'media' => [
                    'kind' => 'image',
                    'mime_type' => 'image/heic',
                    'data_base64' => base64_encode(str_repeat('y', 64)),
                ],
            ],
        );

        $out = app(ImageNoteNormalizer::class)->normalize($msg);
        $this->assertNotNull($out['error']);
        $this->assertStringContainsString('photo', mb_strtolower((string) $out['error']));
        Http::assertNothingSent();
    }

    public function test_empty_extract_returns_polite_error(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/understand-image' => Http::response([
                'text' => '',
            ], 200),
        ]);

        $msg = new InboundMessage(
            channel: 'whatsapp_web_spike',
            externalUserId: '2348011111111',
            text: '',
            raw: [
                'media' => [
                    'kind' => 'image',
                    'mime_type' => 'image/jpeg',
                    'data_base64' => base64_encode(str_repeat('y', 64)),
                ],
            ],
        );

        $out = app(ImageNoteNormalizer::class)->normalize($msg);
        $this->assertNotNull($out['error']);
        $this->assertStringContainsString('photo', mb_strtolower((string) $out['error']));
    }

    public function test_empty_extract_uses_caption_when_member_typed_a_question(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/understand-image' => Http::response([
                'text' => '',
            ], 200),
        ]);

        $msg = new InboundMessage(
            channel: 'web_chat',
            externalUserId: '2348011111111',
            text: 'When is the hackathon deadline?',
            raw: [
                'media' => [
                    'kind' => 'image',
                    'mime_type' => 'image/jpeg',
                    'data_base64' => base64_encode(str_repeat('y', 64)),
                ],
            ],
        );

        $out = app(ImageNoteNormalizer::class)->normalize($msg);
        $this->assertNull($out['error']);
        $this->assertSame('When is the hackathon deadline?', $out['message']->text);
        $this->assertSame('image', $out['message']->raw['input_modality'] ?? null);
    }
}
