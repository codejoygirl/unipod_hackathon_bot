<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Services\AI\AiServiceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wipe Laravel knowledge drafts/published docs and AI chunks + embeddings.
 *
 * Typical server use:
 *   php artisan zak:purge-knowledge --community=01… --force
 *   php artisan zak:purge-knowledge --all --force
 */
class PurgeKnowledgeCommand extends Command
{
    protected $signature = 'zak:purge-knowledge
                            {--community= : Community ULID (Laravel + AI for that community)}
                            {--tenant= : Tenant ULID (all communities under the tenant)}
                            {--all : Purge ALL knowledge + embeddings (dangerous)}
                            {--laravel-only : Only delete Laravel knowledge_documents}
                            {--ai-only : Only call AI /ingestion/purge}
                            {--keep-glossary : Do not delete AI glossary_entries}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Purge knowledge base docs, AI chunks, and embeddings (community / tenant / all)';

    public function handle(AiServiceClient $ai): int
    {
        $communityId = trim((string) $this->option('community'));
        $tenantId = trim((string) $this->option('tenant'));
        $all = (bool) $this->option('all');
        $laravelOnly = (bool) $this->option('laravel-only');
        $aiOnly = (bool) $this->option('ai-only');
        $includeGlossary = ! (bool) $this->option('keep-glossary');
        $force = (bool) $this->option('force');

        if ($laravelOnly && $aiOnly) {
            $this->components->error('Use either --laravel-only or --ai-only, not both.');

            return self::FAILURE;
        }

        $scopeBits = array_filter([$communityId !== '', $tenantId !== '', $all]);
        if (count($scopeBits) !== 1) {
            $this->components->error('Pick exactly one scope: --community=ID, --tenant=ID, or --all.');

            return self::FAILURE;
        }

        if ($communityId !== '') {
            $community = Community::query()->find($communityId);
            if ($community === null) {
                $this->components->error("Community not found: {$communityId}");

                return self::FAILURE;
            }
            $tenantIdForAi = (string) $community->tenant_id;
            $label = "community {$community->name} ({$communityId})";
        } elseif ($tenantId !== '') {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                $this->components->error("Tenant not found: {$tenantId}");

                return self::FAILURE;
            }
            $tenantIdForAi = $tenantId;
            $label = "tenant {$tenant->name} ({$tenantId})";
        } else {
            $tenantIdForAi = null;
            $label = 'ALL tenants / communities';
        }

        $laravelCount = $this->countLaravelRows($communityId, $tenantId, $all);
        $this->components->warn("About to purge knowledge for: {$label}");
        $this->line("  Laravel knowledge_documents matching scope: {$laravelCount}");
        $this->line('  AI: knowledge_sources + versions + chunks/embeddings'
            .($includeGlossary && ($all || $tenantId !== '') ? ' + glossary' : ''));
        if ($laravelOnly) {
            $this->line('  Mode: Laravel only (AI index untouched)');
        } elseif ($aiOnly) {
            $this->line('  Mode: AI only (Laravel rows untouched)');
        }

        if (! $force && ! $this->confirm('This cannot be undone. Continue?', false)) {
            $this->components->info('Aborted.');

            return self::SUCCESS;
        }

        $laravelDeleted = 0;
        if (! $aiOnly) {
            $laravelDeleted = $this->deleteLaravelRows($communityId, $tenantId, $all);
            $this->components->info("Deleted {$laravelDeleted} Laravel knowledge_documents row(s).");
        }

        if (! $laravelOnly) {
            try {
                $aiResult = $ai->purgeKnowledge(
                    communityId: $communityId !== '' ? $communityId : null,
                    tenantId: $communityId !== '' ? $tenantIdForAi : ($tenantId !== '' ? $tenantId : null),
                    all: $all,
                    includeGlossary: $includeGlossary && ($all || $tenantId !== ''),
                );
                $this->components->info((string) ($aiResult['message'] ?? 'AI purge done.'));
                $this->table(
                    ['AI metric', 'Count'],
                    [
                        ['sources', (string) ($aiResult['sources_deleted'] ?? 0)],
                        ['versions', (string) ($aiResult['versions_deleted'] ?? 0)],
                        ['chunks/embeddings', (string) ($aiResult['chunks_deleted'] ?? 0)],
                        ['glossary', (string) ($aiResult['glossary_deleted'] ?? 0)],
                    ],
                );
            } catch (Throwable $e) {
                Log::error('zak.purge_knowledge.ai_failed', ['error' => $e->getMessage()]);
                $this->components->error('AI purge failed: '.$e->getMessage());
                $this->line('Laravel rows already deleted: '.$laravelDeleted);
                $this->line('Retry AI only with: php artisan zak:purge-knowledge '
                    .$this->retryFlags($communityId, $tenantId, $all).' --ai-only --force');

                return self::FAILURE;
            }
        }

        $this->components->info('Knowledge purge complete. Re-import with /import + /publish or /asset as needed.');

        return self::SUCCESS;
    }

    private function countLaravelRows(string $communityId, string $tenantId, bool $all): int
    {
        $q = KnowledgeSource::query();
        if ($all) {
            return $q->count();
        }
        if ($communityId !== '') {
            return $q->where('community_id', $communityId)->count();
        }

        return $q->where('tenant_id', $tenantId)->count();
    }

    private function deleteLaravelRows(string $communityId, string $tenantId, bool $all): int
    {
        $q = KnowledgeSource::query();
        if ($all) {
            // delete() on full table builder
            return (int) $q->delete();
        }
        if ($communityId !== '') {
            return (int) $q->where('community_id', $communityId)->delete();
        }

        return (int) $q->where('tenant_id', $tenantId)->delete();
    }

    private function retryFlags(string $communityId, string $tenantId, bool $all): string
    {
        if ($all) {
            return '--all';
        }
        if ($communityId !== '') {
            return '--community='.$communityId;
        }

        return '--tenant='.$tenantId;
    }
}
