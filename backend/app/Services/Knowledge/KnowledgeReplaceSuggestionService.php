<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Enums\KnowledgeLifecycleStatus;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\AI\AiServiceClient;
use Illuminate\Support\Facades\Log;

/**
 * Model-gated suggestions for which published knowledge a new import may supersede.
 */
final class KnowledgeReplaceSuggestionService
{
    public function __construct(
        private readonly AiServiceClient $aiClient,
        private readonly KnowledgeLifecycleService $lifecycle,
    ) {}

    /**
     * @return array{replace_mode: bool, body: string}
     */
    public static function parseImportMessage(string $afterImportPrefix): array
    {
        $body = trim($afterImportPrefix);
        if ($body === '') {
            return ['replace_mode' => false, 'body' => ''];
        }

        if (preg_match('/^replace\b\s*(.*)$/isu', $body, $m) === 1) {
            return [
                'replace_mode' => true,
                'body' => trim((string) ($m[1] ?? '')),
            ];
        }

        return ['replace_mode' => false, 'body' => $body];
    }

    /**
     * @return array{
     *   target: string,
     *   archive_short_ids: list<string>|null,
     *   use_suggested: bool
     * }
     */
    public static function parsePublishArgs(string $arg): array
    {
        $arg = trim($arg);
        if ($arg === '') {
            return ['target' => '', 'archive_short_ids' => null, 'use_suggested' => false];
        }

        if (preg_match('/^(.+?)\s+(?:replace|drop|archive)\s+(.+)$/iu', $arg, $m) !== 1) {
            return ['target' => $arg, 'archive_short_ids' => null, 'use_suggested' => false];
        }

        $target = trim((string) $m[1]);
        $rest = trim((string) $m[2]);
        if (in_array(strtolower($rest), ['suggested', 'listed', 'all'], true)) {
            return ['target' => $target, 'archive_short_ids' => null, 'use_suggested' => true];
        }

        $parts = preg_split('/[\s,;]+/', $rest) ?: [];
        $ids = [];
        foreach ($parts as $part) {
            $part = strtoupper(trim((string) $part));
            if ($part !== '') {
                $ids[] = $part;
            }
        }

        return ['target' => $target, 'archive_short_ids' => $ids, 'use_suggested' => false];
    }

    /**
     * @return list<array{short_id: string, name: string, reason: string}>
     */
    public function suggestForDraft(Community $community, KnowledgeSource $draft): array
    {
        $published = KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
            ->where(function ($q): void {
                $q->whereNull('metadata->asset_identity')
                    ->orWhere('metadata->asset_identity', '');
            })
            ->orderByDesc('published_at')
            ->limit(25)
            ->get();

        if ($published->isEmpty()) {
            return [];
        }

        $catalog = [];
        foreach ($published as $source) {
            if ((string) $source->id === (string) $draft->id) {
                continue;
            }
            $catalog[] = [
                'short_id' => self::shortId($source),
                'name' => trim((string) $source->name) ?: 'Untitled',
                'excerpt' => $this->excerpt((string) $source->content),
                'published_at' => $source->published_at?->toIso8601String(),
            ];
        }

        if ($catalog === []) {
            return [];
        }

        $suggested = $this->aiClient->suggestKnowledgeReplaceCandidates(
            communityName: $community->name,
            newImportName: trim((string) $draft->name) ?: 'New import',
            newImportExcerpt: $this->excerpt((string) $draft->content),
            publishedCatalog: $catalog,
        );

        if ($suggested === null) {
            return [];
        }

        $allowed = [];
        foreach ($catalog as $row) {
            $allowed[strtoupper($row['short_id'])] = $row['name'];
        }

        $out = [];
        foreach ($suggested as $row) {
            $sid = strtoupper(trim((string) ($row['short_id'] ?? '')));
            if ($sid === '' || ! isset($allowed[$sid])) {
                continue;
            }
            $reason = trim((string) ($row['reason'] ?? ''));
            $out[] = [
                'short_id' => $sid,
                'name' => $allowed[$sid],
                'reason' => $reason !== '' ? $reason : 'Likely superseded by this import.',
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{short_id: string, name: string, reason: string}>  $suggestions
     */
    public function persistSuggestions(KnowledgeSource $draft, array $suggestions): void
    {
        $meta = $draft->metadata ?? [];
        $meta['replace_suggestions'] = $suggestions;
        $draft->metadata = $meta;
        $draft->save();
    }

    /**
     * @param  list<string>|null  $explicitShortIds
     * @return list<string> archived short ids
     */
    public function archiveAfterPublish(
        User $user,
        Community $community,
        KnowledgeSource $publishedDraft,
        ?array $explicitShortIds,
        bool $useSuggested,
    ): array {
        $shortIds = [];
        if ($useSuggested) {
            $meta = $publishedDraft->metadata ?? [];
            $rows = is_array($meta['replace_suggestions'] ?? null) ? $meta['replace_suggestions'] : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sid = strtoupper(trim((string) ($row['short_id'] ?? '')));
                if ($sid !== '') {
                    $shortIds[] = $sid;
                }
            }
        } elseif ($explicitShortIds !== null) {
            $shortIds = array_values(array_unique(array_map(
                static fn (string $id): string => strtoupper(trim($id)),
                $explicitShortIds,
            )));
        }

        $archived = [];
        foreach ($shortIds as $shortId) {
            $source = $this->resolvePublishedByShortId($community, $shortId);
            if ($source === null) {
                continue;
            }
            if ((string) $source->id === (string) $publishedDraft->id) {
                continue;
            }
            try {
                $this->lifecycle->unpublish($user, $source);
                $archived[] = $shortId;
            } catch (\Throwable $e) {
                Log::warning('knowledge.replace_archive_failed', [
                    'short_id' => $shortId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $archived;
    }

    private function resolvePublishedByShortId(Community $community, string $shortId): ?KnowledgeSource
    {
        $suffix = strtoupper(trim($shortId));
        if (strlen($suffix) < 4) {
            return null;
        }

        return KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
            ->whereRaw('UPPER(id) LIKE ?', ['%'.$suffix])
            ->orderByDesc('published_at')
            ->first();
    }

    public static function shortId(KnowledgeSource $source): string
    {
        return strtoupper(substr((string) $source->id, -6));
    }

    private function excerpt(string $content): string
    {
        $text = trim(str_replace("\r\n", "\n", $content));
        if ($text === '') {
            return '';
        }

        return mb_substr($text, 0, 1200);
    }
}
