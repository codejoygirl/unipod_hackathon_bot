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
4. From the **repo root**, run `npm install` once so Husky hooks are installed (`prepare` script).

## Commit message format

We follow [Conventional Commits](https://www.conventionalcommits.org/) and enforce them with [commitlint](https://commitlint.js.org/guides/local-setup.html) via [Husky](https://typicode.github.io/husky/) on `commit-msg`.

```text
<type>(optional-scope): <short summary in imperative mood>

[optional body — why, not only what]

[optional footer — Breaking Change / issue refs]
```

### Types

| Type | When |
| --- | --- |
| `feat` | New user-facing capability |
| `fix` | Bug fix |
| `docs` | Documentation only |
| `style` | Formatting; no logic change |
| `refactor` | Code change that is not a fix or feature |
| `perf` | Performance improvement |
| `test` | Adding or fixing tests |
| `build` | Build system or dependencies |
| `ci` | CI configuration |
| `chore` | Maintenance that does not fit above |
| `revert` | Revert a previous commit |

### Recommended scopes

`backend`, `frontend`, `ai-service`, `docs`, `infra`, `deps`, `repo`

### Examples

```text
feat(backend): add tenant-scoped membership policies
fix(frontend): stop chat poll when tab is hidden
docs: align implementation plan with PRD priorities
chore(repo): add husky and commitlint
ci: lint pull request titles with commitlint
```

### Rules

- Header max 100 characters
- Blank line between subject and body
- Do not end the subject with a period
- Use the imperative mood (“add”, not “added”)

Husky blocks non-conforming commits locally. GitHub Actions also validates **PR titles** with the same rules.

## Pull request format

Use the GitHub PR template (`.github/PULL_REQUEST_TEMPLATE.md`). Required sections:

1. **Summary** — what and why (1–3 bullets)
2. **Type of change** — checklist
3. **Plan / PRD** — phase or section links
4. **Test plan** — how reviewers verify
5. **Notes** — breaking changes / follow-ups

### PR title

Same Conventional Commits shape as a commit subject, for example:

```text
chore(repo): add husky, commitlint, and PR template
```

## Rules that matter

- The **frontend calls Laravel only**. Do not call `ai-service` from the browser.
- Channel webhooks enter through Laravel.
- Permission filters happen **before** retrieval; Laravel revalidates citations.
- Do not add unofficial WhatsApp Web automation.
- Prefer small, reviewable PRs aligned to the current implementation-plan phase.
- Update [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]` for user-visible or structural changes.
- Do not commit `vendor/`, `node_modules/`, `.venv/`, or secret-bearing env files.

## Questions

If something in the PRD and the code disagree, the PRD wins for product intent; open a short note in `docs/decisions/` when you intentionally change an engineering choice.
