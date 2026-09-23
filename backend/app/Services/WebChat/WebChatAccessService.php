<?php

declare(strict_types=1);

namespace App\Services\WebChat;

use App\Models\Community;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class WebChatAccessService
{
    public function isEnabled(): bool
    {
        return (bool) config('zak_web_chat.enabled', true);
    }

    public function mintAccessKey(string $communityId): string
    {
        $key = Str::lower(Str::random(32));
        $hours = (int) config('zak_web_chat.access_key_ttl_hours', 720);
        Cache::put($this->cacheKey($key), $communityId, now()->addHours($hours));

        return $key;
    }

    public function resolveCommunityId(?string $accessKey): string
    {
        abort_unless($this->isEnabled(), 503, 'Web chat is disabled.');

        $accessKey = trim((string) $accessKey);
        $defaultCommunity = trim((string) config('zak_web_chat.default_community_id'));

        if ($accessKey !== '') {
            $configured = trim((string) config('zak_web_chat.access_key'));
            if ($configured !== '' && hash_equals($configured, $accessKey) && $defaultCommunity !== '') {
                return $defaultCommunity;
            }

            $cached = Cache::get($this->cacheKey($accessKey));
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            abort(403, 'Invalid or expired chat link.');
        }

        if (filter_var(config('zak_web_chat.allow_open_access'), FILTER_VALIDATE_BOOLEAN) && $defaultCommunity !== '') {
            return $defaultCommunity;
        }

        abort(403, 'Missing chat access key. Open the link shared by your community admin.');
    }

    public function community(string $communityId): Community
    {
        $community = Community::query()->find($communityId);
        abort_if($community === null, 404, 'Community not found.');

        return $community;
    }

    public function actorUser(): User
    {
        $email = trim((string) config('zak_web_chat.actor_user_email'));
        abort_if($email === '', 503, 'Web chat actor user is not configured.');

        $user = User::query()->where('email', $email)->first();
        abort_if($user === null, 503, 'Web chat actor user was not found.');

        return $user;
    }

    public function assertActorCanAccessCommunity(User $user, string $communityId): void
    {
        $accessible = $user->accessibleCommunityIds();
        abort_unless(in_array($communityId, $accessible, true), 403, 'Actor cannot access this community.');
    }

    private function cacheKey(string $key): string
    {
        return 'web_chat_k:'.$key;
    }
}
