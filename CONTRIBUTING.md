# Contributing

Thanks for helping build Community AI Assistant (UniPods hackathon / product scaffold).

## Before you start

1. Read [docs/prd.md](docs/prd.md) for product scope.
2. Read [docs/implementation-plan.md](docs/implementation-plan.md) for delivery order.
3. Read [AGENTS.md](AGENTS.md) for engineering invariants (especially auth and tenancy boundaries).

## Repository layout

| Path | Role |
| --- | --- |
| `backend/` | Laravel API (system of record, permissions) |
| `frontend/` | Next.js PWA |
| `ai-service/` | Private FastAPI AI/RAG service |
| `docs/` | PRD, plan, architecture notes |
| `infrastructure/` | Docker, nginx, deploy scripts |

## Branches

| Branch | Use |
| --- | --- |
| `main` | Stable / demo-ready default |
| `develop` | Integration branch for active work |

- Create feature branches from `develop`: `feature/<short-name>`, `fix/<short-name>`, `docs/<short-name>`.
- Open pull requests into `develop`.
- Promote `develop` → `main` when a milestone is ready.

## Local setup

See the root [README.md](README.md). Short version:

1. Copy `.env.example` files (never commit real `.env` secrets).
2. Use **either** Laravel Sail (`backend/`) **or** root `compose.yaml` for Postgres/Redis — not both on the same ports.
3. Install each service’s dependencies (`composer`, `npm`, `uv sync`).

## Rules that matter

- The **frontend calls Laravel only**. Do not call `ai-service` from the browser.
- Channel webhooks enter through Laravel.
- Permission filters happen **before** retrieval; Laravel revalidates citations.
- Do not add unofficial WhatsApp Web automation.
- Prefer small, reviewable PRs aligned to the current implementation-plan phase.
- Update [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]` for user-visible or structural changes.

## Commits and PRs

- Prefer clear commits focused on one concern (why over what).
- PR description should say: what changed, how to test, and which PRD/plan section it advances.
- Do not commit `vendor/`, `node_modules/`, `.venv/`, or secret-bearing env files.

## Questions

If something in the PRD and the code disagree, the PRD wins for product intent; open a short note in `docs/decisions/` when you intentionally change an engineering choice.
