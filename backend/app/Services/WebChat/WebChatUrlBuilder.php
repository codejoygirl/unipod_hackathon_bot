<?php

declare(strict_types=1);

namespace App\Services\WebChat;

final class WebChatUrlBuilder
{
    public function __construct(
        private readonly WebChatMemberPhone $phones,
    ) {}

    /**
     * Member web chat URL: ?phone= digits (community from server config).
     */
    public function inviteUrl(?string $memberPhoneRaw = null): string
    {
        $base = rtrim((string) config('zak_presence.web_chat_url', config('app.url')), '/');
        $phone = $this->phones->normalize($memberPhoneRaw);
        if ($phone === null) {
            return $base.'/';
        }

        return $base.'/?'.http_build_query(['phone' => $phone], '', '&', PHP_QUERY_RFC3986);
    }
}
