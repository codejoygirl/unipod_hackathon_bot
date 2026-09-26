<?php

declare(strict_types=1);

namespace App\Services\WebChat;

use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

final class WebPushService
{
    public function isConfigured(): bool
    {
        $public = trim((string) config('zak_web_chat.vapid_public_key', ''));
        $private = trim((string) config('zak_web_chat.vapid_private_key', ''));

        return $public !== '' && $private !== '';
    }

    public function publicKey(): ?string
    {
        $key = trim((string) config('zak_web_chat.vapid_public_key', ''));

        return $key !== '' ? $key : null;
    }

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string}  $subscription
     */
    public function upsertSubscription(
        string $communityId,
        string $memberPhone,
        array $subscription,
        ?string $userAgent = null,
    ): WebPushSubscription {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $p256dh = trim((string) ($subscription['keys']['p256dh'] ?? ''));
        $auth = trim((string) ($subscription['keys']['auth'] ?? ''));

        return WebPushSubscription::query()->updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'community_id' => $communityId,
                'member_phone' => $memberPhone,
                'public_key' => $p256dh,
                'auth_token' => $auth,
                'content_encoding' => trim((string) ($subscription['contentEncoding'] ?? 'aes128gcm')) ?: 'aes128gcm',
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'last_seen_at' => now(),
            ],
        );
    }

    public function removeByEndpoint(string $endpoint): void
    {
        WebPushSubscription::query()->where('endpoint', $endpoint)->delete();
    }

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string, unread?: int}  $payload
     */
    public function notifyCommunity(string $communityId, array $payload): int
    {
        if (! $this->isConfigured()) {
            Log::info('web_push.skip_unconfigured', ['community_id' => $communityId]);

            return 0;
        }

        $subs = WebPushSubscription::query()
            ->where('community_id', $communityId)
            ->get();

        if ($subs->isEmpty()) {
            return 0;
        }

        $auth = [
            'VAPID' => [
                'subject' => (string) config('zak_web_chat.vapid_subject', 'mailto:abdulsamadbalogun25@gmail.com'),
                'publicKey' => (string) config('zak_web_chat.vapid_public_key'),
                'privateKey' => (string) config('zak_web_chat.vapid_private_key'),
            ],
        ];

        try {
            $webPush = new WebPush($auth);
        } catch (Throwable $e) {
            Log::warning('web_push.init_failed', ['error' => $e->getMessage()]);

            return 0;
        }

        $json = json_encode([
            'title' => $payload['title'],
            'body' => $payload['body'],
            'url' => $payload['url'] ?? '/?notifications=1',
            'tag' => $payload['tag'] ?? 'zak-update',
            'unread' => $payload['unread'] ?? 1,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sent = 0;
        foreach ($subs as $sub) {
            try {
                $report = $webPush->sendOneNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'publicKey' => $sub->public_key,
                        'authToken' => $sub->auth_token,
                        'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                    ]),
                    $json ?: '{}',
                );
                if ($report->isSuccess()) {
                    $sent++;
                    $sub->forceFill(['last_seen_at' => now()])->save();
                } elseif ($report->isSubscriptionExpired()) {
                    $sub->delete();
                }
            } catch (Throwable $e) {
                Log::warning('web_push.send_failed', [
                    'subscription_id' => $sub->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $webPush->flush();
        } catch (Throwable) {
            // ignore flush errors
        }

        return $sent;
    }
}
