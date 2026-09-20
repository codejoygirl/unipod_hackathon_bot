# REST API Specification

The `ai-service` enforces multi-tenant row-level boundaries and verifies cryptographic signatures on every HTTP endpoint.

## Authentication (HMAC SHA-256)
Every request must include:
- `X-Timestamp`: Integer epoch seconds. (Must be within 300 seconds of server time)
- `X-Signature`: HMAC SHA-256 hash of `{timestamp}.{payload_str}`

## Core Endpoints

### 1. `POST /ingestion/multimodal`
Ingests media or text.
- **Content-Type**: `multipart/form-data`
- **Fields**:
  - `file` (optional if `content` set): media or text file
  - `content` (optional if `file` set): plain text body
  - `tenant_id`, `community_id`, `name`, `uri`
  - `source_type`: `image` | `audio` | `video` | `text` | `markdown` | `whatsapp` | …

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
