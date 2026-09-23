<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresAssistantDemoScope;
use App\Enums\KnowledgeAuthorityTier;
use App\Enums\KnowledgeLifecycleStatus;
use App\Enums\MembershipRole;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use Illuminate\Console\Command;
use Throwable;

class SeedAssistantDemoCommand extends Command
{
    use EnsuresAssistantDemoScope;

    protected $signature = 'zak:seed-assistant-demo
                            {--email= : Demo user email (default: WHATSAPP_WEB_SPIKE_DEFAULT_USER_EMAIL or TELEGRAM_SPIKE_DEFAULT_USER_EMAIL)}
                            {--password=password123 : Demo user password}
                            {--skip-ai : Skip AI ingestion (Laravel-only seed)}';

    protected $description = 'Seed tenant/community/user + Sanctum token and print curl for /api/v1/assistant/ask';

    public function handle(AiServiceClient $ai): int
    {
        $email = $this->resolveDemoUserEmail($this->option('email'));
        $password = (string) $this->option('password');
        $aiIngested = false;
        $aiError = null;

        $this->components->info('Seeding Zak assistant demo data…');

        [$tenant, $community] = array_slice($this->ensureDemoTenantAndCommunity(), 0, 2);
        [$user] = $this->ensureDemoAdminUser($tenant, $community, $email, $password);

        $membership = Membership::query()->where([
            'tenant_id' => $tenant->id,
            'community_id' => $community->id,
            'user_id' => $user->id,
        ])->firstOrFail();

        $seedDocs = [
            [
                'uri' => 'doc://demo-clinic-hours',
                'name' => 'Clinic hours',
                'source_type' => 'markdown',
                'authority_tier' => KnowledgeAuthorityTier::OfficialAnnouncement,
                'content' => 'The community clinic opens on Saturday at 9am and closes at 1pm.',
                'metadata' => ['seeded_by' => 'zak:seed-assistant-demo'],
            ],
            [
                'uri' => 'community://'.$community->id.'/asset/gfolder:1bximvs0xra5nfmdkvbdbzjgmuuqptlbs',
                'name' => 'UniPod Community Resources',
                'source_type' => 'markdown',
                'authority_tier' => KnowledgeAuthorityTier::VerifiedResource,
                'content' => "UniPod Community Resources\n\nhttps://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs\n\nOfficial UniPod community Google Drive hub containing core programme toolkits, templates, guidelines, and cohort learning materials.",
                'metadata' => [
                    'seeded_by' => 'zak:seed-assistant-demo',
                    'asset_kind' => 'folder',
                    'asset_identity' => 'gfolder:1bximvs0xra5nfmdkvbdbzjgmuuqptlbs',
                    'delivery' => 'google_drive',
                    'delivery_url' => 'https://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
                ],
            ],
            [
                'uri' => 'community://'.$community->id.'/asset/gdoc:1yzvsmxcbq_evwzk-zx3ibhyxwdhxrs5o',
                'name' => 'UniPods Handbook',
                'source_type' => 'markdown',
                'authority_tier' => KnowledgeAuthorityTier::VerifiedResource,
                'content' => "UniPods Handbook\n\nhttps://drive.google.com/file/d/1YZvsMxcbq_EvWZk-Zx3IBHYxwdhXRs5O/view\n\nComprehensive UniPods programme handbook with cohort guidelines, expectations, innovation milestones, and mentor directories.",
                'metadata' => [
                    'seeded_by' => 'zak:seed-assistant-demo',
                    'asset_kind' => 'handbook',
                    'asset_identity' => 'gdoc:1yzvsmxcbq_evwzk-zx3ibhyxwdhxrs5o',
                    'delivery' => 'google_drive',
                    'delivery_url' => 'https://drive.google.com/file/d/1YZvsMxcbq_EvWZk-Zx3IBHYxwdhXRs5O/view',
                ],
            ],
            [
                'uri' => 'community://'.$community->id.'/asset/gdoc:1jkykc8xmp1msh7jpga-c0lpszceg_gkf',
                'name' => 'Wadhwani Ignite Module Slides',
                'source_type' => 'markdown',
                'authority_tier' => KnowledgeAuthorityTier::VerifiedResource,
                'content' => "Wadhwani Ignite Module Slides\n\nhttps://drive.google.com/file/d/1jkYKc8xmP1Msh7jPGaC0lPsZceG_GkFh/view\n\nWadhwani Ignite entrepreneurship training decks, curriculum slides, and workshop resources.",
                'metadata' => [
                    'seeded_by' => 'zak:seed-assistant-demo',
                    'asset_kind' => 'slides',
                    'asset_identity' => 'gdoc:1jkykc8xmp1msh7jpga-c0lpszceg_gkf',
                    'delivery' => 'google_drive',
                    'delivery_url' => 'https://drive.google.com/file/d/1jkYKc8xmP1Msh7jPGaC0lPsZceG_GkFh/view',
                ],
            ],
        ];

        foreach ($seedDocs as $doc) {
            $source = KnowledgeSource::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'uri' => $doc['uri'],
                ],
                [
                    'community_id' => $community->id,
                    'created_by' => $user->id,
                    'name' => $doc['name'],
                    'source_type' => $doc['source_type'],
                    'authority_tier' => $doc['authority_tier'],
                    'lifecycle_status' => KnowledgeLifecycleStatus::Published,
                    'language' => 'en',
                    'content' => $doc['content'],
                    'content_sha256' => hash('sha256', $doc['content']),
                    'published_at' => now(),
                    'metadata' => $doc['metadata'],
                ],
            );

            if (! $this->option('skip-ai')) {
                try {
                    $response = $ai->syncDocument(
                        tenantId: $tenant->id,
                        communityId: $community->id,
                        uri: $doc['uri'],
                        name: $doc['name'],
                        sourceType: $doc['source_type'],
                        content: $doc['content'],
                        authorityTier: $doc['authority_tier']->value,
                        metadata: $doc['metadata'],
                    );
                    $source->ai_source_id = $response['source_id'] ?? null;
                    $source->ai_version_id = $response['version_id'] ?? null;
                    $source->save();
                    $aiIngested = true;
                } catch (Throwable $e) {
                    $aiError = $e->getMessage();
                }
            } else {
                $aiError = 'skipped (--skip-ai)';
            }
        }

        $user->tokens()->where('name', 'zak-demo')->delete();
        $plainTextToken = $user->createToken('zak-demo')->plainTextToken;

        $base = rtrim((string) config('app.url'), '/');
        $askBody = json_encode([
            'query' => 'When does the clinic open?',
            'community_ids' => [$community->id],
            'target_language' => 'en',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $summary = [
            'what_was_seeded' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ],
                'community' => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                    'tenant_id' => $community->tenant_id,
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $email,
                    'password' => $password,
                ],
                'membership' => [
                    'id' => $membership->id,
                    'role' => $membership->role->value,
                    'user_id' => $user->id,
                    'tenant_id' => $tenant->id,
                    'community_id' => $community->id,
                ],
                'knowledge_document' => [
                    'id' => $source->id,
                    'table' => 'knowledge_documents',
                    'name' => $source->name,
                    'uri' => $source->uri,
                    'lifecycle_status' => $source->lifecycle_status->value,
                    'authority_tier' => $source->authority_tier->value,
                    'source_type' => $source->source_type,
                    'language' => $source->language,
                    'content' => $source->content,
                    'ai_source_id' => $source->ai_source_id,
                    'ai_version_id' => $source->ai_version_id,
                    'published_at' => $source->published_at?->toIso8601String(),
                ],
                'auth' => [
                    'type' => 'Sanctum personal access token',
                    'token_name' => 'zak-demo',
                    'token' => $plainTextToken,
                    'header' => 'Authorization: Bearer '.$plainTextToken,
                ],
                'ai_ingest' => [
                    'attempted' => ! $this->option('skip-ai'),
                    'success' => $aiIngested,
                    'detail' => $aiIngested ? 'Document synced to AI /ingestion/sync' : $aiError,
                ],
            ],
            'how_to_test_ask' => [
                'method' => 'POST',
                'url' => $base.'/api/v1/assistant/ask',
                'headers' => [
                    'Authorization' => 'Bearer '.$plainTextToken,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_decode($askBody, true, 512, JSON_THROW_ON_ERROR),
            ],
        ];

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Seeded tenant</>', $tenant->name.' ('.$tenant->id.')');
        $this->components->twoColumnDetail('<fg=green;options=bold>Seeded community</>', $community->name.' ('.$community->id.')');
        $this->components->twoColumnDetail('<fg=green;options=bold>Seeded user</>', $email.' / '.$password);
        $this->components->twoColumnDetail('<fg=green;options=bold>Membership role</>', MembershipRole::CommunityAdmin->value);
        $this->components->twoColumnDetail('<fg=green;options=bold>Knowledge doc</>', $source->name.' ['.$source->lifecycle_status->value.']');
        $this->components->twoColumnDetail('<fg=green;options=bold>Knowledge URI</>', $source->uri);
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>AI ingest</>',
            $aiIngested ? '<fg=green>OK</>' : '<fg=yellow>'.($aiError ?? 'not run').'</>'
        );
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Bearer token</>', $plainTextToken);

        $this->newLine();
        $this->components->info('Seeded data (pretty JSON)');
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->components->info('PowerShell ask');
        $this->line(<<<PS
\$token = '{$plainTextToken}'
\$communityId = '{$community->id}'
\$body = @{
  query = 'When does the clinic open?'
  community_ids = @(\$communityId)
  target_language = 'en'
} | ConvertTo-Json
Invoke-RestMethod -Method POST -Uri '{$base}/api/v1/assistant/ask' `
  -Headers @{ Authorization = "Bearer \$token"; Accept = 'application/json' } `
  -ContentType 'application/json' -Body \$body | ConvertTo-Json -Depth 8
PS);

        $this->newLine();
        $this->components->info('curl ask');
        $compactBody = json_encode([
            'query' => 'When does the clinic open?',
            'community_ids' => [$community->id],
            'target_language' => 'en',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->line(<<<BASH
curl -sS -X POST '{$base}/api/v1/assistant/ask' \\
  -H 'Authorization: Bearer {$plainTextToken}' \\
  -H 'Accept: application/json' \\
  -H 'Content-Type: application/json' \\
  -d '{$compactBody}'
BASH);

        $jsonOut = base_path('storage/app/zak-demo-ask.json');
        file_put_contents(
            $jsonOut,
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );

        $envOut = base_path('storage/app/zak-demo-ask.env');
        file_put_contents($envOut, implode(PHP_EOL, [
            'ZAK_API_BASE='.$base,
            'ZAK_DEMO_EMAIL='.$email,
            'ZAK_DEMO_PASSWORD='.$password,
            'ZAK_DEMO_TOKEN='.$plainTextToken,
            'ZAK_TENANT_ID='.$tenant->id,
            'ZAK_COMMUNITY_ID='.$community->id,
            'ZAK_KNOWLEDGE_URI='.$source->uri,
            'ZAK_KNOWLEDGE_ID='.$source->id,
            '',
        ]));

        $this->newLine();
        $this->components->twoColumnDetail('Saved JSON summary', 'storage/app/zak-demo-ask.json');
        $this->components->twoColumnDetail('Saved env vars', 'storage/app/zak-demo-ask.env');

        return self::SUCCESS;
    }
}
