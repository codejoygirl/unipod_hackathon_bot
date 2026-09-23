<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportKnowledgeChatsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_knowledge_chats_command_publishes_staged_files_and_mints_token(): void
    {
        Http::fake([
            '*/ingestion/multimodal' => Http::response([
                'source_id' => '11111111-1111-1111-1111-111111111111',
                'version_id' => '22222222-2222-2222-2222-222222222222',
                'status' => 'completed',
                'content_sha256' => 'abc',
                'chunks_created' => 2,
                'message' => 'ok',
                'execution_time_ms' => 1.0,
            ], 200),
            '*/ingestion/activate/*' => Http::response([
                'source_id' => '11111111-1111-1111-1111-111111111111',
                'status' => 'active',
            ], 200),
        ]);

        $dir = storage_path('app/knowledge-import');
        File::ensureDirectoryExists($dir);
        File::put($dir.'/meti-cohort-4_chat.txt', "[04/09/2026, 09:27:15] ~A: hello\n");
        File::put($dir.'/wadhwani-africa_chat.txt', "[04/09/2026, 09:27:15] ~B: hi\n");

        $this->artisan('zak:import-knowledge-chats', [
            '--email' => 'importer@zak.test',
        ])->assertSuccessful();

        $this->assertDatabaseHas('knowledge_documents', [
            'name' => 'UniPods METI AI Program 2026 Cohort 4 chat',
            'lifecycle_status' => 'published',
        ]);

        $json = json_decode((string) file_get_contents(storage_path('app/zak-knowledge-import.json')), true);
        $this->assertIsArray($json);
        $this->assertSame('importer@zak.test', $json['importer']['email']);
        $this->assertNotEmpty($json['auth']['token']);
        $this->assertCount(2, $json['imports']);
    }

    public function test_setup_only_skips_import_but_mints_token(): void
    {
        $this->artisan('zak:import-knowledge-chats', [
            '--setup-only' => true,
            '--email' => 'setup@zak.test',
        ])->assertSuccessful();

        $this->assertDatabaseCount('knowledge_documents', 0);
        $json = json_decode((string) file_get_contents(storage_path('app/zak-knowledge-import.json')), true);
        $this->assertSame([], $json['imports']);
        $this->assertNotEmpty($json['auth']['token']);
    }
}
