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
        bool $enableConflictDetection = true
    ): GroundedAnswerDTO {
        $payloadArray = [
            'query' => trim($query),
            'tenant_id' => $tenantId,
            'community_ids' => array_values($communityIds),
            'target_language' => $targetLanguage,
            'enable_conflict_detection' => $enableConflictDetection,
            'temperature' => 0.0,
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
                'query' => $query,
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
     * HMAC for multipart signs "{timestamp}." (empty body), matching the AI service.
     *
     * @param  array<string, mixed>  $metadata
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
    ): array {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.", $this->hmacSecret);

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
                (string) file_get_contents($absoluteFilePath),
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
            ]);

        if ($response->failed()) {
            throw new AiServiceException('Multimodal ingestion failed: '.$response->body());
        }

        return $response->json();
    }
}