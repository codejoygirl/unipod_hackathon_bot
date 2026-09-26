<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\KnowledgeAuthorityTier;
use App\Enums\KnowledgeLifecycleStatus;
use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\KnowledgeSource;
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
    }

    public function test_bootstrap_and_ask_with_phone_query(): void
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
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->getJson('/api/v1/web-chat/bootstrap?phone='.$phone)
            ->assertOk()
            ->assertJsonPath('data.member_phone', $phone)
            ->assertJsonPath('data.community.id', $community->id);

        $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
            'query' => 'What is the syllabus?',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'INSUFFICIENT_EVIDENCE');

        $this->postJson('/api/v1/communities/'.$community->id.'/assistant/ask', [
            'phone' => $phone,
            'query' => 'What is the syllabus?',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'INSUFFICIENT_EVIDENCE');

        $this->postJson('/communities/'.$community->id.'/assistant/ask', [
            'phone' => $phone,
            'query' => 'What is the syllabus?',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'INSUFFICIENT_EVIDENCE');
    }

    public function test_social_query_returns_warm_reply(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
            'query' => 'Hello',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'VERIFIED');
    }

    public function test_unintelligible_query_asks_for_clarification_instead_of_handoff(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response([
                'intent' => 'clarify',
                'link_mode' => 'none',
                'follow_up' => false,
                'link_focus' => 'na',
                'needs_temporal_resolution' => false,
            ], 200),
            '*/conversation/reply' => Http::response([
                'reply' => 'I did not catch a clear question. Could you retype what you meant?',
            ], 200),
            '*/retrieval/grounded-answer' => Http::response(['error' => 'should not retrieve'], 500),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $response = $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
            'query' => 'jdjdjdjdjndjdijdjdjsjddrfjfijhddhdjjfhdhdhdhh',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'VERIFIED')
            ->assertJsonPath('data.needs_escalation', false);

        $answer = strtolower((string) $response->json('data.answer'));
        $this->assertStringContainsString('retype', $answer);
        $this->assertStringNotContainsString('passed it along', $answer);
    }

    public function test_knowledge_gap_uses_model_handoff_after_real_escalation(): void
    {
        Http::fake([
            '*/conversation/classify' => Http::response([
                'intent' => 'knowledge',
                'link_mode' => 'none',
                'follow_up' => false,
                'link_focus' => 'na',
                'needs_temporal_resolution' => false,
            ], 200),
            '*/conversation/reply' => Http::response([
                'reply' => 'No birthday in the notes yet. I passed this to the team and I will follow up.',
            ], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => "When is Diane's birthday?",
                'detected_language' => 'en',
                'execution_time_ms' => 5.0,
                'total_chunks_retrieved' => 0,
                'validated_payload' => [
                    'state' => 'INSUFFICIENT_EVIDENCE',
                    'answer' => '',
                    'confidence_score' => 0.1,
                    'needs_escalation' => true,
                    'escalation_reason' => 'insufficient_evidence',
                    'citations' => [],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
            'query' => "/ask When is Diane's birthday?",
        ])
            ->assertOk()
            ->assertJsonPath('data.needs_escalation', true)
            ->assertJsonPath(
                'data.answer',
                'No birthday in the notes yet. I passed this to the team and I will follow up.',
            );

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), '/conversation/reply')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?: [];

            return ($body['mode'] ?? null) === 'escalated'
                && str_contains((string) ($body['message'] ?? ''), 'Diane');
        });
    }

    public function test_help_command_returns_channel_help(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $response = $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
            'query' => '/help',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'VERIFIED');

        $this->assertStringContainsString('What I can do', (string) $response->json('data.answer'));
    }

    public function test_feature_command_records_and_acknowledges(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $response = $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
            'query' => '/feature Add voice recording transcripts',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'VERIFIED');

        $this->assertStringContainsString('feature request', strtolower((string) $response->json('data.answer')));
    }

    public function test_invalid_phone_is_rejected(): void
    {
        Config::set('zak_web_chat.default_community_id', '01JAAAAAAAAAAAAAAAAAAAAAAA');

        $this->getJson('/api/v1/web-chat/bootstrap?phone=not-a-phone')
            ->assertUnprocessable();
    }

    public function test_admin_phone_requires_password_on_bootstrap(): void
    {
        [$user, $community] = $this->seedMember();
        $adminPhone = '250783188655'; // Diane

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        // Without password, returns requires_password: true
        $res = $this->getJson("/api/v1/web-chat/bootstrap?phone={$adminPhone}")
            ->assertOk()
            ->assertJsonPath('data.requires_password', true)
            ->assertJsonPath('data.is_admin', true)
            ->assertJsonPath('data.admin_name', 'Diane');

        // With wrong password, returns 422
        $this->getJson("/api/v1/web-chat/bootstrap?phone={$adminPhone}&password=wrongpassword")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        // With correct password, succeeds and returns admin_token
        $loginRes = $this->getJson("/api/v1/web-chat/bootstrap?phone={$adminPhone}&password=Zak!Dia%238318")
            ->assertOk()
            ->assertJsonPath('data.requires_password', false)
            ->assertJsonPath('data.is_admin', true)
            ->assertJsonPath('data.role', 'admin');

        $token = $loginRes->json('data.admin_token');
        $this->assertNotEmpty($token);

        // With token, subsequent bootstrap succeeds without password
        $this->withHeader('X-Admin-Token', $token)
            ->getJson("/api/v1/web-chat/bootstrap?phone={$adminPhone}")
            ->assertOk()
            ->assertJsonPath('data.requires_password', false)
            ->assertJsonPath('data.is_admin', true);
    }

    public function test_admin_can_run_logins_command_and_non_admin_is_denied(): void
    {
        [$user, $community] = $this->seedMember();
        $adminPhone = '250783188655'; // Diane
        $memberPhone = '254711999888'; // Normal member

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        // Admin runs /logins
        $adminAsk = $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $adminPhone,
            'query' => '/logins',
        ])
            ->assertOk();

        $answer = (string) $adminAsk->json('data.answer');
        $this->assertStringContainsString('Diane', $answer);
        $this->assertStringContainsString('Gift Ntuli', $answer);
        $this->assertStringContainsString('Charles Bolton', $answer);
        $this->assertStringContainsString('Zak!Dia#8318', $answer);

        // Normal member runs /logins -> denied
        $memberAsk = $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $memberPhone,
            'query' => '/logins',
        ])
            ->assertOk();

        $memberAnswer = (string) $memberAsk->json('data.answer');
        $this->assertStringContainsString('admin-only', $memberAnswer);
        $this->assertStringNotContainsString('Zak!Dia#8318', $memberAnswer);
    }

    public function test_resources_endpoint_returns_published_community_resources(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        KnowledgeSource::query()->create([
            'tenant_id' => $community->tenant_id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UNIPOD COMMUNITY RESOURCES',
            'uri' => 'community://'.$community->id.'/asset/gfolder:123',
            'source_type' => 'markdown',
            'authority_tier' => KnowledgeAuthorityTier::VerifiedResource,
            'lifecycle_status' => KnowledgeLifecycleStatus::Published,
            'content' => "UNIPOD COMMUNITY RESOURCES\n\nhttps://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs\n\nOfficial folder.",
            'content_sha256' => hash('sha256', 'test'),
            'published_at' => now()->subDay(),
            'metadata' => [
                'asset_kind' => 'folder',
                'asset_identity' => 'gfolder:123',
                'delivery_url' => 'https://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
            ],
        ]);

        // Auto-indexed chat message (e.g. from WhatsApp/Telegram) should NOT be returned as a community resource
        KnowledgeSource::query()->create([
            'tenant_id' => $community->tenant_id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Admin update 2026-09-23 17:43',
            'uri' => 'whatsapp://admin-auto/test1234',
            'source_type' => 'markdown',
            'authority_tier' => KnowledgeAuthorityTier::OfficialAnnouncement,
            'lifecycle_status' => KnowledgeLifecycleStatus::Published,
            'content' => "Needs Assessment workshop: https://meet.google.com/abc-def-ghi",
            'content_sha256' => hash('sha256', 'test2'),
            'published_at' => now(),
            'metadata' => [
                'origin' => 'admin_auto_index',
                'channel' => 'whatsapp',
            ],
        ]);

        $res = $this->getJson('/api/v1/web-chat/resources?phone='.$phone)
            ->assertOk()
            ->assertJsonPath('data.community.id', $community->id);

        $resources = $res->json('data.resources');
        $this->assertCount(1, $resources);
        $this->assertSame('UNIPOD COMMUNITY RESOURCES', $resources[0]['name']);
        $this->assertSame('folder', $resources[0]['kind']);
        $this->assertSame('https://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs', $resources[0]['url']);
    }

    public function test_ask_with_image_extracts_then_answers(): void
    {
        Http::fake([
            '*/conversation/understand-image' => Http::response([
                'text' => 'When is the next session?',
            ], 200),
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'When is the next session?',
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
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
            'query' => '',
            'image_base64' => base64_encode(str_repeat('x', 64)),
            'image_mime' => 'image/jpeg',
            'image_filename' => 'flyer.jpg',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'INSUFFICIENT_EVIDENCE');
    }

    public function test_ask_requires_query_or_image(): void
    {
        [$user, $community] = $this->seedMember();
        $phone = '2347041131371';

        Config::set('zak_web_chat.default_community_id', $community->id);
        Config::set('zak_web_chat.actor_user_email', $user->email);

        $this->postJson('/api/v1/web-chat/ask', [
            'phone' => $phone,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    }

    public function test_feature_request_stores_in_database_and_returns_ref(): void
    {
        [, $community] = $this->seedMember();

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
            'http://wa-out.test/*' => Http::response(['ok' => true], 200),
        ]);

        $payload = [
            'title' => 'Export calendar events',
            'description' => 'Allow members to sync Zoom sessions directly to Google Calendar.',
            'user_type' => 'member',
            'phone' => '+254712345678',
            'name' => 'Fellow Jane',
            'community_id' => $community->id,
        ];

        $response = $this->postJson('/api/v1/web-chat/feature-request', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'submitted');

        $this->assertNotNull($response->json('data.ref'));
        $this->assertDatabaseHas('feature_requests', [
            'community_id' => $community->id,
            'user_type' => 'member',
            'title' => 'Export calendar events',
            'user_phone' => '+254712345678',
        ]);
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
