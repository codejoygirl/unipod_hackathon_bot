<?php

declare(strict_types=1);

return [

    'enabled' => filter_var(env('ZAK_WEB_CHAT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /** Member URLs use ?phone=<digits>. Falls back to channel spike community IDs when unset. */
    'default_community_id' => env('ZAK_WEB_CHAT_DEFAULT_COMMUNITY_ID')
        ?: env('WHATSAPP_ZAVU_DEFAULT_COMMUNITY_ID')
        ?: env('TELEGRAM_SPIKE_DEFAULT_COMMUNITY_ID')
        ?: env('WHATSAPP_WEB_SPIKE_DEFAULT_COMMUNITY_ID'),

    /** Optional legacy server-side keys (not placed in member URLs). */
    'access_key' => env('ZAK_WEB_CHAT_ACCESS_KEY'),

    /**
     * Laravel user whose community permissions apply to grounded answers (same idea as channel spike defaults).
     */
    'actor_user_email' => env(
        'ZAK_WEB_CHAT_ACTOR_USER_EMAIL',
        env('WHATSAPP_ZAVU_DEFAULT_USER_EMAIL', env('TELEGRAM_SPIKE_DEFAULT_USER_EMAIL', 'demo@zak.test')),
    ),

    'access_key_ttl_hours' => (int) env('ZAK_WEB_CHAT_ACCESS_KEY_TTL_HOURS', 720),

    /** Admin logins CSV format: Name:+Phone:Password,... */
    'admin_logins' => env('ZAK_ADMIN_LOGINS'),

    /** Web Push (VAPID). Generate with: php artisan zak:vapid-generate */
    'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
    'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
    'vapid_subject' => env('VAPID_SUBJECT', 'mailto:abdulsamadbalogun25@gmail.com'),

];
