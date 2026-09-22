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

    /*
    | Comma-separated admin phone numbers (digits; country code OK).
    | Example: 2348011111111,2348022222222
    */
    'admin_phones' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WHATSAPP_WEB_SPIKE_ADMIN_PHONES', ''))
    ))),

    /*
    | Bot mention aliases for group listen (comma-separated).
    | Always also listens for bot_number / bot_username when set.
    | Example: zak_bot
    */
    'bot_aliases' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WHATSAPP_WEB_SPIKE_BOT_ALIASES', 'zak_bot'))
    ))),

    /*
    | Linked WhatsApp account number (international digits preferred).
    | Group messages that mention this number (any common format) are listened to.
    */
    'bot_number' => env('WHATSAPP_WEB_SPIKE_BOT_NUMBER', ''),

    /*
    | Optional WhatsApp @username for the linked account (without @).
    */
    'bot_username' => env('WHATSAPP_WEB_SPIKE_BOT_USERNAME', ''),

    /*
    | Optional WhatsApp Linked ID (LID). Group @-mentions often use this instead of the phone.
    | Usually auto-sent by the spike worker; set only if you need a static fallback.
    */
    'bot_lid' => env('WHATSAPP_WEB_SPIKE_BOT_LID', ''),

    /*
    | Group listen mode:
    | - mention_or_command (default): reply in groups only when mentioned or commanded
    | - private_only / off: never reply in groups (Laravel-side; sidecar may still POST)
    */
    'group_listen' => env('WHATSAPP_WEB_SPIKE_GROUP_LISTEN', 'mention_or_command'),

    /*
    | Spike outbound HTTP (Laravel → worker) for admin escalation DMs.
    | Spike listens on 127.0.0.1:OUTBOUND_PORT. From Sail use host.docker.internal.
    */
    'outbound_url' => env('WHATSAPP_WEB_SPIKE_OUTBOUND_URL', 'http://host.docker.internal:3101'),
];
