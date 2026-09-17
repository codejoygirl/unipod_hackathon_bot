# Implementation Plan

**Source of truth for product scope:** [prd.md](prd.md) (§8 scope, §39 acceptance, §40 priorities, §41 team).  
**Engineering invariants:** [../AGENTS.md](../AGENTS.md).  
**Now:** Repository scaffold only. No product features yet.

Phases below map 1:1 to PRD §40 delivery priorities.

---

## Phase 0 — Repository foundation (done)

Maps to: PRD §25–§28 structure, §32 Sail/Compose split.

- [x] Monorepo: `backend/`, `frontend/`, `ai-service/`, `infrastructure/`, `docs/`
- [x] Laravel + Sail (`backend/compose.yaml`, pgvector image)
- [x] Next.js App Router + features/components/lib tree
- [x] FastAPI + uv + `uv.lock`; module tree; SQLAlchemy + Alembic
- [x] Root `compose.yaml` (Postgres + Redis)
- [x] Working PRD, this plan, `AGENTS.md`, `CHANGELOG.md`, service READMEs

**Exit criteria:** Services install and start; DB/Redis healthy via Sail **or** root Compose (not both on the same ports).

---

## Phase 1 — Foundation

Maps to: PRD §40 Priority 1. Owners: Member 1 (+ shared review).

1. Sanctum cookie auth for SPA; CSRF; CORS allowlist
2. Tenancy: tenants, communities, groups, memberships
3. Roles, policies, gates; tenant/community/`group_id` scopes
4. Core models from PRD §34 (as needed for foundation)
5. Audit logging foundation
6. Private local storage abstraction (PRD §33)
7. `/api/v1` versioning + Scramble OpenAPI (`/docs/api`)
8. Named queues + Redis (`high`, `channels`, `ai`, `ingestion`, `meetings`, `notifications`, `default`)
9. `app` / `rag` schema ownership plan (PRD §25.3); restricted DB roles when ready
10. Tenant-isolation feature tests (PRD §37.4)

**Exit criteria:** Authenticated users cannot read another tenant’s data in API tests. OpenAPI generates successfully.

---

## Phase 2 — Knowledge and RAG

Maps to: PRD §40 Priority 2. Owners: Members 1–2.

1. Source upload + metadata (PRD §15)
2. Ingestion pipeline: parse → source-aware chunk → embed → review gate (PRD §18)
3. Knowledge lifecycle: Draft → PendingReview → Published → Superseded/Archived (PRD §16)
4. Authority ranking (PRD §17)
5. Hybrid FTS + pgvector; rank fusion; rerank
6. Answer statuses: `verified`, `possible`, `conflict`, `unknown`, `blocked` (PRD §18.4)
7. Citations + Laravel citation revalidation
8. Provider abstractions: chat, embedding, rerank, transcription, translation (PRD §29)
9. HMAC Laravel → AI; internal `/v1/answers`, `/v1/ingestions` (PRD §30.2)
10. WhatsApp export path into review workflow
11. Evaluation dataset skeleton (PRD §37)

**Exit criteria:** Upload a document; ask via API; cited answer; Community A cannot retrieve Community B chunks; unknown answers escalate cleanly.

---

## Phase 3 — Member experience

Maps to: PRD §40 Priority 3. Owner: Member 4 (+ Member 2 for languages).

1. shadcn/ui, TanStack Query, RHF + Zod
2. Member chat + citations + community selector
3. Catch-up summaries and `UserCursor` / `last_seen_at` (PRD §13.2)
4. Answer feedback
5. Multilingual UI + RTL (Arabic)
6. PWA: installable shell, offline drafts, offline indicators (PRD §12)
7. Polling intervals per PRD §12.3
8. Generate TS client from Laravel OpenAPI

**Exit criteria:** Login, ask in web chat, receive cited answer, request catch-up, install PWA shell.

---

## Phase 4 — Channel integrations

Maps to: PRD §40 Priority 4. Owner: Member 3.

1. Channel adapter contract + capabilities (PRD §9.1)
2. Web adapter
3. WhatsApp Cloud API private assistant + signed `JOIN` onboarding (PRD §10)
4. Slack app: DM, mentions, threads, authorised ingestion (PRD §11)
5. External identity linking
6. Message normalisation + delivery retries
7. No unofficial WhatsApp Web automation (PRD §8.2 / §10.3)

**Exit criteria:** Same permission rules for web, WhatsApp, and Slack questions.

---

## Phase 5 — Administration

Maps to: PRD §40 Priority 5. Owners: Members 4 + 1 (+ 3 for delivery).

1. Admin dashboard sections (PRD §22): overview, communities, knowledge, conversations, integrations, users, analytics, audit
2. Knowledge review / publish / supersede / archive
3. Escalation inbox and admin response → user notify / publish as knowledge (PRD §13.3)
4. Official announcements with versioning and channel delivery (PRD §21)
5. Integration health and credential rotation UI
6. Analytics definitions (PRD §38)

**Exit criteria:** Admin can resolve an escalation, publish knowledge, and publish/supersede an announcement with audit entries.

---

## Phase 6 — Meetings

Maps to: PRD §40 Priority 6. Owner: Member 5 (+ Member 2).

1. Transcript / recording upload
2. Calendar / provider connection where available
3. Recall.ai (or equivalent) fallback — not a custom multi-provider bot (PRD §20.1)
4. Summary, decisions, tasks, owners, deadlines, timestamp citations
5. Consent metadata and retention (PRD §20.3)
6. Approval workflow before official meeting knowledge

**Exit criteria:** One meeting transcript yields summary, decisions, tasks, and citations after approval.

---

## Phase 7 — Product intelligence and hardening

Maps to: PRD §40 Priority 7 + §39 remaining criteria. Owners: All.

1. Probable Q&A detection with governance gates (PRD §14)
2. Knowledge-gap analysis
3. AI Admin Copilot + tool registry + confirmation for high-risk actions (PRD §23)
4. Deterministic product tours and onboarding checklist (PRD §24)
5. Deeper evaluation dashboard (PRD §37–§38)
6. Production Dockerfiles under `infrastructure/docker/`; workers; scheduler; reverse proxy
7. Observability: Pulse, Sentry, OpenTelemetry, correlation IDs (PRD §36)
8. UniPods demo seed (data only; no hard-coded product forks)
9. Full acceptance checklist (PRD §39)

**Exit criteria:** PRD §39 items 1–24 pass; zero known cross-tenant leaks.

---

## Team split (PRD §41)

| Member | Owns |
| --- | --- |
| 1 | Architecture, Laravel, tenancy, auth, storage, queues, audit, deployment, AI HMAC |
| 2 | FastAPI, ingestion, retrieval, multilingual, providers, evaluation |
| 3 | Channel adapters, WhatsApp, Slack, webhooks, notifications, announcement delivery |
| 4 | Next.js PWA, chat, dashboard, polling, RTL, tours, Copilot UI |
| 5 | Meetings, consent, E2E/security/permission tests, demo data, user docs |

---

## Working rules

- Frontend → Laravel only; never call AI service from the browser
- Permission filters before retrieval; Laravel revalidates citations (PRD §4.2, §7, §18.3)
- Sail for local Laravel only; production uses dedicated images (PRD §32)
- Horizon optional later; Laravel Queue is the job system (PRD §31)
- Commit `uv.lock`; separate `.env` per service
- Do not claim equal quality for every language without evaluation (PRD §19.1)

## Immediate next step

Start **Phase 1 (Foundation)**: Sanctum, tenancy, policies, audit, Scramble, storage abstraction, tenant-isolation tests.
