<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\ProgramAssetRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProgramAssetRegistrarTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(): void
    {
        config([
            'ai_service.base_url' => 'http://ai.test',
            'ai_service.hmac_secret' => 'test-secret',
        ]);
        $this->app->forgetInstance(\App\Services\AI\AiServiceClient::class);

        Http::fake([
            'http://ai.test/*' => Http::response([
                'source_id' => 'ai-src-asset',
                'version_id' => 'ai-ver-asset',
            ], 200),
        ]);
    }

    public function test_usage_when_empty(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            'whatsapp_web_spike',
            '/asset',
            $user,
            $community,
            'whatsapp',
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('/asset', $result['reply']);
        $this->assertStringContainsString('handbook', $result['reply']);
        $this->assertStringContainsString('import', $result['reply']);
    }

    public function test_publishes_drive_link_as_knowledge(): void
    {
        $this->fakeAi();

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $url = 'https://drive.google.com/file/d/abc123/view';
        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            'whatsapp_web_spike',
            '/asset handbook UniPods Handbook '.$url,
            $user,
            $community,
            'whatsapp',
        );

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Published', $result['reply']);
        $this->assertStringContainsString($url, $result['reply']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'http://ai.test'));

        $row = KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('name', 'UniPods Handbook')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('community://'.$community->id.'/asset/gdrive:abc123', $row->uri);
        $this->assertStringContainsString($url, (string) $row->content);
        $this->assertSame('gdrive:abc123', $row->metadata['asset_identity'] ?? null);
    }

    public function test_same_drive_file_updates_instead_of_duplicating(): void
    {
        $this->fakeAi();

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();
        $registrar = app(ProgramAssetRegistrar::class);

        $registrar->registerFromCommand(
            'whatsapp_web_spike',
            '/asset handbook UniPods Handbook https://drive.google.com/file/d/abc123/view?usp=sharing',
            $user,
            $community,
            'whatsapp',
        );

        $second = $registrar->registerFromCommand(
            'whatsapp_web_spike',
            '/asset handbook UniPods Handbook v2 https://drive.google.com/file/d/abc123/view',
            $user,
            $community,
            'whatsapp',
        );

        $this->assertTrue($second['ok']);
        $this->assertStringContainsString('Updated', $second['reply']);
        $this->assertSame(
            1,
            KnowledgeSource::query()->where('community_id', $community->id)->count()
        );
        $this->assertSame(
            'UniPods Handbook v2',
            KnowledgeSource::query()->where('community_id', $community->id)->value('name')
        );
    }

    public function test_bulk_import_publishes_many_lines(): void
    {
        $this->fakeAi();

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $body = "/asset import\n"
            ."handbook UniPods Handbook https://drive.google.com/file/d/abc123/view\n"
            ."form Session signup https://docs.google.com/forms/d/form99/viewform\n"
            .'# comment ignored'."\n"
            .'slides Week 1 https://docs.google.com/presentation/d/deck1/edit';

        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            'whatsapp_web_spike',
            $body,
            $user,
            $community,
            'whatsapp',
        );

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('3* new', $result['reply']);
        $this->assertSame(3, KnowledgeSource::query()->where('community_id', $community->id)->count());
    }

    public function test_rejects_non_drive_hosts(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            'whatsapp_web_spike',
            '/asset form Signup https://example.com/form',
            $user,
            $community,
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Google Drive', $result['reply']);
    }

    public function test_rejects_ellipsis_placeholder_folder_url(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            'whatsapp_web_spike',
            '/asset other UNIPOD COMMUNITY RESOURCES https://drive.google.com/drive/folders/…',
            $user,
            $community,
            'whatsapp',
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('incomplete', $result['reply']);
        $this->assertSame(0, KnowledgeSource::query()->where('community_id', $community->id)->count());
    }

    public function test_rejects_your_folder_id_placeholder(): void
    {
        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            'whatsapp_web_spike',
            '/asset other Resources https://drive.google.com/drive/folders/YOUR_FOLDER_ID',
            $user,
            $community,
            'whatsapp',
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('incomplete', $result['reply']);
    }

    public function test_publishes_full_folder_url_without_truncation(): void
    {
        $this->fakeAi();

        $tenant = Tenant::factory()->create();
        $community = Community::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $folderId = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs';
        $url = 'https://drive.google.com/drive/folders/'.$folderId.'?usp=sharing';
        $result = app(ProgramAssetRegistrar::class)->registerFromCommand(
            'whatsapp_web_spike',
            '/asset other UNIPOD COMMUNITY RESOURCES '.$url,
            $user,
            $community,
            'whatsapp',
        );

        $this->assertTrue($result['ok']);
        $canonical = 'https://drive.google.com/drive/folders/'.$folderId;
        $this->assertStringContainsString($canonical, $result['reply']);
        $this->assertStringNotContainsString('folders/…', $result['reply']);
        $this->assertStringNotContainsString('folders/...', $result['reply']);

        $row = KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('name', 'UNIPOD COMMUNITY RESOURCES')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame($canonical, $row->metadata['delivery_url'] ?? null);
        $this->assertSame('gfolder:'.strtolower($folderId), $row->metadata['asset_identity'] ?? null);
    }
}
