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
        ?string $timezone = null,
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
            'timezone' => $timezone ?: (string) config('app.timezone', 'UTC'),
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
        ?string $timezone = null,
    ): string {
        $allowedModes = ['social', 'out_of_scope', 'take_private', 'personal_help', 'escalated'];
        if (! in_array($mode, $allowedModes, true)) {
            $mode = 'social';
        }

        $payloadArray = [
            'message' => trim($message),
            'mode' => $mode,
            'community_name' => $communityName,
            'community_scope' => $communityScope,
        ];
        $tz = is_string($timezone) ? trim($timezone) : '';
        if ($tz === '') {
            $tz = (string) config('app.timezone', 'UTC');
        }
        $payloadArray['timezone'] = $tz;
        $payloadArray['reference_time'] = now()->toIso8601String();
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
     * Full assistant reply, with optional member files as extra context.
     * Empty string on hard failure.
     *
     * @param  list<array{filename: string, text: string, selected?: bool}>  $files
     * @param  list<array{role?: string, text?: string}>  $thread
     * @param  list<array{filename: string, selected?: bool, readable?: bool}>  $library
     */
    public function documentReply(
        string $question,
        array $files,
        ?string $task = null,
        ?string $priorQuestion = null,
        ?string $priorAnswerExcerpt = null,
        array $thread = [],
        bool $lastTurnWasQuestion = false,
        array $library = [],
    ): string {
        return $this->documentReplyResult(
            $question,
            $files,
            $task,
            $priorQuestion,
            $priorAnswerExcerpt,
            $thread,
            $lastTurnWasQuestion,
            $library,
        )['reply'];
    }

    /**
     * @param  list<array{filename: string, text: string, selected?: bool}>  $files
     * @param  list<array{role?: string, text?: string}>  $thread
     * @param  list<array{filename: string, selected?: bool, readable?: bool}>  $library
     * @return array{reply: string, export: string}
     */
    public function documentReplyResult(
        string $question,
        array $files,
        ?string $task = null,
        ?string $priorQuestion = null,
        ?string $priorAnswerExcerpt = null,
        array $thread = [],
        bool $lastTurnWasQuestion = false,
        array $library = [],
    ): array {
        $payloadFiles = [];
        foreach ($files as $file) {
            $name = trim((string) ($file['filename'] ?? ''));
            $text = $this->sanitizeDocumentText((string) ($file['text'] ?? ''));
            if ($name === '' || $text === '') {
                continue;
            }
            $payloadFiles[] = [
                'filename' => mb_substr($name, 0, 200),
                'text' => mb_substr($text, 0, 8000),
                'selected' => (bool) ($file['selected'] ?? false),
            ];
        }

        $payloadLibrary = [];
        foreach ($library as $item) {
            $name = trim((string) ($item['filename'] ?? ''));
            if ($name === '') {
                continue;
            }
            $payloadLibrary[] = [
                'filename' => mb_substr($name, 0, 200),
                'selected' => (bool) ($item['selected'] ?? false),
                'readable' => (bool) ($item['readable'] ?? true),
            ];
        }

        $payloadArray = [
            'question' => trim($question),
            'files' => $payloadFiles,
            'timezone' => (string) config('app.timezone', 'UTC'),
            'reference_time' => now()->toIso8601String(),
        ];
        if ($payloadLibrary !== []) {
            $payloadArray['library'] = $payloadLibrary;
        }
        $kind = is_string($task) ? strtolower(trim($task)) : '';
        if ($kind !== '') {
            $payloadArray['task'] = $kind;
        }
        $priorQ = trim((string) $priorQuestion);
        if ($priorQ !== '') {
            $payloadArray['prior_question'] = mb_substr($priorQ, 0, 1000);
        }
        $priorA = trim((string) $priorAnswerExcerpt);
        if ($priorA !== '') {
            $payloadArray['prior_answer_excerpt'] = mb_substr($priorA, 0, 12000);
        }
        $turns = [];
        foreach ($thread as $turn) {
            $role = strtolower(trim((string) ($turn['role'] ?? '')));
            $text = trim((string) ($turn['text'] ?? ''));
            if (! in_array($role, ['user', 'assistant'], true) || $text === '') {
                continue;
            }
            $turns[] = [
                'role' => $role,
                'text' => mb_substr($text, 0, 10000),
            ];
            if (count($turns) >= 8) {
                break;
            }
        }
        if ($turns !== []) {
            $payloadArray['thread'] = $turns;
        }
        if ($lastTurnWasQuestion) {
            $payloadArray['last_turn_was_question'] = true;
        }

        try {
            $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/document-reply payload invalid', [
                'exception' => $e->getMessage(),
            ]);

            return ['reply' => '', 'export' => 'none'];
        }
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(max($this->timeout, 90.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/document-reply");

            if ($response->failed()) {
                Log::warning('AI Service /conversation/document-reply failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['reply' => '', 'export' => 'none'];
            }

            $export = strtolower(trim((string) ($response->json('export') ?? 'none')));
            if (! in_array($export, ['pdf', 'markdown'], true)) {
                $export = 'none';
            }

            return [
                'reply' => trim((string) ($response->json('reply') ?? '')),
                'export' => $export,
            ];
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/document-reply unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return ['reply' => '', 'export' => 'none'];
        }
    }

    /**
     * Classify an ambiguous chat turn. Null on hard failure (caller keeps heuristics).
     *
     * @return array{intent: 'conversational'|'knowledge'|'out_of_scope'|'clarify'|'personal_help', link_mode: 'none'|'recordings'|'meetings'|'assets', follow_up: bool, link_focus: 'one'|'many'|'na', needs_temporal_resolution: bool}|null
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
            $needsTemporal = filter_var(
                $response->json('needs_temporal_resolution') ?? false,
                FILTER_VALIDATE_BOOLEAN,
            );
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

            if ($intent === 'clarify') {
                $followUp = false;
                $linkMode = 'none';
                $linkFocus = 'na';
                $needsTemporal = false;
            } elseif ($followUp) {
                $intent = 'knowledge';
                $linkMode = 'none';
                $linkFocus = 'na';
            }

            if ($intent !== 'knowledge') {
                $linkMode = 'none';
                $followUp = false;
                $linkFocus = 'na';
                $needsTemporal = false;
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
                'needs_temporal_resolution' => $needsTemporal,
            ];
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/classify unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Optional preflight when classify marks a calendar-relative knowledge ask.
     *
     * @return array{needs_resolution: bool, temporal_context: string}|null
     */
    public function conversationTemporalPlan(
        string $message,
        ?string $priorQuestion = null,
        ?string $timezone = null,
        ?string $referenceTimeIso = null,
    ): ?array {
        $payloadArray = [
            'message' => trim($message),
            'prior_question' => $priorQuestion !== null && trim($priorQuestion) !== ''
                ? mb_substr(trim($priorQuestion), 0, 1000)
                : null,
            'timezone_name' => $timezone ?: (string) config('app.timezone', 'UTC'),
            'reference_time_iso' => $referenceTimeIso ?: now()->toIso8601String(),
        ];

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(min($this->timeout, 8.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/temporal-plan");

            if ($response->failed()) {
                Log::warning('AI Service /conversation/temporal-plan failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            return [
                'needs_resolution' => filter_var($response->json('needs_resolution') ?? false, FILTER_VALIDATE_BOOLEAN),
                'temporal_context' => trim((string) ($response->json('temporal_context') ?? '')),
            ];
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/temporal-plan unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  list<array{short_id: string, name: string, excerpt: string, published_at: string|null}>  $publishedCatalog
     * @return list<array{short_id: string, reason: string}>|null
     */
    public function suggestKnowledgeReplaceCandidates(
        ?string $communityName,
        string $newImportName,
        string $newImportExcerpt,
        array $publishedCatalog,
    ): ?array {
        if ($publishedCatalog === []) {
            return [];
        }

        $payloadArray = [
            'community_name' => $communityName,
            'new_import_name' => trim($newImportName),
            'new_import_excerpt' => mb_substr(trim($newImportExcerpt), 0, 2000),
            'published_sources' => array_slice($publishedCatalog, 0, 25),
        ];

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(min($this->timeout, 12.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/knowledge-replace-suggest");

            if ($response->failed()) {
                Log::warning('AI Service /conversation/knowledge-replace-suggest failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $rows = $response->json('suggestions');
            if (! is_array($rows)) {
                return null;
            }

            $out = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sid = strtoupper(trim((string) ($row['short_id'] ?? '')));
                if ($sid === '') {
                    continue;
                }
                $out[] = [
                    'short_id' => $sid,
                    'reason' => trim((string) ($row['reason'] ?? '')),
                ];
            }

            return $out;
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/knowledge-replace-suggest unreachable', [
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
     * @param  list<array{image_base64: string, mime_type?: string|null, filename?: string|null}>  $images
     * @return array{text: string, http_status: int|null, unreachable: bool}
     */
    public function understandImages(array $images, ?string $caption = null): array
    {
        if ($images === []) {
            return ['text' => '', 'http_status' => null, 'unreachable' => false];
        }

        if (count($images) === 1) {
            $one = $images[0];

            return $this->understandImage(
                imageBase64: (string) ($one['image_base64'] ?? ''),
                mimeType: $one['mime_type'] ?? null,
                filename: $one['filename'] ?? null,
                caption: $caption,
            );
        }

        $payloadArray = [
            'images' => array_values(array_map(static function (array $row): array {
                return [
                    'image_base64' => (string) ($row['image_base64'] ?? ''),
                    'mime_type' => $row['mime_type'] ?? null,
                    'filename' => $row['filename'] ?? null,
                ];
            }, $images)),
            'caption' => $caption,
        ];

        return $this->postUnderstandImagePayload($payloadArray);
    }

    public function understandImage(
        string $imageBase64,
        ?string $mimeType = null,
        ?string $filename = null,
        ?string $caption = null,
    ): array {
        $payloadArray = [
            'image_base64' => $imageBase64,
            'mime_type' => $mimeType,
            'filename' => $filename,
            'caption' => $caption,
        ];

        return $this->postUnderstandImagePayload($payloadArray);
    }

    /**
     * @param  array<string, mixed>  $payloadArray
     * @return array{text: string, http_status: int|null, unreachable: bool}
     */
    private function postUnderstandImagePayload(array $payloadArray): array
    {

        $rawBody = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        try {
            $response = $this->http
                ->timeout(max($this->timeout, 45.0))
                ->connectTimeout($this->connectTimeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post("{$this->baseUrl}/conversation/understand-image");

            if ($response->failed()) {
                $bodySnippet = mb_substr($response->body(), 0, 500);
                Log::warning('AI Service /conversation/understand-image failed', [
                    'status' => $response->status(),
                    'body_snippet' => $bodySnippet,
                    'base_url' => $this->baseUrl,
                ]);

                return [
                    'text' => '',
                    'http_status' => $response->status(),
                    'unreachable' => false,
                ];
            }

            return [
                'text' => trim((string) ($response->json('text') ?? '')),
                'http_status' => $response->status(),
                'unreachable' => false,
            ];
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/understand-image unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return [
                'text' => '',
                'http_status' => null,
                'unreachable' => true,
            ];
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
     * Mark an indexed source unsearchable in the AI service (active -> archived).
     *
     * @return array<string, mixed>
     */
    public function deactivateSource(string $sourceId): array
    {
        $payloadArray = new \stdClass;
        $rawBody = json_encode($payloadArray, JSON_THROW_ON_ERROR);
        $headers = $this->generateAuthHeaders($rawBody);

        $response = $this->http
            ->timeout(30.0)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders($headers)
            ->withBody($rawBody, 'application/json')
            ->post("{$this->baseUrl}/ingestion/deactivate/{$sourceId}");

        if ($response->failed()) {
            throw new AiServiceException('Deactivate source failed: '.$response->body());
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

    private function sanitizeDocumentText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;

        return trim($text);
    }
}