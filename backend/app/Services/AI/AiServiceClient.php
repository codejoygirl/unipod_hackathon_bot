<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\GroundedAnswerDTO;
use App\Enums\AnswerState;
use App\Exceptions\AiServiceException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiServiceClient
{
    public function __construct(
        protected HttpFactory $http,
        protected string $baseUrl,
        protected string $hmacSecret,
        protected float $timeout = 10.0,
        protected float $connectTimeout = 2.0,
    ) {}

    protected function generateAuthHeaders(string $body): array
    {
        $timestamp = (string) time();
        $message = "{$timestamp}.{$body}";
        $signature = hash_hmac('sha256', $message, $this->hmacSecret);

        return [
            'X-Signature' => $signature,
            'X-Timestamp' => $timestamp,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function askGroundedQuestion(
        string $query,
        string $tenantId,
        array $communityIds,
        ?string $targetLanguage = null,
        bool $enableConflictDetection = true,
        string $linkMode = 'none',
        string $linkFocus = 'na',
    ): GroundedAnswerDTO {
        $allowedLink = ['none', 'recordings', 'meetings', 'assets'];
        if (! in_array($linkMode, $allowedLink, true)) {
            $linkMode = 'none';
        }
        $allowedFocus = ['one', 'many', 'na'];
        if (! in_array($linkFocus, $allowedFocus, true)) {
            $linkFocus = 'na';
        }

        $payloadArray = [
            'query' => trim($query),
            'tenant_id' => $tenantId,
            'community_ids' => array_values($communityIds),
            'target_language' => $targetLanguage,
            'enable_conflict_detection' => $enableConflictDetection,
            'temperature' => 0.0,
            'link_mode' => $linkMode,
            'link_focus' => $linkFocus,
            'timezone' => (string) config('app.timezone', 'UTC'),
            'reference_time' => now()->toIso8601String(),
        ];

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/retrieval/grounded-answer");

            if ($response->failed()) {
                Log::error('AI Service /retrieval/grounded-answer failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'tenant_id' => $tenantId,
                ]);

                throw new AiServiceException(
                    "AI Service error [HTTP {$response->status()}]: {$response->body()}"
                );
            }

            return GroundedAnswerDTO::fromApiResponse($response->json());

        } catch (Throwable $e) {
            Log::critical('AI Service communication failure', [
                'exception' => $e->getMessage(),
                'query_len' => mb_strlen($query),
                'tenant_id' => $tenantId,
            ]);

            return new GroundedAnswerDTO(
                query: $query,
                detectedLanguage: $targetLanguage ?? 'en',
                state: AnswerState::INSUFFICIENT_EVIDENCE,
                answer: '',
                confidenceScore: 0.0,
                citations: [],
                conflicts: [],
                needsEscalation: true,
                escalationReason: str_contains($e->getMessage(), 'timed out')
                    ? 'AI service timed out. Escalated to human operator.'
                    : 'AI service unreachable or failed to respond. Escalated to human operator.',
                executionTimeMs: 0.0,
                totalChunksRetrieved: 0,
            );
        }
    }

    /**
     * Warm social / out-of-scope / personal-help / take-private reply (no retrieval).
     * Empty string on hard failure.
     */
    public function conversationalReply(
        string $message,
        string $mode = 'social',
        ?string $communityName = null,
        ?string $communityScope = null,
        ?string $targetLanguage = null,
    ): string {
        $allowedModes = ['social', 'out_of_scope', 'take_private', 'personal_help'];
        if (! in_array($mode, $allowedModes, true)) {
            $mode = 'social';
        }

        $payloadArray = [
            'message' => trim($message),
            'mode' => $mode,
            'community_name' => $communityName,
            'community_scope' => $communityScope,
        ];
        $lang = is_string($targetLanguage) ? trim($targetLanguage) : '';
        if ($lang !== '' && strtolower($lang) !== 'auto') {
            $payloadArray['target_language'] = $lang;
        }

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(min($this->timeout, 8.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/reply");

            if ($response->failed()) {
                Log::warning('AI Service /conversation/reply failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return '';
            }

            $reply = trim((string) ($response->json('reply') ?? ''));

            return $reply;
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/reply unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Classify an ambiguous chat turn. Null on hard failure (caller keeps heuristics).
     *
     * @return array{intent: 'conversational'|'knowledge'|'out_of_scope'|'clarify'|'personal_help', link_mode: 'none'|'recordings'|'meetings'|'assets', follow_up: bool, link_focus: 'one'|'many'|'na'}|null
     */
    public function classifyConversationIntent(
        string $message,
        ?string $communityName = null,
        ?string $communityScope = null,
        ?string $priorQuestion = null,
        ?string $priorAnswerExcerpt = null,
        bool $replyToBot = false,
    ): ?array {
        $payloadArray = [
            'message' => trim($message),
            'community_name' => $communityName,
            'community_scope' => $communityScope,
            'prior_question' => $priorQuestion !== null && trim($priorQuestion) !== ''
                ? mb_substr(trim($priorQuestion), 0, 1000)
                : null,
            'prior_answer_excerpt' => $priorAnswerExcerpt !== null && trim($priorAnswerExcerpt) !== ''
                ? mb_substr(trim($priorAnswerExcerpt), 0, 800)
                : null,
            'reply_to_bot' => $replyToBot,
        ];

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(min($this->timeout, 6.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/classify");

            if ($response->failed()) {
                Log::warning('AI Service /conversation/classify failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $intent = strtolower(trim((string) ($response->json('intent') ?? '')));
            $linkMode = strtolower(trim((string) ($response->json('link_mode') ?? 'none')));
            $linkFocus = strtolower(trim((string) ($response->json('link_focus') ?? 'na')));
            $followUp = filter_var($response->json('follow_up') ?? false, FILTER_VALIDATE_BOOLEAN);
            $allowedIntent = ['conversational', 'knowledge', 'out_of_scope', 'clarify', 'personal_help'];
            $allowedLink = ['none', 'recordings', 'meetings', 'assets'];
            $allowedFocus = ['one', 'many', 'na'];

            if (! in_array($intent, $allowedIntent, true)) {
                return null;
            }

            if (! in_array($linkMode, $allowedLink, true)) {
                $linkMode = 'none';
            }

            if (! in_array($linkFocus, $allowedFocus, true)) {
                $linkFocus = 'na';
            }

            if ($followUp) {
                $intent = 'knowledge';
                $linkMode = 'none';
                $linkFocus = 'na';
            }

            if ($intent !== 'knowledge') {
                $linkMode = 'none';
                $followUp = false;
                $linkFocus = 'na';
            }

            if ($linkMode === 'none') {
                $linkFocus = 'na';
            } elseif ($linkFocus === 'na') {
                $linkFocus = 'many';
            }

            return [
                'intent' => $intent,
                'link_mode' => $linkMode,
                'follow_up' => $followUp,
                'link_focus' => $linkFocus,
            ];
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/classify unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether an admin message should be published into community knowledge.
     * Null on hard failure (caller skips indexing).
     */
    public function isAdminMessageIndexable(
        string $message,
        ?string $communityName = null,
        ?string $communityScope = null,
    ): ?bool {
        $payloadArray = [
            'message' => trim($message),
            'community_name' => $communityName,
            'community_scope' => $communityScope,
        ];

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(min($this->timeout, 5.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/indexable");

            if ($response->failed()) {
                Log::warning('AI Service /conversation/indexable failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $raw = $response->json('indexable');
            if (is_bool($raw)) {
                return $raw;
            }
            if (is_string($raw)) {
                $v = strtolower(trim($raw));
                if (in_array($v, ['true', 'yes', '1'], true)) {
                    return true;
                }
                if (in_array($v, ['false', 'no', '0'], true)) {
                    return false;
                }
            }

            return null;
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/indexable unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether a group mention/reply is actually directed at Zak.
     * Null on hard failure (caller applies a conservative default).
     */
    public function isMessageAddressedToBot(
        string $message,
        bool $replyToBot = false,
        bool $botMentioned = false,
        ?string $quotedExcerpt = null,
    ): ?bool {
        $payloadArray = [
            'message' => trim($message),
            'reply_to_bot' => $replyToBot,
            'bot_mentioned' => $botMentioned,
            'quoted_excerpt' => $quotedExcerpt !== null ? mb_substr(trim($quotedExcerpt), 0, 400) : null,
        ];

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(min($this->timeout, 5.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/addressed");

            if ($response->failed()) {
                Log::warning('AI Service /conversation/addressed failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $raw = $response->json('addressed');
            if (is_bool($raw)) {
                return $raw;
            }
            if (is_string($raw)) {
                $v = strtolower(trim($raw));
                if (in_array($v, ['true', 'yes', '1'], true)) {
                    return true;
                }
                if (in_array($v, ['false', 'no', '0'], true)) {
                    return false;
                }
            }

            return null;
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/addressed unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Transcribe a channel voice note. Empty text on hard failure.
     *
     * @return array{text: string, language: string|null, duration_seconds: float|null}
     */
    public function transcribeVoiceNote(
        string $audioBase64,
        ?string $mimeType = null,
        ?string $filename = null,
        ?string $languageHint = null,
    ): array {
        $payloadArray = [
            'audio_base64' => $audioBase64,
            'mime_type' => $mimeType,
            'filename' => $filename,
            'language' => $languageHint,
        ];

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(max($this->timeout, 45.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/transcribe");

            if ($response->failed()) {
                Log::warning('AI Service /conversation/transcribe failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['text' => '', 'language' => null, 'duration_seconds' => null];
            }

            $text = trim((string) ($response->json('text') ?? ''));
            $language = $response->json('language');
            $language = is_string($language) ? strtolower(trim($language)) : null;
            if ($language === '' || $language === 'unknown') {
                $language = null;
            }
            $duration = $response->json('duration_seconds');
            $duration = is_numeric($duration) ? (float) $duration : null;

            return [
                'text' => $text,
                'language' => $language,
                'duration_seconds' => $duration,
            ];
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/transcribe unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return ['text' => '', 'language' => null, 'duration_seconds' => null];
        }
    }

    /**
     * Soft byte budget per /ingestion/sync call. Large WhatsApp exports / API
     * bodies time out when sent as one payload; callers always go through here.
     */
    public const INGEST_CHUNK_MAX_BYTES = 28000;

    public function syncDocument(
        string $tenantId,
        string $communityId,
        string $uri,
        string $name,
        string $sourceType,
        string $content,
        string $authorityTier = 'community_discussion',
        array $metadata = []
    ): array {
        $parts = self::chunkTextForIngest($content, self::INGEST_CHUNK_MAX_BYTES);
        if ($parts === []) {
            $parts = [''];
        }

        if (count($parts) === 1) {
            return $this->postSyncDocument(
                $tenantId,
                $communityId,
                $uri,
                $name,
                $sourceType,
                $parts[0],
                $authorityTier,
                $metadata,
            );
        }

        $partResponses = [];
        $total = count($parts);
        foreach ($parts as $i => $part) {
            $n = $i + 1;
            $partResponses[] = $this->postSyncDocument(
                $tenantId,
                $communityId,
                rtrim($uri, '/').'/part-'.$n,
                $name.' (part '.$n.'/'.$total.')',
                $sourceType,
                $part,
                $authorityTier,
                array_merge($metadata, [
                    'ingest_batch_uri' => $uri,
                    'ingest_part' => $n,
                    'ingest_parts' => $total,
                ]),
            );
        }

        $first = $partResponses[0] ?? [];
        $first['ingest_parts'] = array_values(array_filter(array_map(
            static fn (array $r): ?string => isset($r['source_id'])
                ? (string) $r['source_id']
                : (isset($r['data']['source_id']) ? (string) $r['data']['source_id'] : null),
            $partResponses,
        )));
        $first['ingest_part_count'] = $total;

        Log::info('ai_service.sync_document_chunked', [
            'uri' => $uri,
            'parts' => $total,
            'bytes' => strlen($content),
        ]);

        return $first;
    }

    /**
     * Split large text on line boundaries for ingest (shared by API + channel export).
     *
     * @return list<string>
     */
    public static function chunkTextForIngest(string $content, int $maxBytes = self::INGEST_CHUNK_MAX_BYTES): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if ($content === '') {
            return [];
        }
        if ($maxBytes < 1024) {
            $maxBytes = 1024;
        }
        if (strlen($content) <= $maxBytes) {
            return [$content];
        }

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [$content];
        $chunks = [];
        $buf = '';
        foreach ($lines as $line) {
            $candidate = $buf === '' ? $line : $buf."\n".$line;
            if (strlen($candidate) > $maxBytes && $buf !== '') {
                $chunks[] = $buf;
                $buf = $line;
                // Oversized single line: hard-split.
                while (strlen($buf) > $maxBytes) {
                    $chunks[] = substr($buf, 0, $maxBytes);
                    $buf = substr($buf, $maxBytes);
                }
            } else {
                $buf = $candidate;
            }
        }
        if ($buf !== '') {
            $chunks[] = $buf;
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function postSyncDocument(
        string $tenantId,
        string $communityId,
        string $uri,
        string $name,
        string $sourceType,
        string $content,
        string $authorityTier,
        array $metadata,
    ): array {
        $payloadArray = [
            'tenant_id' => $tenantId,
            'community_id' => $communityId,
            'uri' => $uri,
            'name' => $name,
            'source_type' => $sourceType,
            'content' => $content,
            'authority_tier' => $authorityTier,
            'metadata' => $metadata,
            'index_status' => 'active',
        ];

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        $response = $this->http
            ->timeout(max($this->timeout, 300.0))
            ->connectTimeout($this->connectTimeout)
            ->withHeaders($headers)
            ->withBody($rawBody, 'application/json')
            ->post("{$this->baseUrl}/ingestion/sync");

        if ($response->failed()) {
            throw new AiServiceException('Ingestion failed: '.$response->body());
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    /**
     * Multipart ingest (image/audio/video/text file) via AI /ingestion/multimodal.
     * HMAC signs a canonical form + content digest (not an empty body).
     *
     * @return array<string, mixed>
     */
    public function ingestMultimodal(
        string $tenantId,
        string $communityId,
        string $uri,
        string $name,
        string $sourceType,
        string $absoluteFilePath,
        string $originalFilename,
        string $authorityTier = 'community_discussion',
        ?string $mimeType = null,
        string $indexStatus = 'pending',
    ): array {
        $fileBytes = (string) file_get_contents($absoluteFilePath);
        $contentSha256 = hash('sha256', $fileBytes);
        $canonical = $this->multipartCanonicalPayload(
            tenantId: $tenantId,
            communityId: $communityId,
            uri: $uri,
            name: $name,
            sourceType: $sourceType,
            authorityTier: $authorityTier,
            contentSha256: $contentSha256,
            indexStatus: $indexStatus,
        );
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$canonical}", $this->hmacSecret);

        $response = $this->http
            ->timeout(120.0)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders([
                'X-Signature' => $signature,
                'X-Timestamp' => $timestamp,
                'Accept' => 'application/json',
            ])
            ->attach(
                'file',
                $fileBytes,
                $originalFilename,
                $mimeType ? ['Content-Type' => $mimeType] : [],
            )
            ->post("{$this->baseUrl}/ingestion/multimodal", [
                'tenant_id' => $tenantId,
                'community_id' => $communityId,
                'uri' => $uri,
                'name' => $name,
                'source_type' => $sourceType,
                'authority_tier' => $authorityTier,
                'index_status' => $indexStatus,
            ]);

        if ($response->failed()) {
            throw new AiServiceException('Multimodal ingestion failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Mark a previously indexed AI source searchable after Laravel publish.
     *
     * @return array<string, mixed>
     */
    public function activateSource(string $sourceId): array
    {
        $payloadArray = new \stdClass;
        $rawBody = json_encode($payloadArray, JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        $response = $this->http
            ->timeout(30.0)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders($headers)
            ->withBody($rawBody, 'application/json')
            ->post("{$this->baseUrl}/ingestion/activate/{$sourceId}");

        if ($response->failed()) {
            throw new AiServiceException('Activate source failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Wipe AI knowledge sources, versions, chunks (embeddings), and optionally glossary.
     *
     * @return array{sources_deleted: int, versions_deleted: int, chunks_deleted: int, glossary_deleted: int, scope: string, message: string}
     */
    public function purgeKnowledge(
        ?string $communityId = null,
        ?string $tenantId = null,
        bool $all = false,
        bool $includeGlossary = true,
    ): array {
        $payloadArray = [
            'community_id' => $communityId,
            'tenant_id' => $tenantId,
            'all' => $all,
            'include_glossary' => $includeGlossary,
        ];
        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        $response = $this->http
            ->timeout(max(60.0, $this->timeout))
            ->connectTimeout($this->connectTimeout)
            ->withHeaders($headers)
            ->withBody($rawBody, 'application/json')
            ->post("{$this->baseUrl}/ingestion/purge");

        if ($response->failed()) {
            throw new AiServiceException('Purge knowledge failed: '.$response->body());
        }

        /** @var array{sources_deleted: int, versions_deleted: int, chunks_deleted: int, glossary_deleted: int, scope: string, message: string} */
        return $response->json();
    }

    protected function multipartCanonicalPayload(
        string $tenantId,
        string $communityId,
        string $uri,
        string $name,
        string $sourceType,
        string $authorityTier,
        string $contentSha256,
        string $indexStatus = 'pending',
    ): string {
        return implode("\n", [
            'v1',
            "tenant_id={$tenantId}",
            "community_id={$communityId}",
            "uri={$uri}",
            "name={$name}",
            "source_type={$sourceType}",
            "authority_tier={$authorityTier}",
            "index_status={$indexStatus}",
            "content_sha256={$contentSha256}",
        ]);
    }
}