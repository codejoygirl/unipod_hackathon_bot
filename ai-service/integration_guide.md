# AI Service Integration Guide

This guide details how upstream backends (Laravel), frontend proxies (Next.js), and edge services connect to and consume the `ai-service/` REST API.

---

## 1. Connection Overview & Base Configuration

* **Protocol**: HTTP/JSON over internal bridge network or VPC
* **Default Port**: `8000`
* **Base URL (Local/Docker)**: `http://ai-service:8000` (container-to-container) or `http://127.0.0.1:8000` (host-to-container)
* **Auth Scheme**: Custom HMAC-SHA256 header signature
* **Content-Type**: `application/json; charset=utf-8`

### Environment Variables Required on Client Services
```env
AI_SERVICE_URL=[http://127.0.0.1:8000](http://127.0.0.1:8000)
INTERNAL_HMAC_SECRET=prod_secure_hmac_secret_key_minimum_32_bytes_entropy
AI_SERVICE_TIMEOUT=10.0
AI_SERVICE_CONNECT_TIMEOUT=2.0
```

---

## 2. Authentication Contract (HMAC-SHA256)

Every request to `/retrieval/*` and `/ingestion/*` requires two mandatory verification headers:

| Header | Type | Description |
| :--- | :--- | :--- |
| `X-Timestamp` | Unix Epoch String (Seconds) | Generation timestamp. Requests with drift $> \pm 300\text{s}$ are rejected (`401`). |
| `X-Signature` | 64-char Hex String | `HMAC-SHA256(INTERNAL_HMAC_SECRET, timestamp + "." + raw_json_body)` |

### Canonical Signature String
$$\text{message} = \text{timestamp} + \text{"."} + \text{raw\_body}$$
$$\text{X-Signature} = \text{HMAC\_SHA256}(\text{INTERNAL\_HMAC\_SECRET}, \text{message})$$

> **Important**: Sign the exact byte representation of the payload sent over the wire. Do not reformat or pretty-print the JSON after generating the signature.

---

## 3. Endpoints & Schemas

### A. Health Probes (No Auth Required)

#### 1. Liveness Probe: `GET /health/live`
Checks whether the process and asyncio event loop are responsive.
```bash
curl -i http://localhost:8000/health/live
```
```json
{
  "status": "healthy",
  "service": "ai-service",
  "environment": "production"
}
```

#### 2. Readiness Probe: `GET /health/ready`
Verifies database connectivity and `pgvector` extension readiness.
```bash
curl -i http://localhost:8000/health/ready
```
```json
{
  "status": "ready",
  "database": "connected",
  "vector_extension": "active"
}
```

---

### B. Ingest Document: `POST /ingestion/sync` (Auth Required)

Synchronously normalizes text (Unicode NFC), runs SHA-256 deduplication, chunks, embeds, and indexes records into PostgreSQL.

#### Request Body
```json
{
  "tenant_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "community_id": "a1a2a3a4-b1b2-c1c2-d1d2-e1e2e3e4e5e6",
  "uri": "s3://civic-docs/health/clinic_schedule.pdf",
  "name": "Clinic_Operating_Hours_2026.pdf",
  "source_type": "pdf",
  "content": "Municipal Health Notice: The community clinic is open Monday through Friday from 8:00 AM to 4:30 PM. Walk-in vaccinations are administered between 9:00 AM and 1:00 PM.",
  "authority_tier": "official_announcement",
  "metadata": {
    "department": "Public Health",
    "version": "1.0"
  }
}
```

#### Field Values for `authority_tier`:
* `"official_announcement"` (Weight: 1.00)
* `"policy_document"` (Weight: 0.95)
* `"community_discussion"` (Weight: 0.80)

#### Successful Response (`200 OK`)
```json
{
  "source_id": "0d6e0b74-325b-4c12-9c16-b816a249c5e2",
  "version_id": "4e75d409-e64e-4f10-91a0-4ff60b616b0a",
  "status": "completed",
  "content_sha256": "8f481c7f5f9e2b...",
  "chunks_created": 3,
  "message": "Successfully indexed 3 chunks.",
  "execution_time_ms": 142.3
}
```

> **Deduplication note**: If an identical content hash exists for this `(tenant_id, uri)`, the service returns `"status": "skipped_duplicate"` and `"chunks_created": 0` without consuming embedding API tokens.

---

### C. Grounded QA & Retrieval: `POST /retrieval/grounded-answer` (Auth Required)

Executes hybrid search (dense HNSW + sparse GIN tsvector), RRF fusion, authority reweighting, conflict detection, and LLM synthesis with citation verification.

#### Request Body
```json
{
  "query": "When is the clinic open for walk-in vaccinations?",
  "tenant_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "community_ids": [
    "a1a2a3a4-b1b2-c1c2-d1d2-e1e2e3e4e5e6"
  ],
  "target_language": "en",
  "temperature": 0.0,
  "enable_conflict_detection": true
}
```

#### Successful Response (`200 OK`)
```json
{
  "query": "When is the clinic open for walk-in vaccinations?",
  "detected_language": "en",
  "validated_payload": {
    "state": "VERIFIED",
    "answer": "The community clinic is open Monday through Friday from 8:00 AM to 4:30 PM, with walk-in vaccinations administered between 9:00 AM and 1:00 PM [E1].",
    "confidence_score": 0.94,
    "needs_escalation": false,
    "escalation_reason": null,
    "citations": [
      {
        "evidence_id": "E1",
        "chunk_id": "787c8051-57d4-4cf9-a6e5-4f4b9f27d425",
        "source_name": "Clinic_Operating_Hours_2026.pdf",
        "source_uri": "s3://civic-docs/health/clinic_schedule.pdf",
        "source_type": "pdf",
        "authority_tier": "official_announcement",
        "exact_quote": "The community clinic is open Monday through Friday from 8:00 AM to 4:30 PM. Walk-in vaccinations are administered between 9:00 AM and 1:00 PM.",
        "context_snippet": "...Municipal Health Notice: The community clinic is open Monday through Friday from 8:00 AM to 4:30 PM. Walk-in vaccinations are administered between 9:00 AM and 1:00 PM...",
        "page_number": 1,
        "timestamp_seconds": null,
        "is_verified": true
      }
    ],
    "conflicts": []
  },
  "execution_time_ms": 68.4,
  "total_chunks_retrieved": 4
}
```

---

## 4. The 4-State Response Matrix: Consumer Behavior Rules

Upstream clients must branch on `validated_payload.state`:

| State Value | Confidence Threshold | `answer` Text Content | Client / UI Action |
| :--- | :--- | :--- | :--- |
| **`VERIFIED`** | $\ge 0.82$ | Strict factual statement with `[E#]` anchors. | Display text normally. Render interactive evidence drawer using `citations`. |
| **`POSSIBLE`** | $0.75 \le s < 0.82$ | Sourced from lower-tier or informal evidence. | Display text accompanied by an advisory badge: *"Unverified community report."* |
| **`CONFLICT`** | Any | Contains contradictory claims. | Display both conflicting statements. **Emit notification to community staff.** |
| **`INSUFFICIENT_EVIDENCE`** | $< 0.75$ | **Guaranteed empty string (`""`)**. | Do NOT show ungrounded text. Display fallback message and **open support ticket**. |

---

## 5. Client Implementation Examples

### Option 1: PHP / Laravel Integration

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class AiServiceClient
{
    public function __construct(
        protected string $baseUrl = env('AI_SERVICE_URL', '[http://127.0.0.1:8000](http://127.0.0.1:8000)'),
        protected string $secret = env('INTERNAL_HMAC_SECRET', '')
    ) {}

    public function askQuestion(string $query, string $tenantId, array$communityIds): array
    {
        $payload = [
            'query' => trim($query),
            'tenant_id' => $tenantId,
            'community_ids' => array_values($communityIds),
            'target_language' => 'en',
            'temperature' => 0.0,
            'enable_conflict_detection' => true,
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES \vert{} JSON_UNESCAPED_UNICODE);$timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        $response = Http::timeout(10.0)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            ])
            ->withBody($rawBody, 'application/json')
            ->post("{$this->baseUrl}/retrieval/grounded-answer");

        if ($response->failed()) {
            return [
                'state' => 'INSUFFICIENT_EVIDENCE',
                'answer' => '',
                'needs_escalation' => true,
                'escalation_reason' => 'Service error: ' . $response->status(),
            ];
        }

        return $response->json('validated_payload');
    }
}
```

---

### Option 2: TypeScript / Node.js / Next.js Proxy

```typescript
import crypto from 'crypto';

interface GroundedAnswerRequest {
  query: string;
  tenant_id: string;
  community_ids: string[];
  target_language?: string;
  temperature?: number;
  enable_conflict_detection?: boolean;
}

export async function fetchGroundedAnswer(payload: GroundedAnswerRequest) {
  const baseUrl = process.env.AI_SERVICE_URL || '[http://127.0.0.1:8000](http://127.0.0.1:8000)';
  const secret = process.env.INTERNAL_HMAC_SECRET || '';

  const rawBody = JSON.stringify({
    ...payload,
    temperature: 0.0,
    enable_conflict_detection: true,
  });

  const timestamp = Math.floor(Date.now() / 1000).toString();
  const signature = crypto
    .createHmac('sha256', secret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex');

  const response = await fetch(`${baseUrl}/retrieval/grounded-answer`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Timestamp': timestamp,
      'X-Signature': signature,
    },
    body: rawBody,
  });

  if (!response.ok) {
    throw new Error(`AI Service request failed with HTTP ${response.status}`);
  }

  const data = await response.json();
  return data.validated_payload;
}
```

---

### Option 3: Terminal / cURL Smoke Test

```bash
#!/usr/bin/env bash
set -e

SECRET="prod_secure_hmac_secret_key_minimum_32_bytes_entropy"
TIMESTAMP=$(date +%s)
BODY='{"query":"When is the clinic open?","tenant_id":"00000000-0000-0000-0000-000000000001","community_ids":["00000000-0000-0000-0000-000000000002"],"target_language":"en"}'

# Sign timestamp + "." + body
SIGNATURE=$(printf "%s.%s" "$TIMESTAMP" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -i -X POST http://localhost:8000/retrieval/grounded-answer \
  -H "Content-Type: application/json" \
  -H "X-Timestamp: $TIMESTAMP" \
  -H "X-Signature: $SIGNATURE" \
  -d "$BODY"
```

---

## 6. Common Pitfalls & Troubleshooting

1. **`401 Unauthorized: Invalid HMAC signature`**
   * **Cause**: JSON serializers differ (e.g., PHP escaping slashes `\/` or formatting whitespace).
   * **Fix**: Ensure PHP uses `json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`. Generate the signature on the exact payload string transmitted.

2. **`401 Unauthorized: Request timestamp outside acceptable window`**
   * **Cause**: Server clock skew between Docker host and client container exceeds $\pm 300\text{s}$.
   * **Fix**: Synchronize system clocks: `sudo systemctl restart systemd-timesyncd`.

3. **`INSUFFICIENT_EVIDENCE` with Empty Answer on Real Data**
   * **Cause**: Multi-tenant isolation filter. If `tenant_id` or `community_ids` do not match the database records, search filters out all rows before ranking.
   * **Fix**: Confirm that ingestion and querying use the identical `tenant_id` and `community_id`.

4. **`500 Internal Server Error: QueuePool limit reached`**
   * **Cause**: Abandoned client connections or long unreturned sessions.
   * **Fix**: Set client HTTP timeouts to $\ge 10.0\text{s}$ and increase `DB_POOL_SIZE` in `ai-service/.env` if handling high concurrency.