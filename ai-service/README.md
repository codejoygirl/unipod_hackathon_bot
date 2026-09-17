# AI Service (FastAPI)

Private FastAPI service for Community Assistant: answering, ingestion helpers, embeddings, translation, transcription, summarisation, and evaluation.

Laravel is the only caller. This service must not be reachable from the public internet in production.

See [root README](../README.md), [AGENTS.md](../AGENTS.md), [PRD](../docs/prd.md), and [implementation plan](../docs/implementation-plan.md).

## Stack

| Piece | Choice |
| --- | --- |
| Framework | FastAPI |
| Packaging | uv + committed `uv.lock` |
| Validation | Pydantic / pydantic-settings |
| ORM / migrations | SQLAlchemy + Alembic |
| Providers | Protocols in `providers/base.py` (OpenAI, Anthropic, Gemini, mock) |

## Prerequisites

- Python 3.12+
- [uv](https://docs.astral.sh/uv/)
- Postgres available (Sail or root Compose)

## Setup

```bash
cp .env.example .env
uv sync
uv run fastapi dev src/ai_service/main.py --port 8001
```

```powershell
Copy-Item .env.example .env
uv sync
uv run fastapi dev src/ai_service/main.py --port 8001
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
| `uv run alembic upgrade head` | Apply migrations (when revisions exist) |
| `uv run pytest` | Tests |
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
