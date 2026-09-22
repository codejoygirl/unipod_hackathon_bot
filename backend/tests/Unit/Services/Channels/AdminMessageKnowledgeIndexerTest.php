<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\Models\Community;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use App\Services\Channels\AdminMessageKnowledgeIndexer;
use App\Services\Channels\ChannelConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminMessageKnowledgeIndexerTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_short_and_slash_messages_without_calling_ai(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);
        Http::fake();

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $indexer = app(AdminMessageKnowledgeIndexer::class);

        $this->assertFalse($indexer->maybeIndex(
            'whatsapp_web_spike',
            'ok',
            $user,
            $community,
            '2348011111111',
        )['indexed']);

        $this->assertFalse($indexer->maybeIndex(
            'whatsapp_web_spike',
            '/import some notes',
            $user,
            $community,
            '2348011111111',
        )['indexed']);

        Http::assertNothingSent();
    }

    public function test_publishes_when_model_says_indexable(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/indexable' => Http::response(['indexable' => true], 200),
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-admin-auto',
                'version_id' => 'ai-ver-admin-auto',
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo',
            'description' => 'sessions',
        ]);
        $user = User::factory()->create();

        $indexer = app(AdminMessageKnowledgeIndexer::class);
        $result = $indexer->maybeIndex(
            'whatsapp_web_spike',
            'Clinic closed Friday afternoon for maintenance',
            $user,
            $community,
            '2348011111111',
        );

        $this->assertTrue($result['indexed']);
        $this->assertNotNull($result['knowledge_id']);
        $this->assertStringContainsString('knowledge base', (string) $result['reply']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/conversation/indexable'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ingestion/')
            || str_contains($request->url(), '/sync'));
    }

    public function test_skips_when_model_says_not_indexable(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(AiServiceClient::class);

        Http::fake([
            'http://ai.test/conversation/indexable' => Http::response(['indexable' => false], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $indexer = app(AdminMessageKnowledgeIndexer::class);
        $result = $indexer->maybeIndex(
            'whatsapp_web_spike',
            'lol yeah that was funny yesterday night',
            $user,
            $community,
            '2348011111111',
        );

        $this->assertFalse($result['indexed']);
        $this->assertNull($result['knowledge_id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/conversation/indexable'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ingestion/')
            || str_contains($request->url(), '/sync'));
    }
}
