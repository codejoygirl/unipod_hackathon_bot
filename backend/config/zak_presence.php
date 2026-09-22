<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Bot display name in member / admin chat copy
    |--------------------------------------------------------------------------
    |
    | Shown in intros, help, escalation cards, and approval notes.
    | Change once here (or via ZAK_BOT_DISPLAY_NAME) instead of hunting strings.
    |
    */
    'display_name' => env('ZAK_BOT_DISPLAY_NAME', 'Zak Bot'),

    /*
    |--------------------------------------------------------------------------
    | Where members can reach the bot (shown in short intros / help)
    |--------------------------------------------------------------------------
    |
    | Omit the channel the member is already on. Prefer real links when set.
    |
    */
    'web_chat_url' => env('ZAK_WEB_CHAT_URL', env('FRONTEND_URL', 'http://localhost:3000')),

    'web_chat_label' => env('ZAK_WEB_CHAT_LABEL', 'Web chat'),

    /** Include web chat in intros even when the UI is not live yet. */
    'show_web_chat' => filter_var(env('ZAK_SHOW_WEB_CHAT', true), FILTER_VALIDATE_BOOLEAN),

    /** Optional public Telegram bot handle, e.g. MyCommunityBot (with or without @). */
    'telegram_handle' => env('ZAK_TELEGRAM_HANDLE', ''),

    /** Optional full Telegram deep link; defaults to https://t.me/<handle> when handle is set. */
    'telegram_url' => env('ZAK_TELEGRAM_URL', ''),

    'telegram_label' => env('ZAK_TELEGRAM_LABEL', 'Telegram'),

    /** Optional public WhatsApp invite / click-to-chat link. */
    'whatsapp_url' => env('ZAK_WHATSAPP_URL', ''),

    'whatsapp_label' => env('ZAK_WHATSAPP_LABEL', 'WhatsApp'),
];
