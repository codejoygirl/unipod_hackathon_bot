# API documentation

Zak uses **OpenAPI** (the specification). “Swagger” usually means the UI/tooling around it.

| Service | UI | Spec JSON | Generator |
| --- | --- | --- | --- |
| Backend (Laravel) | `/docs/api` | `/docs/api.json` | [Scramble](https://scramble.dedoc.co/) |
| AI service (FastAPI, internal) | `/docs` | `/openapi.json` | FastAPI native |

## Local URLs (Laravel Sail)

```text
Backend:      http://localhost/docs/api
Backend JSON: http://localhost/docs/api.json
API:          http://localhost/api/v1/...

AI service:   http://localhost:8001/docs
AI JSON:      http://localhost:8001/openapi.json
```

Laravel docs are limited to the `local` environment by default (`RestrictedDocsAccess`).  
Do **not** expose AI-service docs on the public internet in production.

## Backend setup (Sail)

- Package: `dedoc/scramble`
- Config: `backend/config/scramble.php` (`api_path` = `api/v1`)
- Routes under `backend/routes/api.php` are documented automatically
- Auth: Scramble documents Sanctum Bearer on `auth:sanctum` routes (`Authorization: Bearer <token>`)

### Authorize in the docs UI (Scramble / Stoplight Elements)

1. Log in or run `sail artisan zak:seed-assistant-demo` for a Bearer token
2. Open http://localhost/docs/api → **Authorize** (or set the Authorization header in **Send API Request**)
3. Paste the token (no `Bearer ` prefix if the UI adds it; otherwise use `Bearer <token>`)

For `POST /knowledge-sources/import` in **Send API Request**:

- **Text / WhatsApp export:** fill `tenant_id`, `community_id`, and `content` (paste the text). Leave `file` empty / omitted.
- **File upload:** fill `tenant_id`, `community_id`, attach `file`, set `source_type` (`image` / `audio` / `video` / `markdown`). Leave `content` empty.

There is no separate “Try it” button — Stoplight Elements uses **Send API Request**.

**Linux / macOS**

```bash
cd backend
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
# open http://localhost/docs/api
```

**Windows (PowerShell)**

```powershell
cd backend
.\vendor\bin\sail up -d
.\vendor\bin\sail artisan migrate
# open http://localhost/docs/api
```

## AI service setup

```bash
cd ai-service
uv run fastapi dev src/ai_service/main.py --port 8001
# open /docs
```

## Frontend types (later)

Generate TypeScript clients from Laravel’s `/docs/api.json` in Phase 3/4. Do not generate public clients against the AI service OpenAPI.
