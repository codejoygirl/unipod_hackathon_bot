<?php

declare(strict_types=1);

return [

    'enabled' => filter_var(env('ZAK_WEB_CHAT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /**
     * Shared secret in invite links (?k=). When set with default_community_id, members can open chat without sign-in.
     */
    'access_key' => env('ZAK_WEB_CHAT_ACCESS_KEY'),

    'default_community_id' => env('ZAK_WEB_CHAT_DEFAULT_COMMUNITY_ID'),

    /**
     * Laravel user whose community permissions apply to grounded answers (same idea as channel spike defaults).
     */
    'actor_user_email' => env(
        'ZAK_WEB_CHAT_ACTOR_USER_EMAIL',
        env('WHATSAPP_ZAVU_DEFAULT_USER_EMAIL', env('TELEGRAM_SPIKE_DEFAULT_USER_EMAIL', 'demo@zak.test')),
    ),

    /** Allow chat when k is omitted (local/demo only). Never enable in production. */
    'allow_open_access' => filter_var(env('ZAK_WEB_CHAT_ALLOW_OPEN_ACCESS', false), FILTER_VALIDATE_BOOLEAN),

    'access_key_ttl_hours' => (int) env('ZAK_WEB_CHAT_ACCESS_KEY_TTL_HOURS', 720),

    /** Require ?p= (E.164 digits) on bootstrap/ask so each member is identifiable. */
    'require_member_phone' => filter_var(env('ZAK_WEB_CHAT_REQUIRE_PHONE', true), FILTER_VALIDATE_BOOLEAN),

];
