# Backend (Laravel)

Laravel REST API for Community Assistant: authentication, tenancy, permissions, product logic, channel webhooks, and orchestration of the private AI service.

See [root README](../README.md), [AGENTS.md](../AGENTS.md), [PRD](../docs/prd.md), and [implementation plan](../docs/implementation-plan.md).

## Stack

| Piece | Choice |
| --- | --- |
| Framework | Laravel 13 |
| Language | PHP 8.3+ |
| Local Docker | Laravel Sail (`compose.yaml`) |
| Auth | Laravel Sanctum (Phase 1) |
| API docs | Scramble OpenAPI at `/docs/api` (+ `/docs/api.json`) |

| Database | PostgreSQL + pgvector |
| Queue / cache | Redis; `php artisan queue:work` |

## Prerequisites

- PHP 8.3+, Composer
- Docker (for Sail)

## Setup with Sail (recommended locally)

Same steps twice only because shells differ: `cp` / `./vendor/bin/sail` on Linux/macOS, `Copy-Item` / `.\vendor\bin\sail` on Windows PowerShell.

**Linux / macOS**

```bash
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

**Windows (PowerShell)**

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
.\vendor\bin\sail up -d
.\vendor\bin\sail artisan migrate
```

Sail is for local development only. Production uses dedicated images (Phase 7).

If root `compose.yaml` already binds `5432`/`6379`, stop it before starting Sail (or change `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT`).

## Scripts

| Command | Purpose |
| --- | --- |
| `./vendor/bin/sail up -d` | Start Sail stack |
| `./vendor/bin/sail artisan migrate` | Run Laravel migrations |
| `./vendor/bin/sail artisan zak:seed-assistant-demo` | Seed demo user/community/knowledge + print Bearer token |
| `./scripts/seed-and-ask.sh` | Seed then call `POST /api/v1/assistant/ask` (WSL/Linux) |
| `.\scripts\seed-and-ask.ps1` | Same on PowerShell |
| `./vendor/bin/sail artisan test` | Tests inside Sail |
| `./vendor/bin/sail artisan queue:work` | Queue worker |
| `vendor/bin/pint` | Code style |

## Test `/api/v1/assistant/ask` (team)

### 1. Backend (Sail)

```bash
cd backend
cp .env.example .env   # first time; set AI_SERVICE_URL + INTERNAL_HMAC_SECRET
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

PowerShell: `Copy-Item` / `.\vendor\bin\sail` instead of `cp` / `./vendor/bin/sail`.

### 2. AI service

```bash
cd ai-service
cp .env.example .env   # first time; set OPENAI_API_KEY, providers, matching HMAC
uv sync
uv run alembic upgrade head
uv run fastapi dev src/ai_service/main.py --port 8001
```

Details: [ai-service/README.md](../ai-service/README.md).

### 3. Seed + ask

```bash
cd backend
./vendor/bin/sail artisan zak:seed-assistant-demo
./scripts/seed-and-ask.sh
```

PowerShell: `.\vendor\bin\sail artisan zak:seed-assistant-demo` then `.\scripts\seed-and-ask.ps1`.

The seed command prints a Bearer token and curl/PowerShell examples, and writes:

| File | Contents |
| --- | --- |
| `storage/app/zak-demo-ask.json` | Seed summary + ask example |
| `storage/app/zak-demo-ask.env` | Token and community id |

Default user: `demo@zak.test` / `password123` (`community_admin`).

Manual ask (values from seed output):

```bash
curl -sS -X POST 'http://localhost/api/v1/assistant/ask' \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"query":"When does the clinic open?","community_ids":["YOUR_COMMUNITY_ID"],"target_language":"en"}'
```

OpenAPI: http://localhost/docs/api (Authorize with a Sanctum Bearer token — see [docs/api/README.md](../docs/api/README.md)).

Optional Laravel-only seed (no RAG ingest): `zak:seed-assistant-demo --skip-ai`.

## Layout

Modular monolith scaffolding (empty until features land):

```text
app/
  Actions/
  Contracts/{Channels,AI,Storage}/
  Data/ Enums/ Events/ Exceptions/
  Http/Controllers/Api/V1/
  Http/{Middleware,Requests,Resources}/
  Jobs/ Listeners/ Models/ Notifications/ Policies/
  Services/{AI,Channels,Knowledge,Meetings,Tenancy}/
  Support/
```

Request flow: Route → Form Request → Policy/tenant scope → Action/Service → API Resource.

## Conventions

- Use **Laravel Sail** for local backend work: `./vendor/bin/sail …` (PowerShell: `.\vendor\bin\sail …`)
- Thin controllers; no fat repositories that only wrap Eloquent
- UUID/ULID public IDs; tenant + community scopes on relevant queries
- Frontend and channels call this API only
- AI work is delegated to `../ai-service` over signed internal requests

## WhatsApp Web spike (DEV ONLY)

Unofficial WA Web automation lives in [`../infrastructure/whatsapp-web-spike`](../infrastructure/whatsapp-web-spike). Enable with `WHATSAPP_WEB_SPIKE=true` + `WHATSAPP_WEB_SPIKE_SECRET` in `.env`. Not wired into Sail/prod Compose. See Phase 4W in the implementation plan.
