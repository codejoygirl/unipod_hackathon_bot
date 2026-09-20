<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp Web automation spike (DEV ONLY)
    |--------------------------------------------------------------------------
    |
    | Unofficial WA Web session (whatsapp-web.js). NOT for production. Disabled unless
    | WHATSAPP_WEB_SPIKE=true. See infrastructure/whatsapp-web-spike/README.md.
    |
    */
    'enabled' => (bool) env('WHATSAPP_WEB_SPIKE', false),

    'shared_secret' => env('WHATSAPP_WEB_SPIKE_SECRET', ''),

    /*
    | Default identity when an inbound phone is not linked yet.
    | Spike convenience — replace with JOIN tokens in 4W.3.
    */
    'default_user_email' => env('WHATSAPP_WEB_SPIKE_DEFAULT_USER_EMAIL', 'demo@zak.test'),

    'default_community_id' => env('WHATSAPP_WEB_SPIKE_DEFAULT_COMMUNITY_ID'),

    'join_token_ttl_hours' => (int) env('WHATSAPP_WEB_SPIKE_JOIN_TTL_HOURS', 72),
];
