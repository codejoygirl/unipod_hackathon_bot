<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot spike (DEV / hackathon ONLY)
    |--------------------------------------------------------------------------
    |
    | Official Telegram Bot API via a sidecar worker. NOT production channel
    | productization (align later with Phase 4). Disabled unless TELEGRAM_SPIKE=true.
    | See infrastructure/telegram-spike/README.md.
    |
    | Security notes for this spike:
    | - TELEGRAM_SPIKE_SECRET authenticates the sidecar only; keep it high-entropy
    |   and never expose the inbound route publicly without network ACL.
    | - default_user_email is a shared Zak identity for all Telegram senders
    |   (not per-member auth). Use a demo account with limited memberships.
    | - Admin escalation trusts inbound "from" matching admin_chat_id; treat as
    |   hackathon-only until Phase 4 channel identity exists.
    |
    */
    'enabled' => (bool) env('TELEGRAM_SPIKE', false),

    'shared_secret' => env('TELEGRAM_SPIKE_SECRET', ''),

    'default_user_email' => env('TELEGRAM_SPIKE_DEFAULT_USER_EMAIL', 'demo@zak.test'),

    'default_community_id' => env('TELEGRAM_SPIKE_DEFAULT_COMMUNITY_ID'),

    'join_token_ttl_hours' => (int) env('TELEGRAM_SPIKE_JOIN_TTL_HOURS', 72),

    /*
    | Optional reply-language override for grounded asks (ISO code, e.g. fr).
    | Leave unset so the AI detects the member's language each turn.
    */
    'default_target_language' => env('TELEGRAM_SPIKE_TARGET_LANGUAGE'),

    /*
    | Bot token used only to DM the admin when a question escalates.
    | Prefer the same token as infrastructure/telegram-spike/.env.
    */
    'bot_token' => env('TELEGRAM_SPIKE_BOT_TOKEN', env('TELEGRAM_BOT_TOKEN', '')),

    /*
    | Who to notify. Contact is the human-facing identifier (phone for now).
    | chat_id is required for Telegram Bot API delivery (numeric Telegram user/chat id).
    */
    'admin_contact' => env('TELEGRAM_SPIKE_ADMIN_CONTACT', ''),

    'admin_chat_id' => env('TELEGRAM_SPIKE_ADMIN_CHAT_ID', ''),

    /*
    | Reply text styling for Telegram.
    | plain (default): strip markdown bold/italic markers so free clients
    | do not show raw asterisks.
    | html: leave emphasis markers for the spike bot to render with parse_mode=HTML.
    */
    'formatting' => env('TELEGRAM_SPIKE_FORMATTING', 'plain'),

    /*
    | true (default): handle inbound in-request and return data.reply (local spike DX).
    | false: enqueue ProcessTelegramSpikeInbound on zak.queues.channels; spike gets 202
    | accepted and Laravel sends via Bot API from the worker.
    */
    'process_sync' => (bool) env('TELEGRAM_SPIKE_PROCESS_SYNC', true),
];
