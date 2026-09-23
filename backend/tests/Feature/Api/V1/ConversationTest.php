<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\KnowledgeSource;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_only_lists_the_members_own_conversations(): void
    {
        [$user, $tenant, $community] = $this->seedMember();

        $mine = Conversation::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'title' => 'Clinic hours',
        ]);

        $other = User::factory()->create();
        Conversation::query()->create([
            'user_id' => $other->id,
            'tenant_id' => $tenant->id,
            'title' => 'Someone else',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_show_returns_messages_for_the_owner(): void
    {
        [$user, $tenant] = $this->seedMember();

        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => 'When is clinic open?',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.content', 'When is clinic open?');
    }

    public function test_show_on_another_members_conversation_is_not_found(): void
    {
        [, $tenant] = $this->seedMember();
        [$intruder] = $this->seedMember($tenant);

        $owner = User::factory()->create();
        $conversation = Conversation::query()->create([
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($intruder)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertNotFound();
    }

    public function test_store_creates_a_thread_without_picking_a_tenant(): void
    {
        [$user] = $this->seedMember();

        $this->actingAs($user)
            ->postJson('/api/v1/conversations')
            ->assertCreated()
            ->assertJsonPath('data.title', null);
    }

    public function test_ask_creates_a_thread_and_persists_both_turns(): void
    {
        $this->fakeGroundedAnswer('Saturday 9am [E1].');

        [$user, $tenant, $community] = $this->seedMember();
        $this->publishSource($tenant, $community, $user);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/assistant/ask', [
                'query' => 'When is clinic open?',
                'community_ids' => [$community->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'VERIFIED');

        $conversationId = $response->json('meta.conversation_id');
        $this->assertIsString($conversationId);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'title' => 'When is clinic open?',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_USER,
            'content' => 'When is clinic open?',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_ASSISTANT,
        ]);

        // The thread survives a reload with its citations intact.
        $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.answer.state', 'VERIFIED')
            ->assertJsonPath('data.messages.1.answer.evidence_drawer.0.source_uri', 'doc://clinic-hours');
    }

    public function test_ask_appends_to_an_existing_thread_and_sends_prior_context(): void
    {
        $this->fakeGroundedAnswer('Saturday 9am [E1].');

        [$user, $tenant, $community] = $this->seedMember();
        $this->publishSource($tenant, $community, $user);

        $first = $this->actingAs($user)
            ->postJson('/api/v1/assistant/ask', [
                'query' => 'When is clinic open?',
                'community_ids' => [$community->id],
            ])
            ->assertOk();

        $conversationId = $first->json('meta.conversation_id');

        $this->actingAs($user)
            ->postJson('/api/v1/assistant/ask', [
                'query' => 'And where is it?',
                'community_ids' => [$community->id],
                'conversation_id' => $conversationId,
            ])
            ->assertOk()
            ->assertJsonPath('meta.conversation_id', $conversationId);

        $this->assertSame(4, Message::query()->where('conversation_id', $conversationId)->count());

        // The follow-up carries the earlier turn into retrieval, using the envelope the
        // model prompt already understands, while the stored turn stays the bare question.
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/retrieval/grounded-answer')) {
                return false;
            }

            $query = (string) ($request->data()['query'] ?? '');

            return str_contains($query, 'Current question: And where is it?')
                && str_contains($query, 'User: When is clinic open?');
        });

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_USER,
            'content' => 'And where is it?',
        ]);
    }

    public function test_ask_rejects_another_members_conversation(): void
    {
        $this->fakeGroundedAnswer('Saturday 9am [E1].');

        [, $tenant] = $this->seedMember();
        [$intruder, , $community] = $this->seedMember($tenant);

        $owner = User::factory()->create();
        $conversation = Conversation::query()->create([
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($intruder)
            ->postJson('/api/v1/assistant/ask', [
                'query' => 'When is clinic open?',
                'community_ids' => [$community->id],
                'conversation_id' => $conversation->id,
            ])
            ->assertNotFound();
    }

    public function test_destroy_removes_the_thread_for_its_owner(): void
    {
        [$user, $tenant] = $this->seedMember();

        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => 'Hello',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/conversations/{$conversation->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
    }

    private function fakeGroundedAnswer(string $answer): void
    {
        Http::fake([
            '*/retrieval/grounded-answer' => Http::response([
                'query' => 'When is clinic open?',
                'detected_language' => 'en',
                'execution_time_ms' => 12.0,
                'total_chunks_retrieved' => 1,
                'validated_payload' => [
                    'state' => 'VERIFIED',
                    'answer' => $answer,
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
    }

    /** Citation revalidation only keeps citations whose source is published. */
    private function publishSource(Tenant $tenant, Community $community, User $user): void
    {
        KnowledgeSource::query()->create([
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
    }

    /**
     * @return array{0: User, 1: Tenant, 2: Community}
     */
    private function seedMember(?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        Membership::factory()->forCommunity($community, MembershipRole::Member)->create([
            'user_id' => $user->id,
        ]);

        return [$user, $tenant, $community];
    }
}
