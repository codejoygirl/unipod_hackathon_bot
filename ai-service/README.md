# AI Service Microservice

Enterprise Multimodal Grounded RAG with strict tenant isolation, hybrid retrieval, and interactive deep-linked citations.

## Features

- **Dual Providers**: Google Gemini and OpenAI integration.
- **Multimodal Support**: Audio, Video, Image, and Text parsing.
- **Enterprise Security**: PII Redaction, RBAC, and strict HMAC Auth.

## Stack

| Piece | Choice |
| --- | --- |
| Framework | FastAPI (OpenAPI UI at `/docs`, JSON at `/openapi.json`) |
| Packaging | uv + committed `uv.lock` |
| Validation | Pydantic / pydantic-settings |
| ORM / migrations | SQLAlchemy + Alembic |
| Providers | Protocols in `providers/base.py` (OpenAI, Anthropic, Gemini, mock) |

## Prerequisites

- Python 3.12+
- [uv](https://docs.astral.sh/uv/)
- Sail Postgres running (Laravel backend Sail stack), reachable at `127.0.0.1:5432` from the host

## Setup

1. Copy env and fill keys (see `.env.example`):

```bash
cp .env.example .env
```

Set at least:

- `DATABASE_URL` — Sail Postgres on the host (`…@127.0.0.1:5432/zak`)
- `INTERNAL_HMAC_SECRET` — same value as `backend/.env`
- `OPENAI_API_KEY` (or `GEMINI_API_KEY` if using Gemini)
- `LLM_PROVIDER` / `EMBEDDING_PROVIDER` — `openai` or `gemini` (match your key)

2. Install, migrate, run:

**Linux / macOS / WSL**

```bash
uv sync
uv run alembic upgrade head
uv run fastapi dev src/ai_service/main.py --port 8001
```

**Windows (PowerShell)**

```powershell
Copy-Item .env.example .env
uv sync
uv run alembic upgrade head
uv run fastapi dev src/ai_service/main.py --port 8001
```

**Docker**

```bash
docker compose up -d --build
```

| URL | What |
| --- | --- |
| http://localhost:8001/docs | OpenAPI UI (local only) |
| http://localhost:8001/openapi.json | OpenAPI JSON |
| http://localhost:8001/health/live | Liveness |
| http://localhost:8001/health/ready | Readiness |

Laravel reaches this service via `AI_SERVICE_URL=http://host.docker.internal:8001` (see `backend/.env.example`).

## Scripts

| Command | Purpose |
| --- | --- |
| `uv sync` | Install deps from lockfile |
| `uv run alembic upgrade head` | Apply RAG schema migrations |
| `uv run fastapi dev src/ai_service/main.py --port 8001` | Dev server |
| `uv run pytest` | Tests |
| `uv run tests/test_live_all_modalities.py` | Live multimodal verification |
| `uv run ruff check .` | Lint |

## Layout

Package name is `ai_service` (from project `ai-service`). Modules match the architecture:

```text
src/ai_service/
  main.py
  api/{routes,dependencies.py}
  core/{config,security,logging}.py
  schemas/
  providers/{base,openai,anthropic,gemini,mock}.py
  embeddings/ retrieval/ reranking/ ingestion/
  generation/ translation/ transcription/
  citations/ evaluations/
migrations/          # Alembic
tests/{unit,integration,evaluation}/
```

## Conventions

- Use-case endpoints (`/v1/answers`, …), not raw `/prompt`
- Separate env vars for chat, embedding, transcription, and translation models
- Repeat permission filters on retrieval (`tenant_id` + `community_id` columns)
- Commit `uv.lock`; production images run `uv sync --locked`
- Do **not** commit ffmpeg binaries; install ffmpeg on the host/image instead

## Testing

**Unit / integration (no live API keys required for most):**

```bash
cd ai-service
uv sync --group dev
uv run pytest
```

Useful subsets:

```bash
uv run pytest tests/unit -q
uv run pytest tests/unit/test_security_hmac.py tests/integration/test_tenant_isolation.py -q
uv run pytest tests/evaluation -q
```

**HMAC header helper (PowerShell):**

```powershell
$secret = "prod_secure_hmac_secret_key_minimum_32_bytes_entropy"
$ts = [int][double]::Parse((Get-Date -UFormat %s))
$body = '{"tenant_id":"...","community_id":"...","uri":"doc://1","name":"n","source_type":"markdown","content":"Hello"}'
$msg = "$ts.$body"
$hmac = [System.Security.Cryptography.HMACSHA256]::new([Text.Encoding]::UTF8.GetBytes($secret))
$sig = ($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($msg)) | ForEach-Object { $_.ToString("x2") }) -join ""
Invoke-RestMethod -Method POST -Uri http://localhost:8001/ingestion/sync `
  -Headers @{ "X-Timestamp"="$ts"; "X-Signature"=$sig } `
  -ContentType "application/json" -Body $body
```

**Live multimodal suite** (needs running service + keys + system `ffmpeg`):

```bash
uv run pytest tests/test_live_all_modalities.py -q
```
