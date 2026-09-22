<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeAndAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_lifecycle_draft_to_publish_calls_ai_ingest(): void
    {
        Http::fake([
            '*/ingestion/sync' => Http::response([
                'source_id' => '11111111-1111-1111-1111-111111111111',
                'version_id' => '22222222-2222-2222-2222-222222222222',
                'status' => 'completed',
            ], 200),
        ]);

        [$user, $tenant, $community] = $this->seedMember(MembershipRole::CommunityAdmin);

        $this->actingAs($user);

        $create = $this->postJson('/api/v1/knowledge-sources', [
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'name' => 'Clinic hours',
            'source_type' => 'markdown',
            'content' => 'Clinic opens Saturday 9am.',
            'uri' => 'doc://clinic-hours',
        ])->assertCreated();

        $id = $create->json('data.id');

        $this->postJson("/api/v1/knowledge-sources/{$id}/submit-review")
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', 'pending_review');

        $this->postJson("/api/v1/knowledge-sources/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', 'published')
            ->assertJsonPath('data.ai_source_id', '11111111-1111-1111-1111-111111111111');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ingestion/sync'));
    }

    public function test_knowledge_publish_chunks_large_content_into_multiple_syncs(): void
    {
        $seq = 0;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$seq) {
            if (! str_contains($request->url(), '/ingestion/sync')) {
                return Http::response(['ok' => true], 200);
            }
            $seq++;

            return Http::response([
                'source_id' => sprintf('11111111-1111-1111-1111-%012d', $seq),
                'version_id' => sprintf('22222222-2222-2222-2222-%012d', $seq),
                'status' => 'completed',
            ], 200);
        });

        [$user, $tenant, $community] = $this->seedMember(MembershipRole::CommunityAdmin);
        $this->actingAs($user);

        $big = '';
        for ($i = 0; $i < 1200; $i++) {
            $big .= '[04/09/2026, 09:27:15] ~Diane: Session note line '.$i.' with enough padding '.str_repeat('x', 40)."\n";
        }
        $this->assertGreaterThan(28000, strlen($big));

        $create = $this->postJson('/api/v1/knowledge-sources', [
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'name' => 'Large WA export',
            'source_type' => 'whatsapp',
            'content' => $big,
            'uri' => 'whatsapp://export/test-large',
        ])->assertCreated();

        $id = $create->json('data.id');
        $this->postJson("/api/v1/knowledge-sources/{$id}/submit-review")->assertOk();
        $publish = $this->postJson("/api/v1/knowledge-sources/{$id}/publish")->assertOk();

        $this->assertSame('11111111-1111-1111-1111-000000000001', $publish->json('data.ai_source_id'));
        $this->assertGreaterThanOrEqual(2, $seq);

        $meta = \App\Models\KnowledgeSource::query()->findOrFail($id)->metadata ?? [];
        $this->assertArrayHasKey('ingest_part_count', $meta);
        $this->assertGreaterThanOrEqual(2, (int) $meta['ingest_part_count']);
    }

    public function test_knowledge_publish_activates_pending_multimodal_index(): void
    {
        Http::fake([
            '*/ingestion/multimodal' => Http::response([
                'source_id' => '11111111-1111-1111-1111-111111111111',
                'version_id' => '22222222-2222-2222-2222-222222222222',
                'status' => 'completed',
                'content_sha256' => 'abc',
                'chunks_created' => 1,
                'message' => 'ok',
                'execution_time_ms' => 1.0,
            ], 200),
            '*/ingestion/activate/*' => Http::response([
                'source_id' => '11111111-1111-1111-1111-111111111111',
                'status' => 'active',
            ], 200),
        ]);

        [$user, $tenant, $community] = $this->seedMember(MembershipRole::CommunityAdmin);
        $this->actingAs($user);

        $file = \Illuminate\Http\UploadedFile::fake()->create('flyer.png', 100, 'image/png');

        $import = $this->post('/api/v1/knowledge-sources/import', [
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'name' => 'Clinic flyer',
            'uri' => 'doc://clinic-flyer-activate',
            'source_type' => 'image',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $id = $import->json('data.id');

        $this->postJson("/api/v1/knowledge-sources/{$id}/submit-review")->assertOk();
        $this->postJson("/api/v1/knowledge-sources/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', 'published');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ingestion/activate/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ingestion/sync'));
    }

    public function test_knowledge_import_text_creates_draft(): void
    {
        [$user, $tenant, $community] = $this->seedMember(MembershipRole::Member);

        $this->actingAs($user);

        $this->postJson('/api/v1/knowledge-sources/import', [
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'content' => 'Forwarded: water will be off tomorrow.',
            'name' => 'WA forward',
            'source_type' => 'whatsapp',
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'whatsapp')
            ->assertJsonPath('data.lifecycle_status', 'draft');
    }

    public function test_knowledge_import_file_calls_multimodal_ai(): void
    {
        Http::fake([
            '*/ingestion/multimodal' => Http::response([
                'source_id' => '11111111-1111-1111-1111-111111111111',
                'version_id' => '22222222-2222-2222-2222-222222222222',
                'status' => 'completed',
                'content_sha256' => 'abc',
                'chunks_created' => 1,
                'message' => 'ok',
                'execution_time_ms' => 1.0,
            ], 200),
        ]);

        [$user, $tenant, $community] = $this->seedMember(MembershipRole::CommunityAdmin);

        $this->actingAs($user);

        $file = \Illuminate\Http\UploadedFile::fake()->create('flyer.png', 100, 'image/png');

        $this->post('/api/v1/knowledge-sources/import', [
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'name' => 'Clinic flyer',
            'uri' => 'doc://clinic-flyer',
            'source_type' => 'image',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'image')
            ->assertJsonPath('data.lifecycle_status', 'draft')
            ->assertJsonPath('data.ai_source_id', '11111111-1111-1111-1111-111111111111');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ingestion/multimodal'));
    }

    public function test_assistant_ask_rejects_foreign_community_and_revalidates(): void
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
        $communityA = Community::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'a']);
        $communityB = Community::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'b']);

        $userA = User::factory()->create();
        Membership::factory()->forCommunity($communityA, MembershipRole::Member)->create([
            'user_id' => $userA->id,
        ]);

        $this->actingAs($userA);

        $this->postJson('/api/v1/assistant/ask', [
            'query' => 'When is clinic open?',
            'community_ids' => [$communityB->id],
        ])->assertForbidden();

        $this->postJson('/api/v1/assistant/ask', [
            'query' => 'When is clinic open?',
            'community_ids' => [$communityA->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'BLOCKED')
            ->assertJsonPath('data.needs_escalation', true);

        // Publish a matching source so citation revalidation allows the answer.
        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $communityA->id,
            'created_by' => $userA->id,
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

        $this->postJson('/api/v1/assistant/ask', [
            'query' => 'When is clinic open?',
            'community_ids' => [$communityA->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'VERIFIED')
            ->assertJsonPath('data.evidence_drawer.0.source_uri', 'doc://clinic-hours');
    }

    public function test_assistant_ask_allows_chunked_ingest_part_uris(): void
    {
        Http::fake([
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'Send recordings',
                'detected_language' => 'en',
                'execution_time_ms' => 12.0,
                'total_chunks_retrieved' => 1,
                'validated_payload' => [
                    'state' => 'VERIFIED',
                    'answer' => 'Here is a recording https://example.com/r1 [E1].',
                    'confidence_score' => 0.9,
                    'needs_escalation' => false,
                    'escalation_reason' => null,
                    'citations' => [[
                        'evidence_id' => 'E1',
                        'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                        'source_name' => 'WA export',
                        'source_uri' => 'whatsapp://export/cohort-3/abc/part-3',
                        'authority_tier' => 'community_discussion',
                        'exact_quote' => 'https://example.com/r1',
                        'context_snippet' => 'recording https://example.com/r1',
                        'page_number' => null,
                        'timestamp_seconds' => null,
                        'is_verified' => true,
                    ]],
                    'conflicts' => [],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'cohort']);
        $user = User::factory()->create();
        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        \App\Models\KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'WA export',
            'uri' => 'whatsapp://export/cohort-3/abc',
            'source_type' => 'whatsapp',
            'authority_tier' => 'community_discussion',
            'lifecycle_status' => 'published',
            'language' => 'en',
            'content' => 'recording https://example.com/r1',
            'content_sha256' => hash('sha256', 'recording https://example.com/r1'),
            'published_at' => now(),
        ]);

        $this->actingAs($user);

        $this->postJson('/api/v1/assistant/ask', [
            'query' => 'Send recordings',
            'community_ids' => [$community->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'VERIFIED')
            ->assertJsonPath('data.answer', 'Here is a recording https://example.com/r1 [E1].');
    }

    /**
     * @return array{0: User, 1: Tenant, 2: Community}
     */
    private function seedMember(MembershipRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();
        Membership::factory()->forCommunity($community, $role)->create([
            'user_id' => $user->id,
        ]);

        return [$user, $tenant, $community];
    }
}
