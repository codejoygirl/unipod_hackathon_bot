<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Enums\KnowledgeLifecycleStatus;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin chat commands for knowledge drafts, published sources, assets, and features.
 */
final class AdminKnowledgeDesk
{
    public function __construct(
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly ChannelConversationService $conversation,
    ) {}

    /**
     * @return array{ok: bool, reply: string}|null  null = not this command family
     */
    public function tryHandle(
        string $text,
        User $user,
        Community $community,
        string $style = 'whatsapp',
    ): ?array {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\/?publish(?:\s+(.*))?$/iu', $trimmed, $m) === 1) {
            return $this->handlePublish(trim((string) ($m[1] ?? '')), $user, $community, $style);
        }

        if (preg_match('/^\/?(?:knowledge|kb)(?:\s+(.*))?$/iu', $trimmed, $m) === 1) {
            return $this->handleKnowledgeList(trim((string) ($m[1] ?? '')), $community, $style);
        }

        if (preg_match('/^\/?features(?:\s+(.*))?$/iu', $trimmed, $m) === 1) {
            return $this->handleFeaturesList(trim((string) ($m[1] ?? '')), $community, $style);
        }

        return null;
    }

    /**
     * Short suffix used in chat (easier than a full ULID).
     */
    public function shortId(KnowledgeSource $source): string
    {
        return strtoupper(substr((string) $source->id, -6));
    }

    /**
     * Friendly title from pasted import body (first meaningful line / heading).
     */
    public function suggestImportTitle(string $content, string $fallback = 'Imported knowledge'): string
    {
        $text = trim(str_replace("\r\n", "\n", $content));
        if ($text === '') {
            return $fallback;
        }

        foreach (preg_split("/\n+/u", $text) ?: [] as $raw) {
            $line = trim((string) $raw);
            if ($line === '') {
                continue;
            }
            // Section markers like === Slides === → use inner label when short.
            if (preg_match('/^=+\s*(.+?)\s*=+$/u', $line, $m) === 1) {
                $line = trim($m[1]);
            }
            if ($line === '' || str_starts_with(strtolower($line), 'http://')
                || str_starts_with(strtolower($line), 'https://')) {
                continue;
            }
            // Skip pure bullet separators.
            if (preg_match('/^[-*_=\s]+$/u', $line) === 1) {
                continue;
            }
            $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
            if (mb_strlen($line) < 3) {
                continue;
            }
            if (mb_strlen($line) > 80) {
                $line = rtrim(mb_substr($line, 0, 77)).'…';
            }

            return $line;
        }

        return $fallback;
    }

    /**
     * Whether quoted bot text looks like an import draft card.
     */
    public function isDraftCardText(string $quoted): bool
    {
        $q = mb_strtolower(trim($quoted));
        if ($q === '') {
            return false;
        }

        return str_contains($q, 'draft saved')
            || str_contains($q, 'imported')
            || (str_contains($q, '/publish') && preg_match('/\bid\b/i', $quoted) === 1);
    }

    /**
     * Pull the 6-char draft id from a draft card quote.
     */
    public function extractShortIdFromDraftCard(string $quoted): ?string
    {
        if (preg_match('/\bID:\s*`?([A-Z0-9]{4,12})`?/i', $quoted, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\/publish\s+([A-Z0-9]{4,12})\b/i', $quoted, $m) === 1) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * Swipe-reply body that means "publish this draft" (command-shaped only).
     */
    public function isPublishConfirmText(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || strcasecmp($t, '@zak') === 0) {
            return true;
        }

        return preg_match('/^\/?publish(?:\s+\S+)?$/iu', $t) === 1;
    }

    /**
     * Hint shown right after /import creates a draft.
     *
     * @param  'whatsapp'|'plain'|'telegram_html'  $style
     */
    public function draftCreatedReply(KnowledgeSource $source, string $style = 'whatsapp'): string
    {
        $short = $this->shortId($source);
        $kb = $this->conversation->highlightCommand('/knowledge', $style === 'whatsapp' ? 'whatsapp' : 'plain');
        $name = trim((string) $source->name);
        if ($name === '') {
            $name = 'Imported knowledge';
        }

        if ($style === 'whatsapp') {
            return "*Imported.*\n\n"
                ."*{$name}*\n"
                ."ID: `{$short}`\n\n"
                ."Swipe-reply with *publish* to make it live, or send:\n"
                ."```\n"
                ."/publish {$short}\n"
                ."```\n\n"
                ."See drafts anytime with {$kb}.";
        }

        return "Imported.\n\n"
            ."{$name}\n"
            ."ID: {$short}\n\n"
            ."Reply with publish to make it live, or send /publish {$short}.\n"
            ."See drafts anytime with {$kb}.";
    }

    /**
     * @return array{ok: bool, reply: string}
     */
    private function handlePublish(
        string $arg,
        User $user,
        Community $community,
        string $style,
    ): array {
        $pub = $this->conversation->highlightCommand('/publish', $style === 'whatsapp' ? 'whatsapp' : 'plain');
        $arg = trim($arg);

        if ($arg === '' || strcasecmp($arg, 'help') === 0) {
            return [
                'ok' => true,
                'reply' => $this->publishUsage($community, $style, $pub),
            ];
        }

        $source = null;
        if (strcasecmp($arg, 'latest') === 0 || strcasecmp($arg, 'last') === 0) {
            $source = $this->pendingQuery($community)->orderByDesc('created_at')->first();
            if ($source === null) {
                return [
                    'ok' => false,
                    'reply' => $style === 'whatsapp'
                        ? "*Nothing to publish.*\n\nNo draft or pending imports in this community."
                        : 'Nothing to publish. No draft or pending imports in this community.',
                ];
            }
        } else {
            $source = $this->resolveSource($arg, $community);
            if ($source === null) {
                return [
                    'ok' => false,
                    'reply' => $style === 'whatsapp'
                        ? "*I couldn't find that source.*\n\n"
                            ."Try {$pub} with a 6-character ID from "
                            .$this->conversation->highlightCommand('/knowledge drafts', 'whatsapp').'.'
                        : "I couldn't find that source. Try {$pub} with an ID from /knowledge drafts.",
                ];
            }
        }

        if ($source->lifecycle_status === KnowledgeLifecycleStatus::Published) {
            return [
                'ok' => true,
                'reply' => $style === 'whatsapp'
                    ? "*Already published.*\n\n*{$source->name}*\nID: `{$this->shortId($source)}`"
                    : "Already published: {$source->name} (ID {$this->shortId($source)})",
            ];
        }

        if (! in_array($source->lifecycle_status, [
            KnowledgeLifecycleStatus::Draft,
            KnowledgeLifecycleStatus::PendingReview,
        ], true)) {
            return [
                'ok' => false,
                'reply' => $style === 'whatsapp'
                    ? '*Cannot publish.* Status is `'.$source->lifecycle_status->value.'`.'
                    : 'Cannot publish. Status is '.$source->lifecycle_status->value.'.',
            ];
        }

        try {
            if ($source->lifecycle_status === KnowledgeLifecycleStatus::Draft) {
                $this->lifecycle->submitForReview($user, $source);
                $source = $source->fresh() ?? $source;
            }
            $published = $this->lifecycle->publish($user, $source);
        } catch (Throwable $e) {
            Log::error('admin_knowledge.publish_failed', [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'reply' => $style === 'whatsapp'
                    ? "*Publish failed.*\n\n".$e->getMessage()
                    : 'Publish failed: '.$e->getMessage(),
            ];
        }

        $parts = (int) (($published->metadata['ingest_part_count'] ?? 0) ?: 0);
        $name = trim((string) $published->name);
        if ($name === '') {
            $name = 'Imported knowledge';
        }

        if ($style === 'whatsapp') {
            $extra = $parts > 1 ? "\n(Large import — stored in {$parts} parts.)" : '';

            return [
                'ok' => true,
                'reply' => "*Published.*\n\n"
                    ."*{$name}* is live. Members can ask about it now."
                    .$extra,
            ];
        }

        $extra = $parts > 1 ? " Large import stored in {$parts} parts." : '';

        return [
            'ok' => true,
            'reply' => "Published. {$name} is live — members can ask about it now.".$extra,
        ];
    }

    /**
     * @return array{ok: bool, reply: string}
     */
    private function handleKnowledgeList(string $arg, Community $community, string $style): array
    {
        $filter = strtolower(trim($arg));
        if ($filter === '' || $filter === 'help') {
            return ['ok' => true, 'reply' => $this->knowledgeOverview($community, $style)];
        }

        return match ($filter) {
            'draft', 'drafts', 'pending', 'queue' => [
                'ok' => true,
                'reply' => $this->formatSourceList(
                    'Drafts / pending publish',
                    $this->pendingQuery($community)->orderByDesc('created_at')->limit(15)->get(),
                    $style,
                    emptyHint: 'No drafts waiting. Use /import to paste a chat export.',
                ),
            ],
            'published', 'live' => [
                'ok' => true,
                'reply' => $this->formatSourceList(
                    'Published knowledge',
                    KnowledgeSource::query()
                        ->where('community_id', $community->id)
                        ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
                        ->where(function ($q): void {
                            $q->whereNull('metadata->asset_identity')
                                ->orWhere('metadata->asset_identity', '');
                        })
                        ->orderByDesc('published_at')
                        ->limit(15)
                        ->get(),
                    $style,
                    emptyHint: 'Nothing published yet (non-asset docs).',
                    showPublishedAt: true,
                ),
            ],
            'asset', 'assets', 'file', 'files' => [
                'ok' => true,
                'reply' => $this->formatSourceList(
                    'Program files / assets',
                    KnowledgeSource::query()
                        ->where('community_id', $community->id)
                        ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
                        ->whereNotNull('metadata->asset_identity')
                        ->where('metadata->asset_identity', '!=', '')
                        ->orderByDesc('published_at')
                        ->limit(20)
                        ->get(),
                    $style,
                    emptyHint: 'No files yet. Use /asset to register Drive links.',
                    showPublishedAt: true,
                    preferUrl: true,
                ),
            ],
            'all' => ['ok' => true, 'reply' => $this->knowledgeOverview($community, $style, detailed: true)],
            default => [
                'ok' => false,
                'reply' => $style === 'whatsapp'
                    ? "*Unknown filter.*\n\nTry:\n```\n/knowledge\n/knowledge drafts\n/knowledge published\n/knowledge assets\n```"
                    : 'Unknown filter. Try /knowledge, /knowledge drafts, /knowledge published, /knowledge assets.',
            ],
        };
    }

    /**
     * @return array{ok: bool, reply: string}
     */
    private function handleFeaturesList(string $arg, Community $community, string $style): array
    {
        $filter = strtolower(trim($arg));
        if ($filter === 'help') {
            return [
                'ok' => true,
                'reply' => $style === 'whatsapp'
                    ? "*Feature requests*\n\n```\n/features\n/features open\n/features decided\n```"
                    : "Feature requests:\n/features\n/features open\n/features decided",
            ];
        }

        $wantOpen = $filter === '' || $filter === 'open' || $filter === 'pending';
        $wantDecided = $filter === 'decided' || $filter === 'done' || $filter === 'closed';
        if ($filter === 'all') {
            $wantOpen = true;
            $wantDecided = true;
        }

        $sections = [];
        if ($wantOpen) {
            $sections[] = $this->formatOpenFeatures($community, $style);
        }
        if ($wantDecided) {
            $sections[] = $this->formatDecidedFeatures($community, $style);
        }
        if ($sections === []) {
            $sections[] = $this->formatOpenFeatures($community, $style);
        }

        return ['ok' => true, 'reply' => trim(implode("\n\n", array_filter($sections)))];
    }

    private function publishUsage(Community $community, string $style, string $pub): string
    {
        $pending = $this->pendingQuery($community)->orderByDesc('created_at')->limit(5)->get();
        $lines = [];
        foreach ($pending as $source) {
            $lines[] = '• `'.$this->shortId($source).'`  '.$this->oneLineTitle($source);
        }

        if ($style === 'whatsapp') {
            $body = "*Publish a knowledge draft*\n\n"
                ."```\n"
                ."/publish <ID>\n"
                ."/publish latest\n"
                ."```\n\n";
            if ($lines === []) {
                return $body.'No drafts waiting. Paste an export with /import first.';
            }

            return $body."*Waiting:*\n".implode("\n", $lines);
        }

        $body = "Publish a knowledge draft:\n  {$pub} <ID>\n  {$pub} latest\n\n";
        if ($lines === []) {
            return $body.'No drafts waiting. Paste an export with /import first.';
        }

        return $body."Waiting:\n".implode("\n", $lines);
    }

    private function knowledgeOverview(Community $community, string $style, bool $detailed = false): string
    {
        $drafts = $this->pendingQuery($community)->count();
        $published = KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
            ->where(function ($q): void {
                $q->whereNull('metadata->asset_identity')
                    ->orWhere('metadata->asset_identity', '');
            })
            ->count();
        $assets = KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
            ->whereNotNull('metadata->asset_identity')
            ->where('metadata->asset_identity', '!=', '')
            ->count();

        if ($style === 'whatsapp') {
            $out = "*Knowledge desk - {$community->name}*\n\n"
                ."• Drafts / pending: *{$drafts}*\n"
                ."• Published docs: *{$published}*\n"
                ."• Files / assets: *{$assets}*\n\n"
                ."```\n"
                ."/knowledge drafts\n"
                ."/knowledge published\n"
                ."/knowledge assets\n"
                ."/publish <ID>\n"
                ."```";
        } else {
            $out = "Knowledge desk - {$community->name}\n\n"
                ."Drafts / pending: {$drafts}\n"
                ."Published docs: {$published}\n"
                ."Files / assets: {$assets}\n\n"
                ."Filters: /knowledge drafts | published | assets\n"
                .'Publish: /publish <ID>';
        }

        if (! $detailed) {
            return $out;
        }

        $draftList = $this->formatSourceList(
            'Drafts',
            $this->pendingQuery($community)->orderByDesc('created_at')->limit(8)->get(),
            $style,
            emptyHint: '(none)',
        );
        $assetList = $this->formatSourceList(
            'Files',
            KnowledgeSource::query()
                ->where('community_id', $community->id)
                ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
                ->whereNotNull('metadata->asset_identity')
                ->orderByDesc('published_at')
                ->limit(8)
                ->get(),
            $style,
            emptyHint: '(none)',
            preferUrl: true,
        );

        return $out."\n\n".$draftList."\n\n".$assetList;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, KnowledgeSource>  $sources
     */
    private function formatSourceList(
        string $heading,
        $sources,
        string $style,
        string $emptyHint = 'None.',
        bool $showPublishedAt = false,
        bool $preferUrl = false,
    ): string {
        if ($sources->isEmpty()) {
            return $style === 'whatsapp'
                ? "*{$heading}*\n\n{$emptyHint}"
                : "{$heading}\n{$emptyHint}";
        }

        $lines = [];
        $i = 1;
        foreach ($sources as $source) {
            $id = $this->shortId($source);
            $title = $this->oneLineTitle($source);
            $extra = '';
            if ($preferUrl) {
                $url = trim((string) (($source->metadata['delivery_url'] ?? '') ?: ''));
                if ($url !== '') {
                    $extra = preg_match('/[…]|\.{2,}(?:\/|$|\?|#)|YOUR_[A-Z0-9_]+/u', $url) === 1
                        ? "\n   (link incomplete — re-run /asset with the full Drive Share → Copy link)"
                        : "\n   ".$url;
                }
            } elseif ($showPublishedAt && $source->published_at !== null) {
                $extra = ' · '.$source->published_at->timezone((string) config('app.timezone', 'UTC'))->format('Y-m-d');
            }
            $status = $source->lifecycle_status === KnowledgeLifecycleStatus::Published
                ? ''
                : ' ['.$source->lifecycle_status->value.']';
            $lines[] = "{$i}. `{$id}`  {$title}{$status}{$extra}";
            $i++;
        }

        if ($style === 'whatsapp') {
            return "*{$heading}*\n\n".implode("\n", $lines);
        }

        return "{$heading}\n".implode("\n", $lines);
    }

    private function formatOpenFeatures(Community $community, string $style): string
    {
        $open = [];
        $ids = Cache::get('spike_escalations', []);
        if (! is_array($ids)) {
            $ids = [];
        }
        foreach ($ids as $id) {
            $record = Cache::get('spike_escalation:'.$id);
            if (! is_array($record)) {
                continue;
            }
            if (($record['type'] ?? '') !== 'feature_request') {
                continue;
            }
            if (trim((string) ($record['resolved_at'] ?? '')) !== '') {
                continue;
            }
            if ((string) ($record['community_id'] ?? '') !== (string) $community->id) {
                continue;
            }
            $open[] = $record;
            if (count($open) >= 12) {
                break;
            }
        }

        if ($open === []) {
            return $style === 'whatsapp'
                ? "*Open feature requests*\n\nNone waiting."
                : "Open feature requests\nNone waiting.";
        }

        $lines = [];
        $n = 1;
        foreach ($open as $record) {
            $ref = strtoupper(trim((string) ($record['ref'] ?? '')));
            $who = trim((string) ($record['from_name'] ?? 'Member'));
            $who = ltrim($who, '~');
            $content = trim((string) ($record['content'] ?? $record['question'] ?? ''));
            $content = $this->clip($content, 120);
            $refBit = $ref !== '' ? "`{$ref}`" : '(no ref)';
            $lines[] = "{$n}. {$refBit} - {$who}\n   {$content}";
            $n++;
        }

        $footer = $style === 'whatsapp'
            ? "\n\nApprove / decline:\n```\n/approve REF\n/decline REF\n```"
            : "\n\nApprove / decline with /approve REF or /decline REF.";

        $head = $style === 'whatsapp' ? "*Open feature requests*" : 'Open feature requests';

        return $head."\n\n".implode("\n", $lines).$footer;
    }

    private function formatDecidedFeatures(Community $community, string $style): string
    {
        $log = Cache::get('spike_feature_decisions', []);
        if (! is_array($log)) {
            $log = [];
        }
        $rows = [];
        foreach ($log as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['community_id'] ?? '') !== (string) $community->id) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= 10) {
                break;
            }
        }

        if ($rows === []) {
            return $style === 'whatsapp'
                ? "*Recent decisions*\n\nNone logged yet."
                : "Recent decisions\nNone logged yet.";
        }

        $lines = [];
        $n = 1;
        foreach ($rows as $row) {
            $ref = strtoupper(trim((string) ($row['ref'] ?? '')));
            $decision = strtolower(trim((string) ($row['decision'] ?? '')));
            $content = $this->clip(trim((string) ($row['content'] ?? '')), 100);
            $when = trim((string) ($row['resolved_at'] ?? ''));
            $whenBit = $when !== '' ? ' · '.mb_substr($when, 0, 10) : '';
            $refBit = $ref !== '' ? "`{$ref}`" : '-';
            $lines[] = "{$n}. {$refBit} *{$decision}*{$whenBit}\n   {$content}";
            $n++;
        }

        $head = $style === 'whatsapp' ? '*Recent decisions*' : 'Recent decisions';

        return $head."\n\n".implode("\n", $lines);
    }

    private function pendingQuery(Community $community)
    {
        return KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->whereIn('lifecycle_status', [
                KnowledgeLifecycleStatus::Draft,
                KnowledgeLifecycleStatus::PendingReview,
            ]);
    }

    private function resolveSource(string $token, Community $community): ?KnowledgeSource
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $exact = KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->whereKey($token)
            ->first();
        if ($exact !== null) {
            return $exact;
        }

        $suffix = strtoupper($token);
        if (strlen($suffix) < 4) {
            return null;
        }

        return KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->whereRaw('UPPER(id) LIKE ?', ['%'.$suffix])
            ->orderByDesc('created_at')
            ->first();
    }

    private function oneLineTitle(KnowledgeSource $source): string
    {
        $name = trim((string) $source->name);
        if ($name === '') {
            $name = 'Untitled';
        }

        return $this->clip($name, 70);
    }

    private function clip(string $text, int $max): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
