# AI Service Integration Guide

This guide explains how to connect your main application (or other services) to the `ai-service` via its REST API.

## 1. Authentication (HMAC Signatures)

The `ai-service` is an internal microservice, which means it shouldn't be exposed directly to the public internet. It uses **HMAC SHA-256** signatures to authenticate requests and ensure they are coming from a trusted internal source (like your main backend).

You will need the `INTERNAL_HMAC_SECRET` (configured in the `.env` file of the `ai-service`).

For every request, you must include the following headers:
*   `Content-Type: application/json`
*   `X-Timestamp: <current_unix_timestamp_seconds>`
*   `X-Signature: <hmac_sha256_signature>`

**Python Example:**
```python
import time
import hmac
import hashlib
import json

SECRET = "your_secure_hmac_secret_key"

def get_auth_headers(payload_dict: dict) -> dict:
    payload_str = json.dumps(payload_dict)
    timestamp = str(int(time.time()))
    
    # The message to sign is exactly: "{timestamp}.{payload_str}"
    message = f"{timestamp}.{payload_str}".encode("utf-8")
    
    signature = hmac.new(
        SECRET.encode("utf-8"),
        message,
        hashlib.sha256
    ).hexdigest()

    return {
        "Content-Type": "application/json",
        "X-Timestamp": timestamp,
        "X-Signature": signature
    }
```

## 2. Ingesting Documents (Text)

To add textual knowledge to the AI so it can answer questions, send a `POST` request to `/ingestion/sync`.

**Endpoint:** `POST /ingestion/sync`

**Payload Example:**
```json
{
  "tenant_id": "19333900-de82-487b-beeb-5b959660425b",
  "community_id": "19333900-de82-487b-beeb-5b959660425b",
  "name": "Community Health Standards",
  "uri": "app://documents/123",
  "source_type": "markdown",
  "content": "The clinic is open from 9 AM to 5 PM. Vaccines are on Wednesdays.",
  "authority_tier": "official_announcement"
}
```

## 3. Ingesting Media (Image, Audio, Video)

To ingest multimodal files, send a `multipart/form-data` request to `/ingestion/multimodal`.

**Endpoint:** `POST /ingestion/multimodal`

**Form Fields:**
*   `file`: The actual media file (e.g., image.png, audio.mp3, video.mp4).
*   `tenant_id`: UUID string.
*   `community_id`: UUID string.
*   `name`: The display name of the file.
*   `uri`: The source URI.
*   `source_type`: 'image', 'audio', or 'video'.
*   `authority_tier`: (Optional) Defaults to 'community_discussion'.

The AI Service will automatically transcribe audio, describe keyframes in video, and perform OCR on images.

## 4. Asking Questions (Retrieval Augmented Generation)

To ask the AI a question, send a `POST` request to `/retrieval/grounded-answer`.

**Endpoint:** `POST /retrieval/grounded-answer`

**Payload Example:**
```json
{
  "tenant_id": "19333900-de82-487b-beeb-5b959660425b",
  "community_ids": ["19333900-de82-487b-beeb-5b959660425b"],
  "query": "When can I get a vaccine?",
  "top_k": 3,
  "enable_conflict_detection": true,
  "temperature": 0.0
}
```

**Response Example:**
```json
{
  "query": "When can I get a vaccine?",
  "validated_payload": {
    "state": "VERIFIED",
    "answer": "Vaccines are administered on Wednesdays [E1].",
    "confidence_score": 0.85,
    "citations": [
        "evidence_id": "E1",
        "exact_quote": "Vaccines are on Wednesdays.",
        "source_name": "Community Health Standards",
        "source_uri": "app://documents/123",
        "media_type": "text",
        "locator": null,
        "is_verified": true
      },
      {
        "evidence_id": "E2",
        "exact_quote": "[Audio Transcript] Yes, vaccines are on Wednesday mornings.",
        "source_name": "Clinic Update Recording",
        "source_uri": "app://audio/456",
        "media_type": "audio",
        "locator": "01:23-01:30",
        "is_verified": true
      }
    ],
    "conflicts": [],
    "needs_escalation": false
  },
  "execution_time_ms": 1250,
  "total_chunks_retrieved": 3
}
```

**Key Response Fields:**
*   `state`: This is the most important field. It can be:
    *   `VERIFIED`: High confidence, high authority source. Safe to show the user.
    *   `POSSIBLE`: Lower confidence or community-tier source. May require a disclaimer.
    *   `CONFLICT`: The AI found contradicting information across documents. Needs human escalation.
    *   `INSUFFICIENT_EVIDENCE`: No documents were found to answer the query (stops hallucinations!).
*   `answer`: The generated answer text.
*   `citations`: A list of the exact quotes the AI used to prove its answer. Now includes deep-linking metadata:
    *   `source_uri`: Where to send the user when they click the citation.
    *   `media_type`: `'text'`, `'image'`, `'audio'`, or `'video'`.
    *   `locator`: Exact media location (e.g., `01:23-01:30` for audio/video, bounding box for image).

## 5. Model Configuration (OpenAI vs Gemini)

The AI service uses an abstract model layer and can be toggled between OpenAI and Google Gemini via `.env` or environment variables without changing your application's API calls.

*   `LLM_PROVIDER=gemini` (uses Gemini 1.5 Flash for chat & reasoning)
*   `LLM_PROVIDER=openai` (uses GPT-4o for chat & reasoning)
*   `EMBEDDING_PROVIDER=gemini` (uses Gemini embeddings)
*   `EMBEDDING_PROVIDER=openai` (uses text-embedding-3)

## 6. Health Checks

For Kubernetes or Docker, the service exposes public liveness and readiness endpoints (no HMAC needed):
*   **Liveness:** `GET /health/live` (Returns 200 if the service is running).
*   **Readiness:** `GET /health/ready` (Returns 200 if the database connection is healthy).
