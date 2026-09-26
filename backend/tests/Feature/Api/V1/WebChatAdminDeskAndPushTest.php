<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Channels\AdminCredentialsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WebChatAdminDeskAndPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('zak_web_chat.enabled', true);
    }

    private function fakeAi(): void
    {
        Config::set('ai_service.base_url', 'http://ai.test');
        Config::set('ai_service.hmac_secret', 'test-secret');
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-asset',
                'version_id' => 'ai-ver-asset',
            ], 200),
        ]);
    }

    public function test_meetings_list_seeds_defaults(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $response = $this->getJson('/api/v1/web-chat/meetings?phone='.$phone)
            ->assertOk()
            ->assertJsonPath('data.community_id', $community->id);

        $this->assertGreaterThanOrEqual(3, count($response->json('data.meetings')));
        $this->assertDatabaseCount('community_meetings', 3);
    }

    public function test_admin_can_publish_meeting(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);
        Config::set('zak_web_chat.admin_logins', 'Lead Admin:+234 704 113 1371:secret');

        $token = app(AdminCredentialsService::class)->issueAdminToken($phone);

        $this->postJson('/api/v1/web-chat/admin/meetings', [
            'phone' => $phone,
            'admin_token' => $token,
            'title' => 'Extra office hour',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'platform' => 'meet',
            'category' => 'office_hours',
            'schedule' => 'Monday 10am GMT',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Extra office hour')
            ->assertJsonPath('data.platform', 'meet');

        $this->assertDatabaseHas('community_meetings', [
            'community_id' => $community->id,
            'title' => 'Extra office hour',
        ]);
    }

    public function test_admin_can_register_asset(): void
    {
        $this->fakeAi();

        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);
        Config::set('zak_web_chat.admin_logins', 'Lead Admin:+234 704 113 1371:secret');

        $token = app(AdminCredentialsService::class)->issueAdminToken($phone);

        $this->postJson('/api/v1/web-chat/admin/assets', [
            'phone' => $phone,
            'admin_token' => $token,
            'kind' => 'handbook',
            'title' => 'UniPods Handbook',
            'url' => 'https://drive.google.com/file/d/1q1wsNCblgit9s7nwYeGKsOpkTIyPm6N/view',
        ])
            ->assertCreated()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.identity', 'gdrive:1q1wsncblgit9s7nwyegksopktiypm6n');
    }

    public function test_member_cannot_register_asset(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348099999999';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);
        Config::set('zak_web_chat.admin_logins', 'Lead Admin:+234 704 113 1371:secret');

        $this->postJson('/api/v1/web-chat/admin/assets', [
            'phone' => $phone,
            'kind' => 'handbook',
            'title' => 'Nope',
            'url' => 'https://example.com/doc',
        ])->assertForbidden();
    }

    public function test_push_subscribe_and_public_key(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348011222333';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);
        Config::set('zak_web_chat.vapid_public_key', 'BPtestpublickeybase64urllookingstringXXXX');
        Config::set('zak_web_chat.vapid_private_key', 'testprivatekey');

        $this->getJson('/api/v1/web-chat/push/vapid-public-key')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.public_key', 'BPtestpublickeybase64urllookingstringXXXX');

        $this->postJson('/api/v1/web-chat/push/subscribe', [
            'phone' => $phone,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-1',
            'keys' => [
                'p256dh' => 'p256dh-test-key',
                'auth' => 'auth-test-key',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.subscribed', true);

        $this->assertDatabaseHas('web_push_subscriptions', [
            'community_id' => $community->id,
            'member_phone' => $phone,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-1',
        ]);

        $this->postJson('/api/v1/web-chat/push/unsubscribe', [
            'phone' => $phone,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.subscribed', false);

        $this->assertDatabaseMissing('web_push_subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-1',
        ]);
    }

    public function test_publish_notification_does_not_fail_without_vapid(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);
        Config::set('zak_web_chat.admin_logins', 'Lead Admin:+234 704 113 1371:secret');
        Config::set('zak_web_chat.vapid_public_key', null);
        Config::set('zak_web_chat.vapid_private_key', null);

        $token = app(AdminCredentialsService::class)->issueAdminToken($phone);

        WebPushSubscription::query()->create([
            'community_id' => $community->id,
            'member_phone' => $phone,
            'endpoint' => 'https://example.com/push/1',
            'public_key' => 'pk',
            'auth_token' => 'at',
        ]);

        $this->postJson('/api/v1/web-chat/notifications', [
            'phone' => $phone,
            'admin_token' => $token,
            'title' => 'Still works',
            'message' => 'Push skipped when VAPID missing.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Still works');
    }

    /**
     * @return array{0: User, 1: Community}
     */
    private function seedMember(): array
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        return [$user, $community];
    }
}
