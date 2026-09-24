<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels\Zavu;

use App\Services\Channels\Zavu\ZavuClient;
use App\Services\Channels\Zavu\ZavuInboundMediaHydrator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZavuInboundMediaHydratorTest extends TestCase
{
    public function test_resolves_media_url_from_message_id_then_downloads_bytes(): void
    {
        config([
            'whatsapp_zavu.api_key' => 'zv_test',
            'whatsapp_zavu.api_base' => 'https://api.zavu.dev/v1',
        ]);

        Http::fake([
            'https://api.zavu.dev/v1/messages/msg_voice_1' => Http::response([
                'messageId' => 'msg_voice_1',
                'content' => [
                    'mediaUrl' => 'https://cdn.zavu.dev/voice.ogg',
                    'mimeType' => 'audio/ogg',
                ],
            ], 200),
            'https://cdn.zavu.dev/voice.ogg' => Http::response('fake-audio-bytes', 200),
        ]);

        $hydrator = new ZavuInboundMediaHydrator(new ZavuClient);
        $raw = $hydrator->hydrate([
            'zavu_message_id' => 'msg_voice_1',
            'media' => [
                'kind' => 'voice',
                'mime_type' => 'audio/ogg',
                'media_id' => 'wa_123',
            ],
        ]);

        $this->assertSame(base64_encode('fake-audio-bytes'), $raw['media']['data_base64']);
    }
}
