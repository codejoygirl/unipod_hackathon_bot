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
    ): GroundedAnswerDTO {
        $allowedLink = ['none', 'recordings', 'meetings', 'assets'];
        if (! in_array($linkMode, $allowedLink, true)) {
            $linkMode = 'none';
        }

        $payloadArray = [
            'query' => trim($query),
            'tenant_id' => $tenantId,
            'community_ids' => array_values($communityIds),
            'target_language' => $targetLanguage,
            'enable_conflict_detection' => $enableConflictDetection,
            'temperature' => 0.0,
            'link_mode' => $linkMode,
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
     * Warm social or out-of-scope reply (no retrieval). Empty string on hard failure.
     */
    public function conversationalReply(
        string $message,
        string $mode = 'social',
        ?string $communityName = null,
        ?string $communityScope = null,
    ): string {
        $payloadArray = [
            'message' => trim($message),
            'mode' => $mode === 'out_of_scope' ? 'out_of_scope' : 'social',
            'community_name' => $communityName,
            'community_scope' => $communityScope,
        ];

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
     * @return array{intent: 'conversational'|'knowledge'|'out_of_scope'|'clarify', link_mode: 'none'|'recordings'|'meetings'|'assets'}|null
     */
    public function classifyConversationIntent(
        string $message,
        ?string $communityName = null,
        ?string $communityScope = null,
    ): ?array {
        $payloadArray = [
            'message' => trim($message),
            'community_name' => $communityName,
            'community_scope' => $communityScope,
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
            $allowedIntent = ['conversational', 'knowledge', 'out_of_scope', 'clarify'];
            $allowedLink = ['none', 'recordings', 'meetings', 'assets'];

            if (! in_array($intent, $allowedIntent, true)) {
                return null;
            }

            if (! in_array($linkMode, $allowedLink, true)) {
                $linkMode = 'none';
            }

            if ($intent !== 'knowledge') {
                $linkMode = 'none';
            }

            return [
                'intent' => $intent,
                'link_mode' => $linkMode,
            ];
        } catch (Throwable $e) {
            Log::warning('AI Service /conversation/classify unreachable', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

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
            ->timeout(60.0)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders($headers)
            ->withBody($rawBody, 'application/json')
            ->post("{$this->baseUrl}/ingestion/sync");

        if ($response->failed()) {
            throw new AiServiceException("Ingestion failed: " . $response->body());
        }

        return $response->json();
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