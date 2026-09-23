<?php

declare(strict_types=1);

namespace App\Services\WebChat;

final class WebChatUrlBuilder
{
    public function __construct(
        private readonly WebChatMemberPhone $phones,
    ) {}

    /**
     * Member invite URL on the same host as Laravel (includes ?k= and ?p= when configured).
     */
    public function inviteUrl(?string $memberPhoneRaw = null): string
    {
        $base = rtrim((string) config('zak_presence.web_chat_url', config('app.url')), '/');
        $query = [];

        $accessKey = trim((string) config('zak_web_chat.access_key'));
        if ($accessKey !== '') {
            $query['k'] = $accessKey;
        }

        $phone = $this->phones->normalize($memberPhoneRaw);
        if ($phone !== null) {
            $query['p'] = $phone;
            $query['s'] = $this->phones->sessionIdFromPhone($phone);
        }

        if ($query === []) {
            return $base.'/';
        }

        return $base.'/?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
