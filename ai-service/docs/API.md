# REST API Specification

The `ai-service` enforces multi-tenant row-level boundaries and verifies cryptographic signatures on every HTTP endpoint.

## Authentication (HMAC SHA-256)
Every request must include:
- `X-Timestamp`: Integer epoch seconds. (Must be within 300 seconds of server time)
- `X-Signature`: HMAC SHA-256 hash of `{timestamp}.{payload_str}`

## Core Endpoints

### 1. `POST /ingestion/multimodal`
Ingests media formats.
- **Content-Type**: `multipart/form-data`
- **Fields**:
  - `file`: The media file (image, audio, video)
  - `tenant_id`: UUID
  - `community_id`: UUID
  - `source_type`: 'image' | 'audio' | 'video' | 'text'

### 2. `POST /retrieval/grounded-answer`
Queries the vector DB and synthesizes an answer.
- **Payload**:
```json
{
  "tenant_id": "uuid",
  "community_ids": ["uuid"],
  "query": "What is the procedure?",
  "top_k": 25
}
```
- **Response**:
Returns a 4-state deterministic `ValidatedAnswerPayload` ensuring citations are grounded with exact temporal/spatial media locators.
