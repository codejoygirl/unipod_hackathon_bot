<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\GroundedAnswerDTO;
use App\Enums\AnswerState;
use App\Services\AI\AiServiceClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;

class AiServiceClientTest extends TestCase
{
    public function test_hmac_header_signature_matches_python_contract(): void
    {
        $secret = 'test_secret_key_12345';
        $http = new HttpFactory();
        $client = new class($http, 'http://127.0.0.1:8000', $secret) extends AiServiceClient {
            public function exposeHeaders(string $body): array
            {
                return $this->generateAuthHeaders($body);
            }
        };

        $body = '{"query":"water status"}';
        $headers = $client->exposeHeaders($body);

        $this->assertArrayHasKey('X-Signature', $headers);
        $this->assertArrayHasKey('X-Timestamp', $headers);

        $expected = hash_hmac('sha256', "{$headers['X-Timestamp']}.{$body}", $secret);
        $this->assertSame($expected, $headers['X-Signature']);
    }

    public function test_multipart_canonical_hmac_binds_form_fields_and_content_hash(): void
    {
        $secret = 'test_secret_key_12345';
        $http = new HttpFactory();
        $client = new class($http, 'http://127.0.0.1:8000', $secret) extends AiServiceClient {
            public function exposeCanonical(
                string $tenantId,
                string $communityId,
                string $uri,
                string $name,
                string $sourceType,
                string $authorityTier,
                string $contentSha256,
                string $indexStatus = 'pending',
            ): string {
                return $this->multipartCanonicalPayload(
                    $tenantId,
                    $communityId,
                    $uri,
                    $name,
                    $sourceType,
                    $authorityTier,
                    $contentSha256,
                    $indexStatus,
                );
            }
        };

        $canonical = $client->exposeCanonical(
            'tenant-1',
            'community-1',
            'doc://flyer',
            'flyer.png',
            'image',
            'community_discussion',
            'abc123',
            'pending',
        );

        $this->assertSame(
            "v1\ntenant_id=tenant-1\ncommunity_id=community-1\nuri=doc://flyer\nname=flyer.png\nsource_type=image\nauthority_tier=community_discussion\nindex_status=pending\ncontent_sha256=abc123",
            $canonical,
        );

        $timestamp = '1700000000';
        $expected = hash_hmac('sha256', "{$timestamp}.{$canonical}", $secret);
        $this->assertSame(64, strlen($expected));
    }

    public function test_maps_verified_api_response_to_dto(): void
    {
        $rawResponse = [
            'query' => 'Where is the clinic?',
            'detected_language' => 'en',
            'execution_time_ms' => 45.2,
            'total_chunks_retrieved' => 4,
            'validated_payload' => [
                'state' => 'VERIFIED',
                'answer' => 'The clinic is on 4th street [E1].',
                'confidence_score' => 0.94,
                'needs_escalation' => false,
                'escalation_reason' => null,
                'citations' => [
                    [
                        'evidence_id' => 'E1',
                        'chunk_id' => '72b079bc-25c2-4a0b-800f-8ee57de015c9',
                        'source_name' => 'Health Directory.pdf',
                        'source_uri' => 'https://city.gov/health.pdf',
                        'authority_tier' => 'official_announcement',
                        'exact_quote' => 'The clinic is on 4th street',
                        'context_snippet' => 'The clinic is on 4th street next to the station.',
                        'page_number' => 2,
                        'timestamp_seconds' => null,
                        'is_verified' => true,
                    ]
                ],
                'conflicts' => [],
            ],
        ];

        $dto = GroundedAnswerDTO::fromApiResponse($rawResponse);

        $this->assertSame(AnswerState::VERIFIED, $dto->state);
        $this->assertSame('The clinic is on 4th street [E1].', $dto->answer);
        $this->assertSame(0.94, $dto->confidenceScore);
        $this->assertFalse($dto->needsEscalation);
        $this->assertCount(1, $dto->citations);
        $this->assertSame('E1', $dto->citations[0]->evidenceId);
        $this->assertSame(2, $dto->citations[0]->pageNumber);
    }

    public function test_chunk_text_for_ingest_splits_on_line_boundaries(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = str_repeat('a', 100)."-line-{$i}";
        }
        $content = implode("\n", $lines);
        $parts = AiServiceClient::chunkTextForIngest($content, 1200);

        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(1200, strlen($part));
        }
        $this->assertSame($content, implode("\n", $parts));
    }

    public function test_chunk_text_for_ingest_keeps_small_docs_whole(): void
    {
        $content = "Clinic opens Saturday 9am.\nBring your ID.";
        $parts = AiServiceClient::chunkTextForIngest($content, 28000);

        $this->assertSame([$content], $parts);
    }
}