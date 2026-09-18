# API documentation

Zak uses **OpenAPI** (the specification). “Swagger” usually means the UI/tooling around it.

| Service | UI | Spec JSON | Generator |
| --- | --- | --- | --- |
| Backend (Laravel) | `/docs/api` | `/docs/api.json` | [Scramble](https://scramble.dedoc.co/) |
| AI service (FastAPI, internal) | `/docs` | `/openapi.json` | FastAPI native |

## Local URLs

```text
Backend:     http://localhost:8000/docs/api
Backend JSON: http://localhost:8000/docs/api.json

AI service:  http://localhost:8001/docs
AI JSON:     http://localhost:8001/openapi.json
```

Laravel docs are limited to the `local` environment by default (`RestrictedDocsAccess`).  
Do **not** expose AI-service docs on the public internet in production.

## Backend setup

- Package: `dedoc/scramble`
- Config: `backend/config/scramble.php` (`api_path` = `api/v1`)
- Routes under `backend/routes/api.php` are documented automatically

```bash
cd backend
php artisan serve
# open /docs/api
```

## AI service setup

```bash
cd ai-service
uv run fastapi dev src/ai_service/main.py --port 8001
# open /docs
```

## Frontend types (later)

Generate TypeScript clients from Laravel’s `/docs/api.json` in Phase 3/4. Do not generate public clients against the AI service OpenAPI.
