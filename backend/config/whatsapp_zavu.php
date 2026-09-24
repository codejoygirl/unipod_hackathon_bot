<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp via Zavu (official BSP → Cloud API)
    |--------------------------------------------------------------------------
    |
    | Production-oriented WhatsApp path. Independent of WHATSAPP_WEB_SPIKE and
    | TELEGRAM_SPIKE; leave those off in real environments.
    |
    */
    'enabled' => (bool) env('WHATSAPP_ZAVU', false),

    'api_key' => env('WHATSAPP_ZAVU_API_KEY', ''),

    'webhook_secret' => env('WHATSAPP_ZAVU_WEBHOOK_SECRET', ''),

    'api_base' => rtrim((string) env('WHATSAPP_ZAVU_API_BASE', 'https://api.zavu.dev/v1'), '/'),

    /*
    | Admin mint endpoint (JOIN tokens). Same pattern as channel spikes.
    */
    'admin_secret' => env('WHATSAPP_ZAVU_ADMIN_SECRET', ''),

    'default_user_email' => env('WHATSAPP_ZAVU_DEFAULT_USER_EMAIL', 'demo@zak.test'),

    'default_community_id' => env('WHATSAPP_ZAVU_DEFAULT_COMMUNITY_ID'),

    'join_token_ttl_hours' => (int) env('WHATSAPP_ZAVU_JOIN_TTL_HOURS', 72),

    /*
    | Business number for wa.me deep links (E.164 digits without + is fine).
    */
    'phone_number' => env('WHATSAPP_ZAVU_PHONE', ''),

    /*
    | Admin senders (E.164 digits). Defaults to WHATSAPP_WEB_SPIKE_ADMIN_PHONES when unset.
    */
    'admin_phones' => env('WHATSAPP_ZAVU_ADMIN_PHONES', ''),

    /*
    | When true, handle inbound in-request (local/dev). Prefer queue + afterResponse
    | in production so Zavu gets 200 within 30s.
    */
    'process_sync' => (bool) env('WHATSAPP_ZAVU_PROCESS_SYNC', false),

    'webhook_max_age_seconds' => (int) env('WHATSAPP_ZAVU_WEBHOOK_MAX_AGE', 300),

    /*
    | POST /v1/messages/{id}/typing while preparing LLM replies (WhatsApp only).
    */
    'typing_indicator' => (bool) env('WHATSAPP_ZAVU_TYPING_INDICATOR', true),
];
