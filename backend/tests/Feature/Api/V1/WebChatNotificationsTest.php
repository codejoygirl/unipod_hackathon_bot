<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\CommunityNotification;
use App\Models\CommunityNotificationRead;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\AdminCredentialsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class WebChatNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('zak_web_chat.enabled', true);
    }

    public function test_list_seeds_defaults_and_returns_unread_count(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $response = $this->getJson('/api/v1/web-chat/notifications?phone='.$phone)
            ->assertOk()
            ->assertJsonPath('data.community_id', $community->id);

        $this->assertGreaterThanOrEqual(5, count($response->json('data.notifications')));
        $this->assertSame(
            count($response->json('data.notifications')),
            (int) $response->json('data.unread_count'),
        );

        $this->assertDatabaseCount('community_notifications', 5);
    }

    public function test_mark_one_and_all_read(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348011111111';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $list = $this->getJson('/api/v1/web-chat/notifications?phone='.$phone)->assertOk();
        $firstId = (string) $list->json('data.notifications.0.id');

        $this->postJson('/api/v1/web-chat/notifications/'.$firstId.'/read?phone='.$phone)
            ->assertOk();

        $this->assertDatabaseHas('community_notification_reads', [
            'notification_id' => $firstId,
            'member_phone' => $phone,
        ]);

        $afterOne = $this->getJson('/api/v1/web-chat/notifications?phone='.$phone)->assertOk();
        $this->assertSame(
            ((int) $list->json('data.unread_count')) - 1,
            (int) $afterOne->json('data.unread_count'),
        );

        $this->postJson('/api/v1/web-chat/notifications/read-all?phone='.$phone)
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(
            CommunityNotification::query()->where('community_id', $community->id)->count(),
            CommunityNotificationRead::query()->where('member_phone', $phone)->count(),
        );
    }

    public function test_admin_can_publish_notification(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);
        Config::set('zak_web_chat.admin_logins', 'Lead Admin:+234 704 113 1371:secret');

        $token = app(AdminCredentialsService::class)->issueAdminToken($phone);

        $this->postJson('/api/v1/web-chat/notifications', [
            'phone' => $phone,
            'admin_token' => $token,
            'title' => 'Office hour moved',
            'message' => 'Tomorrow’s office hour starts at 5pm CAT.',
            'category' => 'Live Session',
            'action_query' => 'When is the next live session?',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Office hour moved');

        $this->assertDatabaseHas('community_notifications', [
            'community_id' => $community->id,
            'title' => 'Office hour moved',
            'category' => 'Live Session',
        ]);
    }

    public function test_member_cannot_publish(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2348099999999';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);
        Config::set('zak_web_chat.admin_logins', 'Lead Admin:+234 704 113 1371:secret');

        $this->postJson('/api/v1/web-chat/notifications', [
            'phone' => $phone,
            'title' => 'Spam',
            'message' => 'Nope',
        ])->assertForbidden();
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
