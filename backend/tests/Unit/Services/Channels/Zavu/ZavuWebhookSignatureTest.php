<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels\Zavu;

use App\Services\Channels\Zavu\ZavuWebhookSignature;
use Tests\TestCase;

class ZavuWebhookSignatureTest extends TestCase
{
    public function test_verifies_v2_signature(): void
    {
        $body = '{"id":"evt_1","type":"message.inbound"}';
        $secret = 'whsec_unit';
        $header = ZavuWebhookSignature::signV2($body, $secret);

        $ok = (new ZavuWebhookSignature)->verify($body, $header, $secret);

        $this->assertTrue($ok);
    }

    public function test_rejects_tampered_body(): void
    {
        $body = '{"id":"evt_1"}';
        $secret = 'whsec_unit';
        $header = ZavuWebhookSignature::signV2($body, $secret);

        $ok = (new ZavuWebhookSignature)->verify($body.'x', $header, $secret);

        $this->assertFalse($ok);
    }
}
