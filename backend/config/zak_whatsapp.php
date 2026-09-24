<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp transports (Zavu Cloud vs Web spike can both be enabled locally)
    |--------------------------------------------------------------------------
    |
    | channel_key must match ChannelAdapter::channelName() for that transport.
    | Public web sign-in uses primary_transport when multiple are enabled.
    |
    */
    'primary_transport' => env('ZAK_WHATSAPP_PRIMARY', 'zavu'),

    /*
    | Show Web spike alongside Zavu in /help "Also reach me" and GET /public/reach.
    | Default false — members see one WhatsApp link (primary / Zavu).
    */
    'show_spike_in_reach' => (bool) env('ZAK_WHATSAPP_SHOW_SPIKE_IN_REACH', false),

    'transports' => [
        'zavu' => [
            'channel_key' => 'whatsapp_zavu',
            'enabled' => (bool) env('WHATSAPP_ZAVU', false),
            'phone' => env('WHATSAPP_ZAVU_PHONE', ''),
            'public_url' => env('ZAK_WHATSAPP_URL_ZAVU', ''),
            'reach_label' => env('ZAK_WHATSAPP_ZAVU_LABEL', ''),
        ],
        'web_spike' => [
            'channel_key' => 'whatsapp_web_spike',
            'enabled' => (bool) env('WHATSAPP_WEB_SPIKE', false),
            'phone' => env('WHATSAPP_WEB_SPIKE_BOT_NUMBER', ''),
            'public_url' => env('ZAK_WHATSAPP_URL_SPIKE', ''),
            'reach_label' => env('ZAK_WHATSAPP_SPIKE_LABEL', ''),
        ],
    ],

    /*
    | Legacy single link (optional). When set, used as override for primary transport only.
    | Prefer ZAK_WHATSAPP_URL_ZAVU / _SPIKE or phone fields per transport.
    */
    'legacy_public_url' => env('ZAK_WHATSAPP_URL', ''),
];
