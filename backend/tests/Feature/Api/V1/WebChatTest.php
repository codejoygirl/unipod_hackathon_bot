<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WebChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('zak_web_chat.enabled', true);
        Config::set('zak_web_chat.require_member_phone', false);
        Config::set('zak_web_chat.allow_open_access', true);
    }

    public function test_bootstrap_and_ask_without_sign_in(): void
    {
        Http::fake([
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'Hello',
                'detected_language' => 'en',
                'execution_time_ms' => 5.0,
                'total_chunks_retrieved' => 0,
                'validated_payload' => [
                    'state' => 'INSUFFICIENT_EVIDENCE',
                    'answer' => 'No sources found.',
                    'confidence_score' => 0.1,
                    'needs_escalation' => true,
                    'escalation_reason' => null,
                    'citations' => [],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        [$user, $community] = $this->seedMember();

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $session = 'testsess01';

        $this->getJson('/api/v1/web-chat/bootstrap?s='.$session)
            ->assertOk()
            ->assertJsonPath('data.community.id', $community->id);

        $this->postJson('/api/v1/web-chat/ask', [
            's' => $session,
            'query' => 'Hello',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'INSUFFICIENT_EVIDENCE');
    }

    public function test_phone_query_identifies_member_session(): void
    {
        Config::set('zak_web_chat.require_member_phone', true);

        [$user, $community] = $this->seedMember();
        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $phone = '2347041131371';
        $session = 'm'.$phone;

        $this->getJson('/api/v1/web-chat/bootstrap?s='.$session.'&p='.$phone)
            ->assertOk()
            ->assertJsonPath('data.member_phone', $phone)
            ->assertJsonPath('data.session_id', $session);

        $this->getJson('/api/v1/web-chat/bootstrap?s=wrongsession&p='.$phone)
            ->assertUnprocessable();
    }

    public function test_invalid_access_key_is_rejected(): void
    {
        Config::set('zak_web_chat.allow_open_access', false);
        Config::set('zak_web_chat.access_key', 'known-key');
        Config::set('zak_web_chat.default_community_id', '01JAAAAAAAAAAAAAAAAAAAAAAA');

        $this->getJson('/api/v1/web-chat/bootstrap?s=testsess02&k=wrong')
            ->assertForbidden();
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
