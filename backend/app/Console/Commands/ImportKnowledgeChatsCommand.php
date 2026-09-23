<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresAssistantDemoScope;
use App\Enums\KnowledgeAuthorityTier;
use App\Enums\MembershipRole;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Same demo scope + spike user email as zak:seed-assistant-demo, then import staged chats.
 */
class ImportKnowledgeChatsCommand extends Command
{
    use EnsuresAssistantDemoScope;

    private const TOKEN_NAME = 'zak-knowledge-import';

    private const STAGE_DIR = 'knowledge-import';

    /** @var list<array{file: string, name: string, source_type: string}> */
    private const CHAT_IMPORTS = [
        [
            'file' => 'meti-cohort-4_chat.txt',
            'name' => 'UniPods METI AI Program 2026 Cohort 4 chat',
            'source_type' => 'whatsapp',
        ],
        [
            'file' => 'wadhwani-africa_chat.txt',
            'name' => 'Wadhwani UniPod AI Program Africa chat',
            'source_type' => 'whatsapp',
        ],
    ];

    protected $signature = 'zak:import-knowledge-chats
                            {--email= : Demo user email (default: WHATSAPP_WEB_SPIKE_DEFAULT_USER_EMAIL or TELEGRAM_SPIKE_DEFAULT_USER_EMAIL)}
                            {--password=password123 : Password when creating the demo user}
                            {--setup-only : Only ensure scope + user + token; do not import files}
                            {--token-only : Refresh bearer token only (skip file import)}';

    protected $description = 'Seed demo scope like zak:seed-assistant-demo, mint token, import storage/app/knowledge-import/*.txt, publish';

    public function handle(KnowledgeLifecycleService $lifecycle): int
    {
        [, $demoCommunity, $scopeCreated] = $this->ensureDemoTenantAndCommunity();
        $community = $this->communityForKnowledgeOperations($demoCommunity);
        $tenant = $community->tenant;

        $email = $this->resolveDemoUserEmail($this->option('email'));
        $password = (string) $this->option('password');
        [$user, $userCreated] = $this->ensureDemoAdminUser($tenant, $community, $email, $password);

        $user->tokens()->where('name', self::TOKEN_NAME)->delete();
        $plainTextToken = $user->createToken(self::TOKEN_NAME)->plainTextToken;

        $imports = [];
        $importErrors = [];

        $runImport = ! $this->option('setup-only') && ! $this->option('token-only');
        if ($runImport) {
            $stagePath = storage_path('app/'.self::STAGE_DIR);
            if (! is_dir($stagePath)) {
                File::ensureDirectoryExists($stagePath);
            }

            foreach (self::CHAT_IMPORTS as $spec) {
                $absolute = $stagePath.DIRECTORY_SEPARATOR.$spec['file'];
                if (! is_file($absolute)) {
                    $importErrors[] = ['file' => $spec['file'], 'error' => 'missing on disk'];
                    $this->components->warn("Skip missing chat file: {$absolute}");

                    continue;
                }

                try {
                    $imports[] = $this->importChatFile($lifecycle, $user, $tenant, $community, $spec, $absolute);
                } catch (Throwable $e) {
                    $importErrors[] = ['file' => $spec['file'], 'error' => $e->getMessage()];
                    $this->components->error("Import failed for {$spec['file']}: {$e->getMessage()}");
                }
            }

            $packPath = $stagePath.DIRECTORY_SEPARATOR.'resource-pack.txt';
            if (is_file($packPath)) {
                try {
                    $imports[] = $this->importResourcePack($lifecycle, $user, $tenant, $community, $packPath);
                } catch (Throwable $e) {
                    $importErrors[] = ['file' => 'resource-pack.txt', 'error' => $e->getMessage()];
                    $this->components->error('Import failed for resource-pack.txt: '.$e->getMessage());
                }
            } else {
                $this->components->info('No resource-pack.txt (optional) — skipped.');
            }
        }

        $base = rtrim((string) config('app.url'), '/');
        $summary = [
            'scope' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'created' => $scopeCreated['tenant'],
                ],
                'community' => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                    'tenant_id' => $community->tenant_id,
                    'created' => $scopeCreated['community'],
                ],
            ],
            'importer' => [
                'id' => $user->id,
                'email' => $user->email,
                'created' => $userCreated,
                'membership_role' => MembershipRole::CommunityAdmin->value,
            ],
            'auth' => [
                'type' => 'Sanctum personal access token',
                'token_name' => self::TOKEN_NAME,
                'token' => $plainTextToken,
                'header' => 'Authorization: Bearer '.$plainTextToken,
            ],
            'staging_dir' => storage_path('app/'.self::STAGE_DIR),
            'imports' => $imports,
            'import_errors' => $importErrors,
            'api' => [
                'base' => $base,
                'import_endpoint' => $base.'/api/v1/knowledge-sources/import',
            ],
        ];

        $this->printSummary($summary);

        $jsonOut = storage_path('app/zak-knowledge-import.json');
        file_put_contents($jsonOut, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $envOut = storage_path('app/zak-knowledge-import.env');
        file_put_contents($envOut, implode(PHP_EOL, [
            'ZAK_API_BASE='.$base,
            'ZAK_DEMO_EMAIL='.$user->email,
            'ZAK_IMPORT_TOKEN='.$plainTextToken,
            'ZAK_TENANT_ID='.$tenant->id,
            'ZAK_COMMUNITY_ID='.$community->id,
            '',
        ]));

        $this->newLine();
        $this->components->twoColumnDetail('Saved JSON', 'storage/app/zak-knowledge-import.json');
        $this->components->twoColumnDetail('Saved env snippet', 'storage/app/zak-knowledge-import.env');

        if ($runImport && $imports === [] && $importErrors !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{file: string, name: string, source_type: string}  $spec
     * @return array<string, mixed>
     */
    private function importChatFile(
        KnowledgeLifecycleService $lifecycle,
        User $user,
        $tenant,
        $community,
        array $spec,
        string $absolutePath,
    ): array {
        $uploaded = new UploadedFile(
            $absolutePath,
            basename($absolutePath),
            'text/plain',
            null,
            true,
        );

        $source = $lifecycle->import($user, [
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'name' => $spec['name'],
            'source_type' => $spec['source_type'],
            'uri' => 'whatsapp://import/'.Str::slug($spec['file']),
        ], $uploaded);

        return $this->submitAndPublish($lifecycle, $user, $source, $spec['file']);
    }

    /**
     * @return array<string, mixed>
     */
    private function importResourcePack(
        KnowledgeLifecycleService $lifecycle,
        User $user,
        $tenant,
        $community,
        string $absolutePath,
    ): array {
        $content = (string) file_get_contents($absolutePath);

        $source = $lifecycle->import($user, [
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'name' => 'UniPods / Wadhwani programme resource pack',
            'source_type' => 'markdown',
            'authority_tier' => KnowledgeAuthorityTier::OfficialAnnouncement->value,
            'content' => $content,
            'uri' => 'knowledge://import/resource-pack',
        ]);

        return $this->submitAndPublish($lifecycle, $user, $source, 'resource-pack.txt');
    }

    /**
     * @return array<string, mixed>
     */
    private function submitAndPublish(
        KnowledgeLifecycleService $lifecycle,
        User $user,
        KnowledgeSource $source,
        string $fileLabel,
    ): array {
        $lifecycle->submitForReview($user, $source);
        $published = $lifecycle->publish($user, $source->refresh());

        return [
            'file' => $fileLabel,
            'knowledge_document_id' => $published->id,
            'name' => $published->name,
            'lifecycle_status' => $published->lifecycle_status->value,
            'ai_source_id' => $published->ai_source_id,
            'ingest_part_count' => (int) (($published->metadata['ingest_part_count'] ?? 0) ?: 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function printSummary(array $summary): void
    {
        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>Tenant</>',
            $summary['scope']['tenant']['name'].' ('.$summary['scope']['tenant']['id'].')'
        );
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>Community</>',
            $summary['scope']['community']['name'].' ('.$summary['scope']['community']['id'].')'
        );
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>Importer</>',
            $summary['importer']['email']
        );
        $this->components->twoColumnDetail(
            '<fg=cyan;options=bold>Bearer token</>',
            $summary['auth']['token']
        );

        if ($summary['imports'] !== []) {
            $this->newLine();
            $this->components->info('Published imports');
            foreach ($summary['imports'] as $row) {
                $this->line(sprintf(
                    '  • %s [%s] id=%s',
                    $row['name'],
                    $row['lifecycle_status'],
                    $row['knowledge_document_id'],
                ));
            }
        }

        $this->newLine();
        $this->components->info('Full summary (JSON)');
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
