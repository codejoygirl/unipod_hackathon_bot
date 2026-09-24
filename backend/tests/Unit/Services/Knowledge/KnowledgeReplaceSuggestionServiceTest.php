<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Enums\KnowledgeLifecycleStatus;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Knowledge\KnowledgeReplaceSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeReplaceSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_import_message_detects_replace_mode(): void
    {
        $parsed = KnowledgeReplaceSuggestionService::parseImportMessage('replace Cohort 7 chat dump');
        $this->assertTrue($parsed['replace_mode']);
        $this->assertSame('Cohort 7 chat dump', $parsed['body']);

        $plain = KnowledgeReplaceSuggestionService::parseImportMessage('Cohort 7 chat dump');
        $this->assertFalse($plain['replace_mode']);
        $this->assertSame('Cohort 7 chat dump', $plain['body']);
    }

    public function test_parse_publish_args_handles_replace_variants(): void
    {
        $suggested = KnowledgeReplaceSuggestionService::parsePublishArgs('latest drop all');
        $this->assertSame('latest', $suggested['target']);
        $this->assertTrue($suggested['use_suggested']);
        $this->assertNull($suggested['archive_short_ids']);

        $legacy = KnowledgeReplaceSuggestionService::parsePublishArgs('latest replace suggested');
        $this->assertTrue($legacy['use_suggested']);

        $ids = KnowledgeReplaceSuggestionService::parsePublishArgs('latest drop DEF456, GHI789');
        $this->assertSame('latest', $ids['target']);
        $this->assertFalse($ids['use_suggested']);
        $this->assertSame(['DEF456', 'GHI789'], $ids['archive_short_ids']);

        $plain = KnowledgeReplaceSuggestionService::parsePublishArgs('latest');
        $this->assertSame('latest', $plain['target']);
        $this->assertFalse($plain['use_suggested']);
    }

    public function test_suggest_for_draft_filters_to_catalog_short_ids(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Demo']);
        $user = User::factory()->create();

        $published = KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Cohort 6 export',
            'uri' => 'whatsapp://import/old',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Published,
            'language' => 'en',
            'content' => 'older chat lines',
            'content_sha256' => hash('sha256', 'older chat lines'),
            'published_at' => now()->subDay(),
            'metadata' => [],
        ]);

        $draft = KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Cohort 7 export',
            'uri' => 'whatsapp://import/new',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'newer chat lines',
            'content_sha256' => hash('sha256', 'newer chat lines'),
            'metadata' => ['import_mode' => 'replace_candidate'],
        ]);

        $short = KnowledgeReplaceSuggestionService::shortId($published);

        Http::fake([
            '*/conversation/knowledge-replace-suggest' => Http::response([
                'suggestions' => [
                    ['short_id' => $short, 'reason' => 'Same programme cohort'],
                    ['short_id' => 'BOGUS9', 'reason' => 'Should drop'],
                ],
            ], 200),
        ]);

        $service = app(KnowledgeReplaceSuggestionService::class);
        $suggestions = $service->suggestForDraft($community, $draft);

        $this->assertCount(1, $suggestions);
        $this->assertSame($short, $suggestions[0]['short_id']);
        $this->assertSame('Cohort 6 export', $suggestions[0]['name']);
        $this->assertStringContainsString('cohort', mb_strtolower($suggestions[0]['reason']));
    }
}
