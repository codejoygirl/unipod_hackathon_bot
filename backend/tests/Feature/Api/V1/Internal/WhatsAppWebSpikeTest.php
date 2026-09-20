<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Internal;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppWebSpikeTest extends TestCase
{
    use RefreshDatabase;

    public function test_spike_inbound_is_disabled_by_default(): void
    {
        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'hello',
        ])->assertNotFound();
    }

    public function test_spike_rejects_invalid_secret(): void
    {
        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'correct-secret',
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'hello',
        ], [
            'X-Spike-Secret' => 'wrong',
        ])->assertUnauthorized();
    }

    public function test_spike_ask_returns_cited_reply(): void
    {
        Http::fake([
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'When is clinic open?',
                'detected_language' => 'en',
                'execution_time_ms' => 12.0,
                'total_chunks_retrieved' => 1,
                'validated_payload' => [
                    'state' => 'VERIFIED',
                    'answer' => 'Saturday 9am [E1].',
                    'confidence_score' => 0.9,
                    'needs_escalation' => false,
                    'escalation_reason' => null,
                    'citations' => [[
                        'evidence_id' => 'E1',
                        'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                        'source_name' => 'Clinic',
                        'source_uri' => 'doc://clinic-hours',
                        'authority_tier' => 'official_announcement',
                        'exact_quote' => 'Saturday 9am',
                        'context_snippet' => 'Clinic opens Saturday 9am.',
                        'page_number' => 1,
                        'timestamp_seconds' => null,
                        'is_verified' => true,
                    ]],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Clinic',
            'uri' => 'doc://clinic-hours',
            'source_type' => 'markdown',
            'authority_tier' => 'official_announcement',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'Clinic opens Saturday 9am.',
            'content_sha256' => hash('sha256', 'Clinic opens Saturday 9am.'),
            'published_at' => now(),
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'When is clinic open?',
            'message_id' => 'wamid.abc',
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp_web_spike')
            ->assertJsonPath('data.reply', 'Saturday 9am [E1].'."\n\n".'— Clinic (VERIFIED)');
    }

    public function test_spike_export_creates_draft(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => $community->id,
        ]);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15551234567',
            'text' => 'EXPORT Water off tomorrow 8am–12pm.',
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp_web_spike');

        $this->assertDatabaseHas('knowledge_documents', [
            'community_id' => $community->id,
            'source_type' => 'whatsapp',
            'lifecycle_status' => 'draft',
            'name' => 'WA Web spike forward',
        ]);
    }

    public function test_spike_join_token_and_link(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['email' => 'demo@zak.test']);
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => User::query()->where('email', 'demo@zak.test')->value('id'),
        ]);

        config([
            'whatsapp_web_spike.enabled' => true,
            'whatsapp_web_spike.shared_secret' => 'spike-test-secret',
            'whatsapp_web_spike.default_user_email' => 'demo@zak.test',
            'whatsapp_web_spike.default_community_id' => null,
        ]);

        $mint = $this->postJson('/api/v1/internal/whatsapp-web-spike/join-token', [
            'community_id' => $community->id,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])->assertOk();

        $joinMessage = $mint->json('data.join_message');
        $this->assertNotEmpty($joinMessage);
        $this->assertStringStartsWith('JOIN-', $joinMessage);

        $this->postJson('/api/v1/internal/whatsapp-web-spike/inbound', [
            'from' => '15559876543',
            'text' => $joinMessage,
        ], [
            'X-Spike-Secret' => 'spike-test-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.reply', 'Linked to community '.$community->id.'. Ask me a question about community knowledge.');
    }
}
