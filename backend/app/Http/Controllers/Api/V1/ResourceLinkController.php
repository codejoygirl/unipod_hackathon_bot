<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\KnowledgeLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The links buried in a community's published knowledge.
 *
 * Citations cannot supply these: every `source_uri` uses an internal scheme
 * (`whatsapp://…`, `doc://…`, `community://…`) that no browser can open. Only real
 * `http(s)` URLs found *inside* the content are clickable, so they are extracted here.
 *
 * A chat export is mostly noise — member profiles and one-off pitches outnumber actual
 * resources — so links are de-duplicated by path and bucketed by what they are.
 */
class ResourceLinkController extends Controller
{
    /** Cap the scan so one huge export cannot make this endpoint expensive. */
    private const MAX_SOURCES = 50;

    private const MAX_LINKS = 200;

    /** Group invites are not resources, and a chat export is full of them. */
    private const EXCLUDED_HOSTS = ['chat.whatsapp.com', 'wa.me', 't.me'];

    /** Host suffixes that map to a bucket. Matched on suffix, so subdomains qualify. */
    private const CATEGORY_HOSTS = [
        'recordings' => ['youtu.be', 'youtube.com', 'vimeo.com', 'stream.microsoft.com'],
        'meetings' => ['teams.microsoft.com', 'teams.live.com', 'zoom.us', 'meet.google.com'],
        'documents' => ['drive.google.com', 'docs.google.com', 'sharepoint.com', 'dropbox.com'],
        'code' => ['github.com', 'gitlab.com'],
    ];

    public function index(Request $request): JsonResponse
    {
        $communityIds = $request->user()->accessibleCommunityIds();

        $sources = KnowledgeSource::query()
            ->where('lifecycle_status', KnowledgeLifecycleStatus::Published)
            ->whereIn('community_id', $communityIds)
            ->orderByDesc('published_at')
            ->limit(self::MAX_SOURCES)
            ->get(['id', 'name', 'content']);

        $links = [];

        foreach ($sources as $source) {
            preg_match_all('#https?://[^\s<>"\'()\]]+#i', (string) $source->content, $matches);

            foreach ($matches[0] as $url) {
                // Trailing sentence punctuation belongs to the sentence, not the URL.
                $url = rtrim($url, '.,;:!?*_');

                $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

                if ($host === '' || in_array($host, self::EXCLUDED_HOSTS, true)) {
                    continue;
                }

                // Same document shared with and without a tracking query is one resource.
                $canonical = preg_replace('/[?#].*$/', '', $url) ?? $url;

                $links[$canonical] ??= [
                    'url' => $url,
                    'domain' => preg_replace('/^www\./', '', $host),
                    'category' => $this->categoryFor($host),
                    'source_name' => (string) $source->name,
                ];
            }
        }

        $deduped = array_values($links);

        // Bucketed resources first, then the long tail of member links.
        $order = ['recordings' => 0, 'meetings' => 1, 'documents' => 2, 'code' => 3, 'other' => 4];

        usort(
            $deduped,
            static fn (array $a, array $b): int => ($order[$a['category']] <=> $order[$b['category']])
                ?: strcmp($a['domain'], $b['domain'])
                ?: strcmp($a['url'], $b['url']),
        );

        return response()->json([
            'data' => array_slice($deduped, 0, self::MAX_LINKS),
            'meta' => [
                'total' => count($deduped),
                'sources_scanned' => $sources->count(),
            ],
        ]);
    }

    private function categoryFor(string $host): string
    {
        $bare = preg_replace('/^www\./', '', $host) ?? $host;

        foreach (self::CATEGORY_HOSTS as $category => $suffixes) {
            foreach ($suffixes as $suffix) {
                if ($bare === $suffix || str_ends_with($bare, '.'.$suffix)) {
                    return $category;
                }
            }
        }

        return 'other';
    }
}
