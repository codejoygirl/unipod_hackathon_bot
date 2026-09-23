<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\KnowledgeLifecycleStatus;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PurgeKnowledgeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_exactly_one_scope(): void
    {
        $this->artisan('zak:purge-knowledge', ['--force' => true])
            ->expectsOutputToContain('Pick exactly one scope')
            ->assertFailed();
    }

    public function test_purges_community_laravel_and_ai(): void
    {
        Http::fake([
            '*/ingestion/purge' => Http::response([
                'sources_deleted' => 2,
                'versions_deleted' => 2,
                'chunks_deleted' => 10,
                'glossary_deleted' => 0,
                'scope' => 'community:x',
                'message' => 'Purged AI knowledge for community:x: 2 sources, 2 versions, 10 chunks/embeddings, 0 glossary rows.',
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $other = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Keep? No',
            'uri' => 'doc://a',
            'source_type' => 'markdown',
            'lifecycle_status' => KnowledgeLifecycleStatus::Published,
            'language' => 'en',
            'content' => 'a',
            'content_sha256' => hash('sha256', 'a'),
            'published_at' => now(),
        ]);
        KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $other->id,
            'created_by' => $user->id,
            'name' => 'Other community',
            'uri' => 'doc://b',
            'source_type' => 'markdown',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'b',
            'content_sha256' => hash('sha256', 'b'),
        ]);

        $this->artisan('zak:purge-knowledge', [
            '--community' => $community->id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('knowledge_documents', ['community_id' => $community->id]);
        $this->assertDatabaseHas('knowledge_documents', ['community_id' => $other->id]);

        Http::assertSent(function ($request) use ($community, $tenant) {
            if (! str_contains($request->url(), '/ingestion/purge')) {
                return false;
            }
            $data = $request->data();

            return ($data['community_id'] ?? null) === $community->id
                && ($data['tenant_id'] ?? null) === $tenant->id
                && ($data['all'] ?? true) === false;
        });
    }

    public function test_laravel_only_skips_ai(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Draft',
            'uri' => 'doc://c',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'c',
            'content_sha256' => hash('sha256', 'c'),
        ]);

        $this->artisan('zak:purge-knowledge', [
            '--community' => $community->id,
            '--laravel-only' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('knowledge_documents', 0);
        Http::assertNothingSent();
    }

    public function test_all_requires_force_or_confirm_abort(): void
    {
        Http::fake();

        $this->artisan('zak:purge-knowledge', ['--all' => true])
            ->expectsConfirmation('This cannot be undone. Continue?', 'no')
            ->expectsOutputToContain('Aborted')
            ->assertSuccessful();
    }
}
