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
- Repeat permission filters on retrieval
- Commit `uv.lock`; production images run `uv sync --locked`
