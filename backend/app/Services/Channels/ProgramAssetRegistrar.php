<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Enums\KnowledgeAuthorityTier;
use App\Enums\KnowledgeLifecycleStatus;
use App\Models\Community;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeLifecycleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Register Google Drive / Docs program files as published knowledge.
 *
 * Delivery URL stays the Drive link; Zak indexes title + URL (+ optional blurb).
 * Identity key (Drive file/folder id) is shared across manual /asset, bulk import,
 * and future Drive poll so the same file never duplicates in a community.
 */
final class ProgramAssetRegistrar
{
    public function __construct(
        private readonly KnowledgeLifecycleService $lifecycle,
        private readonly ChannelConversationService $conversation,
    ) {}

    /**
     * @return array{ok: bool, reply: string}
     */
    public function registerFromCommand(
        string $channel,
        string $commandText,
        User $user,
        Community $community,
        string $style = 'whatsapp',
    ): array {
        $body = trim($commandText);
        foreach (['/ASSET', 'ASSET'] as $prefix) {
            if (str_starts_with(strtoupper($body), $prefix)) {
                $body = trim(substr($body, strlen($prefix)));
                break;
            }
        }

        $cmd = $this->conversation->highlightCommand('/asset', $style);
        if ($body === '') {
            return [
                'ok' => false,
                'reply' => $this->usageReply($cmd, $style),
            ];
        }

        // Bulk: /asset import <lines…>  (paste a list; same line shape as single /asset)
        if (preg_match('/^import(?:\s+|$)/iu', $body) === 1) {
            $list = trim((string) preg_replace('/^import\s*/iu', '', $body));

            return $this->registerManyFromText($channel, $list, $user, $community, $style);
        }

        return $this->registerOneFromLine($channel, $body, $user, $community, $style);
    }

    /**
     * Parse a pasted / uploaded text body of asset lines (one per line).
     *
     * @return array{ok: bool, reply: string}
     */
    public function registerManyFromText(
        string $channel,
        string $text,
        User $user,
        Community $community,
        string $style = 'whatsapp',
    ): array {
        $cmd = $this->conversation->highlightCommand('/asset', $style);
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            return [
                'ok' => false,
                'reply' => "Paste one asset per line after {$cmd} import, like:\n"
                    ."```\n"
                    ."/asset import\n"
                    ."handbook UniPods Handbook https://drive.google.com/file/d/YOUR_FILE_ID/view\n"
                    ."form Session signup https://docs.google.com/forms/d/YOUR_FORM_ID/viewform\n"
                    ."```\n\n"
                    .'Paste full Share → Copy link URLs. Same Drive file id is updated, not duplicated.',
            ];
        }

        $lines = preg_split("/\n+/u", $text) ?: [];
        $added = 0;
        $updated = 0;
        $failed = 0;
        $notes = [];

        foreach ($lines as $rawLine) {
            $line = trim((string) $rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Allow accidental nested /asset prefixes in a pasted file.
            foreach (['/ASSET', 'ASSET'] as $prefix) {
                if (str_starts_with(strtoupper($line), $prefix)) {
                    $line = trim(substr($line, strlen($prefix)));
                    break;
                }
            }
            if (preg_match('/^import\b/iu', $line) === 1) {
                continue;
            }

            $result = $this->registerOneFromLine($channel, $line, $user, $community, $style);
            if (! ($result['ok'] ?? false)) {
                $failed++;
                if (count($notes) < 5) {
                    $notes[] = '• '.mb_substr((string) ($result['reply'] ?? 'failed'), 0, 120);
                }

                continue;
            }

            $reply = (string) ($result['reply'] ?? '');
            if (str_contains(mb_strtolower($reply), 'updated')) {
                $updated++;
            } else {
                $added++;
            }
        }

        if ($added === 0 && $updated === 0) {
            return [
                'ok' => false,
                'reply' => "No assets published.\n"
                    .($notes !== [] ? implode("\n", $notes) : 'Check each line: kind title https://…'),
            ];
        }

        $summary = "Done. *{$added}* new, *{$updated}* updated"
            .($failed > 0 ? ", *{$failed}* skipped" : '')
            .'.';
        if ($notes !== []) {
            $summary .= "\n\nSkipped:\n".implode("\n", $notes);
        }

        return ['ok' => true, 'reply' => $summary];
    }

    /**
     * @return array{ok: bool, reply: string, identity?: string}
     */
    public function registerOneFromLine(
        string $channel,
        string $line,
        User $user,
        Community $community,
        string $style = 'whatsapp',
        string $origin = 'admin_asset',
    ): array {
        $line = trim($line);
        // Optional CSV / pipe: kind|title|url
        if (substr_count($line, '|') >= 2 && preg_match('/^(handbook|form|slides|other)\s*\|/iu', $line) === 1) {
            $bits = array_map('trim', explode('|', $line));
            if (count($bits) >= 3) {
                $line = $bits[0].' '.$bits[1].' '.$bits[count($bits) - 1];
            }
        }

        if (preg_match('/^(handbook|form|slides|other)\s+(.+)$/isu', $line, $m) !== 1) {
            return [
                'ok' => false,
                'reply' => 'Usage: kind title https://… (kinds: handbook, form, slides, other)',
            ];
        }

        $kind = strtolower(trim($m[1]));
        $rest = trim($m[2]);
        if (preg_match('/^(.*?)\s+(https?:\/\/\S+)\s*$/u', $rest, $um) !== 1) {
            return [
                'ok' => false,
                'reply' => 'Include a full https:// Drive (or Docs) link at the end.',
            ];
        }

        $title = trim($um[1]);
        $url = rtrim(trim($um[2]), ".,);]}>\"'");
        if ($title === '' || $url === '') {
            return [
                'ok' => false,
                'reply' => 'Title and Drive URL are both required.',
            ];
        }

        $canonical = $this->canonicalizeGoogleUrl($url);
        if ($canonical === null) {
            $looksGoogle = preg_match('/(?:drive|docs|sheets|slides)\.google\.com/i', $url) === 1;
            $looksTruncated = preg_match('/[…]|\.{2,}(?:\/|$|\?|#)|YOUR_[A-Z0-9_]+/u', $url) === 1;

            return [
                'ok' => false,
                'reply' => $looksTruncated
                    ? 'That Drive link looks incomplete (placeholder or cut off). In Drive: Share → Copy link, then paste the *full* https URL — not the YOUR_FOLDER_ID example.'
                    : ($looksGoogle
                        ? 'That Google link is missing a usable file/folder id. Paste the full share link from Drive.'
                        : 'For now, use a Google Drive / Docs / Sheets / Slides https link.'),
            ];
        }

        $identity = $this->assetIdentityKey($canonical);
        $uri = 'community://'.$community->id.'/asset/'.$identity;

        $content = $title."\n\n".$canonical."\n\n"
            .'Program file ('.$kind.'). Members can ask Zak for this file.';

        $metadata = [
            'channel' => $channel,
            'origin' => $origin,
            'asset_key' => $kind.'-'.Str::slug(mb_substr($title, 0, 80)),
            'asset_kind' => $kind,
            'asset_identity' => $identity,
            'delivery' => 'google_drive',
            'delivery_url' => $canonical,
        ];

        try {
            $existing = $this->findExistingAsset($community, $identity, $canonical);
            if ($existing !== null) {
                $existing->name = $title;
                $existing->uri = $uri;
                $existing->content = $content;
                $existing->content_sha256 = hash('sha256', $content);
                $existing->authority_tier = KnowledgeAuthorityTier::VerifiedResource;
                $meta = is_array($existing->metadata) ? $existing->metadata : [];
                $existing->metadata = array_merge($meta, $metadata);
                $existing->lifecycle_status = KnowledgeLifecycleStatus::Draft;
                // Force re-sync so title/URL changes reach the AI index.
                $existing->ai_source_id = null;
                $existing->ai_version_id = null;
                $existing->save();

                $this->lifecycle->submitForReview($user, $existing);
                $published = $this->lifecycle->publish($user, $existing->fresh() ?? $existing);

                Log::info('channel.program_asset.updated', [
                    'knowledge_id' => $published->id,
                    'identity' => $identity,
                    'kind' => $kind,
                ]);

                return [
                    'ok' => true,
                    'identity' => $identity,
                    'reply' => 'Updated. Members can ask for *'.$title."* and I'll send the Drive link.\n\n"
                        .$canonical,
                ];
            }

            $source = $this->lifecycle->import($user, [
                'tenant_id' => $community->tenant_id,
                'community_id' => $community->id,
                'name' => $title,
                'uri' => $uri,
                'source_type' => 'markdown',
                'authority_tier' => KnowledgeAuthorityTier::VerifiedResource,
                'content' => $content,
                'metadata' => $metadata,
            ]);
            $this->lifecycle->submitForReview($user, $source);
            $published = $this->lifecycle->publish($user, $source->fresh() ?? $source);
        } catch (\Throwable $e) {
            Log::error('channel.program_asset.register_failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'reply' => "I couldn't publish that file link right now: ".$e->getMessage(),
            ];
        }

        Log::info('channel.program_asset.published', [
            'knowledge_id' => $published->id,
            'identity' => $identity,
            'kind' => $kind,
        ]);

        return [
            'ok' => true,
            'identity' => $identity,
            'reply' => 'Published. Members can ask for *'.$title."* and I'll send the Drive link.\n\n"
                .$canonical,
        ];
    }

    /**
     * Stable id for dedupe across manual entry, bulk import, and Drive poll.
     */
    public function assetIdentityKey(string $canonicalUrl): string
    {
        $url = $canonicalUrl;
        if (preg_match('#drive\.google\.com/file/d/([^/]+)#i', $url, $m) === 1) {
            return 'gdrive:'.strtolower($m[1]);
        }
        if (preg_match('~drive\.google\.com/drive/(?:u/\d+/)?folders/([^/?#]+)~i', $url, $m) === 1) {
            return 'gfolder:'.strtolower($m[1]);
        }
        if (preg_match('#docs\.google\.com/document/d/([^/]+)#i', $url, $m) === 1) {
            return 'gdoc:'.strtolower($m[1]);
        }
        if (preg_match('#docs\.google\.com/spreadsheets/d/([^/]+)#i', $url, $m) === 1) {
            return 'gsheet:'.strtolower($m[1]);
        }
        if (preg_match('#docs\.google\.com/presentation/d/([^/]+)#i', $url, $m) === 1) {
            return 'gslides:'.strtolower($m[1]);
        }
        if (preg_match('#docs\.google\.com/forms/d/([^/]+)#i', $url, $m) === 1) {
            return 'gform:'.strtolower($m[1]);
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m) === 1) {
            return 'gid:'.strtolower($m[1]);
        }

        return 'url:'.hash('sha256', strtolower($url));
    }

    /**
     * Normalize sharing variants to one openable https URL, or null if not Google.
     */
    public function canonicalizeGoogleUrl(string $url): ?string
    {
        $url = trim($url);
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $allowed = [
            'drive.google.com',
            'docs.google.com',
            'sheets.google.com',
            'slides.google.com',
        ];
        $okHost = false;
        foreach ($allowed as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                $okHost = true;
                break;
            }
        }
        if (! $okHost) {
            return null;
        }

        if (preg_match('#drive\.google\.com/file/d/([^/]+)#i', $url, $m) === 1) {
            return $this->usableGoogleResourceId($m[1])
                ? 'https://drive.google.com/file/d/'.$m[1].'/view'
                : null;
        }
        if (preg_match('~drive\.google\.com/drive/(?:u/\d+/)?folders/([^/?#]+)~i', $url, $m) === 1) {
            return $this->usableGoogleResourceId($m[1])
                ? 'https://drive.google.com/drive/folders/'.$m[1]
                : null;
        }
        if (preg_match('~drive\.google\.com/open\?[^#]*id=([a-zA-Z0-9_-]+)~i', $url, $m) === 1) {
            return $this->usableGoogleResourceId($m[1])
                ? 'https://drive.google.com/file/d/'.$m[1].'/view'
                : null;
        }
        if (preg_match('#docs\.google\.com/document/d/([^/]+)#i', $url, $m) === 1) {
            return $this->usableGoogleResourceId($m[1])
                ? 'https://docs.google.com/document/d/'.$m[1].'/edit'
                : null;
        }
        if (preg_match('#docs\.google\.com/spreadsheets/d/([^/]+)#i', $url, $m) === 1) {
            return $this->usableGoogleResourceId($m[1])
                ? 'https://docs.google.com/spreadsheets/d/'.$m[1].'/edit'
                : null;
        }
        if (preg_match('#docs\.google\.com/presentation/d/([^/]+)#i', $url, $m) === 1) {
            return $this->usableGoogleResourceId($m[1])
                ? 'https://docs.google.com/presentation/d/'.$m[1].'/edit'
                : null;
        }
        if (preg_match('#docs\.google\.com/forms/d/([^/]+)#i', $url, $m) === 1) {
            return $this->usableGoogleResourceId($m[1])
                ? 'https://docs.google.com/forms/d/'.$m[1].'/viewform'
                : null;
        }

        // Strip tracking query junk but keep path.
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';

        return $scheme.'://'.$parts['host'].$parts['path'];
    }

    /**
     * Reject help-text placeholders and truncated pastes (e.g. folders/…).
     * Real Drive/Docs ids are alphanumeric with -/_ ; unit tests may use short fakes (≥5).
     */
    private function usableGoogleResourceId(string $id): bool
    {
        $id = rawurldecode(trim($id));
        if ($id === '' || preg_match('/[…]/u', $id) === 1 || preg_match('/\.{2,}/', $id) === 1) {
            return false;
        }
        if (preg_match('/^(your_[a-z0-9_]+|xxx+|placeholder|<[^>]+>)$/i', $id) === 1) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9_-]{5,}$/', $id) === 1;
    }

    private function findExistingAsset(Community $community, string $identity, string $canonicalUrl): ?KnowledgeSource
    {
        $uri = 'community://'.$community->id.'/asset/'.$identity;

        $byUri = KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('uri', $uri)
            ->first();
        if ($byUri !== null) {
            return $byUri;
        }

        // Legacy rows that stored the raw Drive URL as uri.
        $byLegacyUri = KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where(function ($q) use ($canonicalUrl): void {
                $q->where('uri', $canonicalUrl)
                    ->orWhere('uri', 'like', $canonicalUrl.'%');
            })
            ->first();
        if ($byLegacyUri !== null) {
            return $byLegacyUri;
        }

        return KnowledgeSource::query()
            ->where('community_id', $community->id)
            ->where('metadata->asset_identity', $identity)
            ->first();
    }

    private function usageReply(string $cmd, string $style): string
    {
        if ($style === 'whatsapp') {
            return "Register a program file on Google Drive, like:\n"
                ."```\n"
                ."/asset handbook UniPods Handbook https://drive.google.com/file/d/YOUR_FILE_ID/view\n"
                ."```\n\n"
                ."Or paste many at once:\n"
                ."```\n"
                ."/asset import\n"
                ."handbook UniPods Handbook https://drive.google.com/file/d/YOUR_FILE_ID/view\n"
                ."form Session signup https://docs.google.com/forms/d/YOUR_FORM_ID/viewform\n"
                ."slides Week 1 deck https://docs.google.com/presentation/d/YOUR_SLIDES_ID/edit\n"
                ."other All program resources https://drive.google.com/drive/folders/YOUR_FOLDER_ID\n"
                ."```\n\n"
                ."Replace YOUR_*_ID with the id from Drive → Share → Copy link (full URL, not cut off).\n"
                ."Kinds: handbook, form, slides, other.\n"
                ."Same Drive file is *updated*, never duplicated (manual, import, or future Drive sync).\n"
                ."Drive folder auto-poll is next; configure one shared folder when ready.";
        }

        return "Register a program file: {$cmd} <kind> <title> <drive-url>\n"
            ."Or bulk: {$cmd} import  (then one asset per line).\n"
            .'Kinds: handbook, form, slides, other. Same Drive file id updates instead of duplicating.';
    }
}
