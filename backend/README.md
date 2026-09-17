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
| API docs | Scramble / OpenAPI (Phase 1) |
| Database | PostgreSQL + pgvector |
| Queue / cache | Redis; `php artisan queue:work` |

## Prerequisites

- PHP 8.3+, Composer
- Docker (for Sail)

## Setup with Sail (recommended locally)

```bash
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

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
| `./vendor/bin/sail artisan serve` | Not usually needed; Sail exposes HTTP |
| `./vendor/bin/sail artisan test` | Tests inside Sail |
| `./vendor/bin/sail artisan queue:work` | Queue worker |
| `vendor/bin/pint` | Code style |

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

- Thin controllers; no fat repositories that only wrap Eloquent
- UUID/ULID public IDs; tenant + community scopes on relevant queries
- Frontend and channels call this API only
- AI work is delegated to `../ai-service` over signed internal requests
