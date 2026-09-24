<?php

declare(strict_types=1);

namespace App\Services\Channels;

/**
 * Per-transport WhatsApp numbers and wa.me links (Zavu vs Web spike can both be active).
 */
final class WhatsAppPresence
{
    public const TRANSPORT_ZAVU = 'zavu';

    public const TRANSPORT_WEB_SPIKE = 'web_spike';

    /**
     * @return list<string>
     */
    public function enabledTransportIds(): array
    {
        $ids = [];
        foreach ($this->transportConfigs() as $id => $cfg) {
            if ($this->transportEnabled($cfg)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function channelKeyToTransportId(string $channelKey): ?string
    {
        foreach ($this->transportConfigs() as $id => $cfg) {
            if (($cfg['channel_key'] ?? '') === $channelKey) {
                return $id;
            }
        }

        return null;
    }

    public function urlForInboundChannel(?string $channelKey, bool $preferClickable = false): string
    {
        if ($channelKey === null || $channelKey === '') {
            return $this->primaryPublicUrl($preferClickable);
        }

        $transportId = $this->channelKeyToTransportId($channelKey);
        if ($transportId === null) {
            return $this->primaryPublicUrl($preferClickable);
        }

        return $this->urlForTransport($transportId, $preferClickable);
    }

    public function urlForTransport(string $transportId, bool $preferClickable = false): string
    {
        $cfg = $this->transportConfigs()[$transportId] ?? null;
        if (! is_array($cfg) || ! $this->transportEnabled($cfg)) {
            return '';
        }

        $raw = trim((string) ($cfg['public_url'] ?? ''));

        if ($raw === '') {
            $digits = preg_replace('/\D+/', '', (string) ($cfg['phone'] ?? '')) ?? '';
            if ($digits !== '') {
                $raw = 'https://wa.me/'.$digits;
            }
        }

        if ($raw === '' && $transportId === self::TRANSPORT_ZAVU) {
            $legacy = trim((string) config('zak_whatsapp.legacy_public_url', ''));
            if ($legacy === '') {
                $legacy = trim((string) config('zak_presence.whatsapp_url', ''));
            }
            $primary = (string) config('zak_whatsapp.primary_transport', self::TRANSPORT_ZAVU);
            if ($legacy !== '' && $primary === self::TRANSPORT_ZAVU) {
                $raw = $legacy;
            }
        }

        if ($raw === '') {
            return '';
        }

        return app(ChannelConversationService::class)->applyWhatsAppInviteParams($raw, $preferClickable);
    }

    public function primaryPublicUrl(bool $preferClickable = false): string
    {
        $primary = (string) config('zak_whatsapp.primary_transport', self::TRANSPORT_ZAVU);
        $url = $this->urlForTransport($primary, $preferClickable);
        if ($url !== '') {
            return $url;
        }

        foreach ($this->enabledTransportIds() as $id) {
            $url = $this->urlForTransport($id, $preferClickable);
            if ($url !== '') {
                return $url;
            }
        }

        $legacy = trim((string) config('zak_presence.whatsapp_url', ''));
        if ($legacy !== '') {
            return app(ChannelConversationService::class)->applyWhatsAppInviteParams($legacy, $preferClickable);
        }

        return '';
    }

    /**
     * @return list<array{transport_id: string, label: string, url: string, channel_key: string}>
     */
    public function enabledReachEntries(bool $preferClickable = false): array
    {
        $baseLabel = (string) config('zak_presence.whatsapp_label', 'WhatsApp');
        $entries = [];

        foreach ($this->enabledTransportIds() as $transportId) {
            $url = $this->urlForTransport($transportId, $preferClickable);
            if ($url === '') {
                continue;
            }
            $cfg = $this->transportConfigs()[$transportId];
            $suffix = trim((string) ($cfg['reach_label'] ?? ''));
            $label = $suffix !== '' ? $baseLabel.' '.$suffix : $baseLabel;
            if (count($this->enabledTransportIds()) > 1 && $suffix === '') {
                $label = $baseLabel.' ('.$transportId.')';
            }

            $entries[] = [
                'transport_id' => $transportId,
                'label' => $label,
                'url' => $url,
                'channel_key' => (string) ($cfg['channel_key'] ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * Member-facing reach: one WhatsApp link (primary / Zavu). Web spike is never listed here
     * unless ZAK_WHATSAPP_SHOW_SPIKE_IN_REACH=true (local dev only).
     *
     * @return list<array{transport_id: string, label: string, url: string, channel_key: string}>
     */
    public function memberFacingReachEntries(bool $preferClickable = false): array
    {
        $entries = [];
        $primary = $this->singleMemberWhatsAppReach($preferClickable);
        if ($primary !== null) {
            $entries[] = $primary;
        }

        $showSpike = filter_var(config('zak_whatsapp.show_spike_in_reach', false), FILTER_VALIDATE_BOOLEAN);
        if ($showSpike) {
            $spikeUrl = $this->urlForTransport(self::TRANSPORT_WEB_SPIKE, $preferClickable);
            if ($spikeUrl !== '') {
                $cfg = $this->transportConfigs()[self::TRANSPORT_WEB_SPIKE] ?? [];
                $entries[] = [
                    'transport_id' => self::TRANSPORT_WEB_SPIKE,
                    'label' => (string) config('zak_presence.whatsapp_label', 'WhatsApp').' (web spike)',
                    'url' => $spikeUrl,
                    'channel_key' => (string) ($cfg['channel_key'] ?? ''),
                ];
            }
        }

        return $entries;
    }

    /**
     * @return array{transport_id: string, label: string, url: string, channel_key: string}|null
     */
    private function singleMemberWhatsAppReach(bool $preferClickable): ?array
    {
        $primaryId = (string) config('zak_whatsapp.primary_transport', self::TRANSPORT_ZAVU);
        $tryOrder = [$primaryId, self::TRANSPORT_ZAVU];
        foreach ($this->enabledTransportIds() as $transportId) {
            if ($transportId !== self::TRANSPORT_WEB_SPIKE) {
                $tryOrder[] = $transportId;
            }
        }
        $tryOrder = array_values(array_unique($tryOrder));

        foreach ($tryOrder as $transportId) {
            if ($transportId === self::TRANSPORT_WEB_SPIKE) {
                continue;
            }
            $url = $this->urlForTransport($transportId, $preferClickable);
            if ($url === '') {
                continue;
            }
            $cfg = $this->transportConfigs()[$transportId] ?? [];

            return [
                'transport_id' => $transportId,
                'label' => (string) config('zak_presence.whatsapp_label', 'WhatsApp'),
                'url' => $url,
                'channel_key' => (string) ($cfg['channel_key'] ?? ''),
            ];
        }

        $legacy = trim((string) config('zak_presence.whatsapp_url', ''));
        if ($legacy === '') {
            $legacy = trim((string) config('zak_whatsapp.legacy_public_url', ''));
        }
        if ($legacy === '') {
            return null;
        }

        $cfg = $this->transportConfigs()[$primaryId] ?? [];

        return [
            'transport_id' => $primaryId,
            'label' => (string) config('zak_presence.whatsapp_label', 'WhatsApp'),
            'url' => app(ChannelConversationService::class)->applyWhatsAppInviteParams($legacy, $preferClickable),
            'channel_key' => (string) ($cfg['channel_key'] ?? 'whatsapp_zavu'),
        ];
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function transportEnabled(array $cfg): bool
    {
        return filter_var($cfg['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function transportConfigs(): array
    {
        $transports = config('zak_whatsapp.transports');

        return is_array($transports) ? $transports : [];
    }
}
