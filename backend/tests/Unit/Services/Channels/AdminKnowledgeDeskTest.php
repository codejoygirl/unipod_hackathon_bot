<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\Enums\KnowledgeLifecycleStatus;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\AdminKnowledgeDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminKnowledgeDeskTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_created_reply_includes_publish_hint(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();
        $source = KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods / Wadhwani programme resource pack',
            'uri' => 'whatsapp-web-spike://import/test',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'hello export',
            'content_sha256' => hash('sha256', 'hello export'),
            'metadata' => ['origin' => 'admin_import'],
        ]);

        $desk = app(AdminKnowledgeDesk::class);
        $reply = $desk->draftCreatedReply($source, 'whatsapp');

        $this->assertStringContainsString('Imported', $reply);
        $this->assertStringContainsString('Swipe-reply', $reply);
        $this->assertStringContainsString('/publish', $reply);
        $this->assertStringContainsString($desk->shortId($source), $reply);
        $this->assertStringContainsString('/knowledge', $reply);
        $this->assertStringNotContainsString('knowledge drafts', $reply);
        $this->assertStringNotContainsString('spike', mb_strtolower($reply));
    }

    public function test_suggest_import_title_uses_first_meaningful_line(): void
    {
        $desk = app(AdminKnowledgeDesk::class);
        $title = $desk->suggestImportTitle(
            "UniPods / Wadhwani programme resource pack\n\nCommunity knowledge: schedules\n\nhttps://example.com"
        );

        $this->assertSame('UniPods / Wadhwani programme resource pack', $title);
    }

    public function test_draft_card_helpers_extract_id_and_confirm_publish(): void
    {
        $desk = app(AdminKnowledgeDesk::class);
        $card = "*Imported.*\n\n*UniPods pack*\nID: `MGR8KC`\n\nSwipe-reply with *publish*";

        $this->assertTrue($desk->isDraftCardText($card));
        $this->assertSame('MGR8KC', $desk->extractShortIdFromDraftCard($card));
        $this->assertTrue($desk->isPublishConfirmText(''));
        $this->assertTrue($desk->isPublishConfirmText('publish'));
        $this->assertTrue($desk->isPublishConfirmText('/publish'));
        $this->assertFalse($desk->isPublishConfirmText('what is this about?'));
    }

    public function test_knowledge_lists_drafts_and_assets(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Demo Community']);
        $user = User::factory()->create();

        KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Chat export draft',
            'uri' => 'whatsapp-web-spike://import/a',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'export body',
            'content_sha256' => hash('sha256', 'export body'),
            'metadata' => [],
        ]);

        KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'UniPods Handbook',
            'uri' => 'community://'.$community->id.'/asset/drive-abc',
            'source_type' => 'markdown',
            'lifecycle_status' => KnowledgeLifecycleStatus::Published,
            'language' => 'en',
            'content' => "UniPods Handbook\n\nhttps://drive.google.com/file/d/abc",
            'content_sha256' => hash('sha256', 'handbook'),
            'published_at' => now(),
            'metadata' => [
                'asset_identity' => 'drive-abc',
                'delivery_url' => 'https://drive.google.com/file/d/abc',
            ],
        ]);

        $desk = app(AdminKnowledgeDesk::class);
        $overview = $desk->tryHandle('/knowledge', $user, $community, 'whatsapp');
        $this->assertNotNull($overview);
        $this->assertTrue($overview['ok']);
        $this->assertStringContainsString('Drafts / pending: *1*', $overview['reply']);
        $this->assertStringContainsString('Files / assets: *1*', $overview['reply']);

        $drafts = $desk->tryHandle('/knowledge drafts', $user, $community, 'whatsapp');
        $this->assertStringContainsString('Chat export draft', $drafts['reply']);

        $assets = $desk->tryHandle('/knowledge assets', $user, $community, 'whatsapp');
        $this->assertStringContainsString('UniPods Handbook', $assets['reply']);
        $this->assertStringContainsString('drive.google.com/file/d/abc', $assets['reply']);
    }

    public function test_draft_created_replace_reply_lists_suggestions_and_publish_options(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();
        $source = KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Cohort 7 export',
            'uri' => 'whatsapp-web-spike://import/replace',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'export body',
            'content_sha256' => hash('sha256', 'export body'),
            'metadata' => ['import_mode' => 'replace_candidate'],
        ]);

        $desk = app(AdminKnowledgeDesk::class);
        $reply = $desk->draftCreatedOverlapReply($source, [
            ['short_id' => 'OLD123', 'name' => 'Cohort 6 export', 'reason' => 'Older cohort dump'],
        ], 'whatsapp');

        $this->assertStringContainsString('Imported', $reply);
        $this->assertStringContainsString('OLD123', $reply);
        $this->assertStringContainsString('/unpublish', $reply);
        $this->assertStringContainsString('/publish latest', $reply);
        $this->assertStringContainsString('drop all', $reply);
        $this->assertStringContainsString($desk->shortId($source), $reply);
    }

    public function test_publish_latest_syncs_to_ai_index(): void
    {
        Http::fake([
            '*/ingestion/sync' => Http::response([
                'source_id' => '11111111-1111-1111-1111-111111111111',
                'version_id' => '22222222-2222-2222-2222-222222222222',
                'status' => 'completed',
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Export to publish',
            'uri' => 'whatsapp-web-spike://import/b',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'body',
            'content_sha256' => hash('sha256', 'body'),
            'metadata' => [],
        ]);

        $desk = app(AdminKnowledgeDesk::class);
        $result = $desk->tryHandle('/publish latest', $user, $community, 'whatsapp');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Published', $result['reply']);
        $this->assertStringContainsString('Export to publish', $result['reply']);
        $this->assertStringContainsString('Members can ask about it now', $result['reply']);
        $this->assertStringNotContainsString('AI index', $result['reply']);
        $this->assertStringNotContainsString('chunked', mb_strtolower($result['reply']));

        $this->assertDatabaseHas('knowledge_documents', [
            'name' => 'Export to publish',
            'lifecycle_status' => KnowledgeLifecycleStatus::Published->value,
            'ai_source_id' => '11111111-1111-1111-1111-111111111111',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ingestion/sync'));
    }

    public function test_publish_with_replace_suggested_archives_listed_sources(): void
    {
        Http::fake([
            '*/ingestion/sync' => Http::response([
                'source_id' => '44444444-4444-4444-4444-444444444444',
                'version_id' => '55555555-5555-5555-5555-555555555555',
                'status' => 'completed',
            ], 200),
            '*/ingestion/deactivate/*' => Http::response(['status' => 'archived'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $old = KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Cohort 6 export',
            'uri' => 'whatsapp-web-spike://import/old',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Published,
            'language' => 'en',
            'content' => 'old body',
            'content_sha256' => hash('sha256', 'old body'),
            'ai_source_id' => '66666666-6666-6666-6666-666666666666',
            'published_at' => now()->subDay(),
            'metadata' => [],
        ]);

        $oldShort = app(AdminKnowledgeDesk::class)->shortId($old);

        KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Cohort 7 export',
            'uri' => 'whatsapp-web-spike://import/new',
            'source_type' => 'whatsapp',
            'lifecycle_status' => KnowledgeLifecycleStatus::Draft,
            'language' => 'en',
            'content' => 'new body',
            'content_sha256' => hash('sha256', 'new body'),
            'metadata' => [
                'replace_suggestions' => [
                    ['short_id' => $oldShort, 'name' => 'Cohort 6 export', 'reason' => 'Superseded'],
                ],
            ],
        ]);

        $desk = app(AdminKnowledgeDesk::class);
        $result = $desk->tryHandle('/publish latest drop all', $user, $community, 'whatsapp');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Published', $result['reply']);
        $this->assertStringContainsString('Archived', $result['reply']);
        $this->assertStringContainsString($oldShort, $result['reply']);

        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $old->id,
            'lifecycle_status' => KnowledgeLifecycleStatus::Archived->value,
        ]);
    }

    public function test_features_lists_open_requests_for_community(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $id = '01FEATURETEST000000000000';
        Cache::put('spike_escalations', [$id], now()->addDay());
        Cache::put('spike_escalation:'.$id, [
            'id' => $id,
            'type' => 'feature_request',
            'ref' => 'RYG2AJ',
            'from_name' => 'Abdulsamad Balogun',
            'content' => 'we would like voice replies',
            'community_id' => $community->id,
            'resolved_at' => null,
        ], now()->addDay());

        $desk = app(AdminKnowledgeDesk::class);
        $result = $desk->tryHandle('/features', $user, $community, 'whatsapp');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('RYG2AJ', $result['reply']);
        $this->assertStringContainsString('voice replies', $result['reply']);
        $this->assertStringContainsString('/approve', $result['reply']);
    }

    public function test_member_feature_command_is_not_intercepted(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $desk = app(AdminKnowledgeDesk::class);
        $this->assertNull($desk->tryHandle('/feature Add voice notes', $user, $community, 'whatsapp'));
    }

    public function test_unpublish_archives_published_source_and_calls_ai_deactivate(): void
    {
        Http::fake([
            '*/ingestion/deactivate/*' => Http::response([
                'source_id' => '33333333-3333-3333-3333-333333333333',
                'status' => 'archived',
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $source = KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Old Program Guide',
            'uri' => 'community://'.$community->id.'/asset/gfolder:oldguide',
            'source_type' => 'markdown',
            'lifecycle_status' => KnowledgeLifecycleStatus::Published,
            'language' => 'en',
            'content' => 'Old guide content',
            'content_sha256' => hash('sha256', 'Old guide content'),
            'ai_source_id' => '33333333-3333-3333-3333-333333333333',
            'metadata' => [
                'delivery_url' => 'https://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
            ],
        ]);

        $desk = app(AdminKnowledgeDesk::class);
        $shortId = $desk->shortId($source);

        $result = $desk->tryHandle('/unpublish '.$shortId, $user, $community, 'plain');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Unpublished', $result['reply']);
        $this->assertStringContainsString('Old Program Guide', $result['reply']);
        $this->assertStringContainsString('archived', mb_strtolower($result['reply']));

        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $source->id,
            'lifecycle_status' => KnowledgeLifecycleStatus::Archived->value,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ingestion/deactivate/33333333-3333-3333-3333-333333333333'));
    }

    public function test_unpublish_resolves_by_drive_url(): void
    {
        Http::fake([
            '*/ingestion/deactivate/*' => Http::response([], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $source = KnowledgeSource::query()->create([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'created_by' => $user->id,
            'name' => 'Broken Drive Folder',
            'uri' => 'community://'.$community->id.'/asset/gfolder:1bximvs0xra5nfmdkvbdbzjgmuuqptlbs',
            'source_type' => 'markdown',
            'lifecycle_status' => KnowledgeLifecycleStatus::Published,
            'language' => 'en',
            'content' => 'Broken folder',
            'content_sha256' => hash('sha256', 'Broken folder'),
            'metadata' => [
                'delivery_url' => 'https://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
            ],
        ]);

        $desk = app(AdminKnowledgeDesk::class);

        // Can pass the drive link directly
        $result = $desk->tryHandle('/unpublish https://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs', $user, $community, 'whatsapp');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Unpublished', $result['reply']);
        $this->assertStringContainsString('Broken Drive Folder', $result['reply']);

        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $source->id,
            'lifecycle_status' => KnowledgeLifecycleStatus::Archived->value,
        ]);
    }
}
