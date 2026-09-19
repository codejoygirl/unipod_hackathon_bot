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
- Postgres available via **Laravel Sail** (`DB_HOST=pgsql`) or root Compose

## Setup

**Linux / macOS**

```bash
cp .env.example .env
# populate OPENAI_API_KEY and GEMINI_API_KEY
uv sync
uv run fastapi dev src/ai_service/main.py --port 8001
```

**Windows (PowerShell)**

```powershell
Copy-Item .env.example .env
uv sync
uv run fastapi dev src/ai_service/main.py --port 8001
```

**Docker**

```bash
docker compose up -d --build
```

| URL | What |
| --- | --- |
| http://localhost:8001/docs | OpenAPI UI (local only) |
| http://localhost:8001/health/live | Liveness |
| http://localhost:8001/health/ready | Readiness |

## Scripts

| Command | Purpose |
| --- | --- |
| `uv sync` | Install deps from lockfile |
| `uv run fastapi dev src/ai_service/main.py --port 8001` | Dev server |
| `uv run alembic upgrade head` | Apply migrations |
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

**Run the service locally (against Sail Postgres on host port 5432):**

```bash
cp .env.example .env
# set INTERNAL_HMAC_SECRET, DATABASE_URL, OPENAI_API_KEY / GEMINI_API_KEY as needed
uv run alembic upgrade head
uv run fastapi dev src/ai_service/main.py --port 8001
```

Then open http://localhost:8001/docs — try `GET /health/live` (no HMAC) and signed `POST /ingestion/sync` / retrieval routes.

**HMAC header helper (PowerShell):**

```powershell
$secret = "dev_insecure_secret_key_change_in_prod"
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
# or: uv run python tests/test_live_all_modalities.py
```
