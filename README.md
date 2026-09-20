# Community Assistant

Multi-tenant, multilingual, omnichannel AI community platform. Members ask questions through web, WhatsApp, Slack, and future channels. The assistant answers only from knowledge each member is authorised to see, with citations.

UniPods is the first deployment. The product is not hard-coded for UniPods.

Engineering invariants: [AGENTS.md](AGENTS.md).

## Repository layout

Top-level names follow the same pattern as the local Gravity reference (`backend/` + `frontend/`), plus `ai-service/`:

```text
backend/       Laravel API and product backend (Sail for local DX)
frontend/      Next.js TypeScript PWA
ai-service/    FastAPI — RAG, transcription, translation, evaluation, …
infrastructure/  Docker, nginx, deploy stubs; DEV-ONLY WhatsApp Web spike sidecar
docs/          PRD, plan, architecture/api/decisions/operations
```

## Architecture

```text
Channels / Browser  →  backend (Laravel)  →  PostgreSQL + Redis + private files
                              ↓
                         ai-service (FastAPI, internal only)
```

Rules (locked):

- The frontend talks only to Laravel.
- Channel webhooks enter through Laravel.
- Laravel is the system of record and security boundary.
- The AI service is not public. Laravel calls it with signed requests.
- The AI service repeats permission filters during retrieval. Laravel validates citations before answers leave the API.

## Stack

| Area | Choice |
| --- | --- |
| Frontend | Next.js, TypeScript, Tailwind; shadcn/ui + TanStack Query + RHF/Zod (Phase 4) |
| Backend | Laravel 13, PHP 8.3+, Sanctum + Scramble (Phase 1), modular monolith |
| AI service | FastAPI, Pydantic, SQLAlchemy, Alembic, uv + `uv.lock` |
| Database | PostgreSQL + pgvector |
| Cache / queues | Redis; Laravel Queue (Horizon later); Supervisor in production |
| Local Laravel | **Laravel Sail** (`backend/compose.yaml`) — not used for production |
| Monorepo infra | Root `compose.yaml` (Postgres + Redis now; app images later) |

## Requirements

- Node.js 20+ (22 recommended) and npm (root `npm install` enables Husky hooks)
- PHP 8.3+ and Composer
- Python 3.12+ and [uv](https://docs.astral.sh/uv/)
- Docker Desktop (or Engine + Compose)

## Quick start

### Option A — Sail (Laravel local DX)

**Linux / macOS**

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

**Windows (PowerShell)**

```powershell
cd backend
Copy-Item .env.example .env
composer install
php artisan key:generate
.\vendor\bin\sail up -d
.\vendor\bin\sail artisan migrate
```

### Option B — Root Compose infra + host processes

**Linux / macOS**

```bash
cp .env.example .env
docker compose -f compose.yaml up -d
```

**Windows (PowerShell)**

```powershell
Copy-Item .env.example .env
docker compose -f compose.yaml up -d
```

Do not run Option A and Option B databases on the same ports at once.

Then (same idea on both shells — use `Copy-Item` instead of `cp` on PowerShell):

```bash
# frontend
cd frontend && cp .env.example .env.local && npm install && npm run dev

# ai-service (after Sail is up so Postgres is on 5432)
cd ai-service && cp .env.example .env && uv sync
# edit .env: OPENAI_API_KEY, providers, INTERNAL_HMAC_SECRET (match backend)
uv run alembic upgrade head
uv run fastapi dev src/ai_service/main.py --port 8001
```

| URL | Service |
| --- | --- |
| http://localhost | Laravel API (Sail, default `APP_PORT=80`) |
| http://localhost/docs/api | Scramble OpenAPI |
| http://localhost:3000 | Frontend |
| http://localhost:8001/docs | AI service OpenAPI (local only) |
| http://localhost:8001/health/live | AI liveness |

### Test assistant ask (team)

```bash
# terminal 1 — Sail (if not already up)
cd backend && ./vendor/bin/sail up -d && ./vendor/bin/sail artisan migrate

# terminal 2 — AI service
cd ai-service && uv run alembic upgrade head
uv run fastapi dev src/ai_service/main.py --port 8001

# terminal 1 — seed + ask
cd backend
./vendor/bin/sail artisan zak:seed-assistant-demo
./scripts/seed-and-ask.sh
```

Demo user: `demo@zak.test` / `password123`. Full steps: [backend/README.md](backend/README.md#test-apiv1assistantask-team).

## Documentation

| Doc | Purpose |
| --- | --- |
| [docs/api/README.md](docs/api/README.md) | OpenAPI / Scramble / FastAPI docs |
| [docs/prd.md](docs/prd.md) | Working product requirements |
| [docs/implementation-plan.md](docs/implementation-plan.md) | Phased build plan (maps to PRD §40) |
| [CHANGELOG.md](CHANGELOG.md) | Notable changes |
| [AGENTS.md](AGENTS.md) | Engineering invariants |
| [backend/README.md](backend/README.md) | Laravel + Sail |
| [frontend/README.md](frontend/README.md) | Next.js |
| [ai-service/README.md](ai-service/README.md) | FastAPI |

## Current status

Phase 0–2 foundation on `develop`: Sanctum tenancy, knowledge lifecycle, RAG AI service, assistant ask with citation revalidation. Next: Phase 3 member experience.

## Security notes

- Keep `.env` files out of Git. Only `.env.example` templates are tracked.
- Never put server secrets in `NEXT_PUBLIC_*` variables.
- Tenant and community isolation must be enforced in Laravel (and repeated in AI retrieval), not only in prompts.
- WhatsApp Web automation under `infrastructure/whatsapp-web-spike/` is a **dev spike only** (`WHATSAPP_WEB_SPIKE=false` by default). Production channel path is WhatsApp Cloud API (Phase 4).
