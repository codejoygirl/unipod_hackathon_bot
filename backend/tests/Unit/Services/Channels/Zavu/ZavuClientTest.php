<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels\Zavu;

use App\Services\Channels\Zavu\ZavuClient;
use PHPUnit\Framework\TestCase;

class ZavuClientTest extends TestCase
{
    public function test_normalize_whatsapp_to_adds_plus_prefix(): void
    {
        $this->assertSame('+2348117084647', ZavuClient::normalizeWhatsAppTo('2348117084647'));
        $this->assertSame('+2348117084647', ZavuClient::normalizeWhatsAppTo('+2348117084647'));
    }
}
