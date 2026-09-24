<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels\Zavu;

use App\Services\Channels\Zavu\ZavuInboundPayloadMapper;
use Tests\TestCase;

class ZavuInboundPayloadMapperTest extends TestCase
{
    public function test_maps_zavu_content_audio_to_voice_media(): void
    {
        $mapper = new ZavuInboundPayloadMapper;
        $raw = $mapper->mapRaw([
            'messageId' => 'msg_voice_1',
            'from' => '+15551234567',
            'messageType' => 'audio',
            'text' => '',
            'content' => [
                'mediaId' => 'wa_media_99',
                'mimeType' => 'audio/ogg',
            ],
        ]);

        $this->assertSame('msg_voice_1', $raw['zavu_message_id']);
        $this->assertIsArray($raw['media']);
        $this->assertSame('voice', $raw['media']['kind']);
        $this->assertSame('audio/ogg', $raw['media']['mime_type']);
        $this->assertSame('wa_media_99', $raw['media']['media_id']);
        $this->assertTrue($mapper->hasMedia([
            'messageType' => 'audio',
            'content' => ['mediaId' => 'x', 'mimeType' => 'audio/ogg'],
        ]));
    }

    public function test_maps_zavu_content_image_with_caption(): void
    {
        $mapper = new ZavuInboundPayloadMapper;
        $raw = $mapper->mapRaw([
            'messageId' => 'msg_img_1',
            'from' => '+15551234567',
            'messageType' => 'image',
            'text' => 'What is this schedule?',
            'content' => [
                'mediaUrl' => 'https://cdn.zavu.dev/media/photo.jpg',
                'mimeType' => 'image/jpeg',
            ],
        ]);

        $this->assertSame('image', $raw['media']['kind']);
        $this->assertSame('https://cdn.zavu.dev/media/photo.jpg', $raw['media']['url']);
        $this->assertSame('What is this schedule?', $mapper->inboundText($raw));
    }

    public function test_maps_content_reply_to_fields(): void
    {
        $mapper = new ZavuInboundPayloadMapper;
        $raw = $mapper->mapRaw([
            'messageId' => 'msg_reply_1',
            'from' => '+15551234567',
            'messageType' => 'text',
            'text' => 'And the afternoon slot?',
            'content' => [
                'replyToText' => 'Victor morning, Jeli afternoon',
                'replyToMessageId' => 'msg_parent',
                'replyToFrom' => '+15559998888',
            ],
        ]);

        $this->assertSame('Victor morning, Jeli afternoon', $raw['quoted_text']);
        $this->assertSame('msg_parent', $raw['quoted_message_id']);
        $this->assertSame('+15559998888', $raw['quoted_from']);
    }
}
