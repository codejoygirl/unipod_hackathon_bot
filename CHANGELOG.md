# Changelog

All notable changes to Community AI Assistant are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Phase 4T Telegram Bot spike (DEV ONLY): official Bot API sidecar under `infrastructure/telegram-spike/`, Laravel `TelegramSpikeAdapter` gated by `TELEGRAM_SPIKE`
- Phase 4W WhatsApp Web automation spike (DEV ONLY): whatsapp-web.js sidecar under `infrastructure/whatsapp-web-spike/`, Laravel `WhatsAppWebSpikeAdapter` + internal webhook gated by `WHATSAPP_WEB_SPIKE`
- Phase 2 knowledge/RAG: Laravel knowledge lifecycle (draft → review → publish), WhatsApp export drafts, assistant ask with citation revalidation, AI community_id columns + multilingual query expansion, HMAC restored
- Phase 1 backend foundation: Sanctum SPA auth, tenancy models, policies, audit log, private storage, `/api/v1` routes, tenant isolation tests
- OpenAPI docs scaffold: Laravel Scramble (`/docs/api`) and FastAPI (`/docs`, `/openapi.json`)
- Husky + commitlint (Conventional Commits) and GitHub PR title workflow
- Pull request template and contributing guide for commit/PR formats
- Monorepo scaffold: `backend/` (Laravel 13 + Sail), `frontend/` (Next.js), `ai-service/` (FastAPI + uv)
- Modular Laravel app folders (`Actions`, `Contracts`, `Services`, …)
- Frontend App Router / features / components / lib directory tree
- AI service module tree, provider protocol stubs, SQLAlchemy + Alembic scaffolding
- Root `compose.yaml` for Postgres (pgvector) and Redis; Sail `backend/compose.yaml` for local Laravel DX
- `infrastructure/{docker,nginx,scripts}` and `docs/{architecture,api,decisions,operations}` stubs
- Working PRD (`docs/prd.md`), implementation plan (`docs/implementation-plan.md`), and `AGENTS.md`

### Changed

- Root Compose file renamed to `compose.yaml` to match the PRD repository layout
- PRD replaced with the full working product requirements document (METI UniPods initial deployment)

## [0.1.0] - 2026-09-18

### Added

- Initial repository foundation (scaffold only; no product features)
