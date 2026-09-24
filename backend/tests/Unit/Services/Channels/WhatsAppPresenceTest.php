<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\Services\Channels\WhatsAppPresence;
use Tests\TestCase;

class WhatsAppPresenceTest extends TestCase
{
    public function test_url_for_inbound_channel_uses_matching_transport(): void
    {
        config([
            'zak_presence.whatsapp_url' => '',
            'zak_whatsapp.legacy_public_url' => '',
            'zak_whatsapp.transports.zavu.enabled' => true,
            'zak_whatsapp.transports.zavu.phone' => '+2348011111111',
            'zak_whatsapp.transports.web_spike.enabled' => true,
            'zak_whatsapp.transports.web_spike.phone' => '2347099999999',
        ]);

        $presence = new WhatsAppPresence;

        $zavu = $presence->urlForInboundChannel('whatsapp_zavu');
        $spike = $presence->urlForInboundChannel('whatsapp_web_spike');

        $this->assertStringContainsString('2348011111111', $zavu);
        $this->assertStringContainsString('2347099999999', $spike);
        $this->assertNotSame($zavu, $spike);
    }

    public function test_enabled_reach_entries_lists_both_transports(): void
    {
        config([
            'zak_presence.whatsapp_url' => '',
            'zak_whatsapp.legacy_public_url' => '',
            'zak_whatsapp.transports.zavu.enabled' => true,
            'zak_whatsapp.transports.zavu.phone' => '+2348011111111',
            'zak_whatsapp.transports.web_spike.enabled' => true,
            'zak_whatsapp.transports.web_spike.phone' => '2347099999999',
            'zak_presence.whatsapp_label' => 'WhatsApp',
        ]);

        $entries = (new WhatsAppPresence)->enabledReachEntries();
        $this->assertCount(2, $entries);

        $public = (new WhatsAppPresence)->memberFacingReachEntries();
        $this->assertCount(1, $public);
        $this->assertSame('WhatsApp', $public[0]['label']);
        $this->assertStringContainsString('2348011111111', $public[0]['url']);
        $this->assertSame('zavu', $public[0]['transport_id']);
        $phones = array_map(static fn (array $e): string => $e['url'], $entries);
        $this->assertTrue(
            str_contains($phones[0], '2348011111111') || str_contains($phones[1], '2348011111111')
        );
        $this->assertTrue(
            str_contains($phones[0], '2347099999999') || str_contains($phones[1], '2347099999999')
        );
    }

    public function test_member_facing_prefers_zavu_not_legacy_spike_url(): void
    {
        config([
            'zak_whatsapp.primary_transport' => 'zavu',
            'zak_whatsapp.show_spike_in_reach' => false,
            'zak_whatsapp.legacy_public_url' => '',
            'zak_whatsapp.transports.zavu.enabled' => true,
            'zak_whatsapp.transports.zavu.phone' => '+2348011111111',
            'zak_whatsapp.transports.web_spike.enabled' => true,
            'zak_whatsapp.transports.web_spike.phone' => '2347041131371',
            'zak_presence.whatsapp_url' => 'https://wa.me/2347041131371',
        ]);

        $public = (new WhatsAppPresence)->memberFacingReachEntries(true);
        $this->assertCount(1, $public);
        $this->assertSame('WhatsApp', $public[0]['label']);
        $this->assertStringContainsString('2348011111111', $public[0]['url']);
        $this->assertStringNotContainsString('2347041131371', $public[0]['url']);
        $this->assertStringNotContainsString('webspike', mb_strtolower($public[0]['label']));
    }
}
