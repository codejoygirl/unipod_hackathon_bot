# Implementation Plan

**Source of truth for product scope:** [prd.md](prd.md) (§8 scope, §39 acceptance, §40 priorities, §41 team).  
**Engineering invariants:** [../AGENTS.md](../AGENTS.md).  
**Now:** Phase 2 knowledge/RAG foundation complete. Next: Phase 3 member experience.

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

- [x] Sanctum cookie auth for SPA; CSRF; CORS allowlist (`FRONTEND_URL`)
- [x] Tenancy: tenants, communities, groups, memberships
- [x] Roles, policies, gates; tenant/community scopes (`BelongsToTenant`)
- [x] Core foundation models (Tenant, Community, Group, Membership, AuditLog)
- [x] Audit logging foundation (`AuditLogger`)
- [x] Private local storage abstraction (`PrivateStorage` / `LocalPrivateStorage`)
- [x] `/api/v1` versioning + Scramble OpenAPI (`/docs/api`)
- [x] Named queues config (`config/zak.php`)
- [x] `app` / `rag` schema ownership decision (`docs/decisions/001-database-schemas.md`)
- [x] Tenant-isolation feature tests (`tests/Feature/Api/V1/*`)

**Exit criteria:** Authenticated users cannot read another tenant’s data in API tests. OpenAPI generates successfully.

---

## Phase 2 — Knowledge and RAG

Maps to: PRD §40 Priority 2. Owners: Members 1–2.

- [x] Source upload + metadata (Laravel `knowledge_sources`)
- [x] Ingestion pipeline via AI `/ingestion/sync` on publish
- [x] Knowledge lifecycle: Draft → PendingReview → Published / Rejected (+ superseded/archived enums)
- [x] Authority ranking tiers (shared enum with AI)
- [x] Hybrid FTS + pgvector; rank fusion; rerank (AI service)
- [x] Answer statuses: VERIFIED / POSSIBLE / CONFLICT / INSUFFICIENT_EVIDENCE / UNKNOWN / BLOCKED
- [x] Citations + Laravel citation revalidation (`CitationRevalidator`)
- [x] Provider abstractions + HMAC Laravel → AI (`AiServiceClient`)
- [x] WhatsApp export path → draft review workflow
- [x] Evaluation dataset skeleton (`tests/evaluation/`)
- [x] Multilingual query expansion wired into hybrid retrieval
- [x] Community isolation on ask + AI predicate tests

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
7. No unofficial WhatsApp Web automation in **production** (PRD §8.2 / §10.3)

**Exit criteria:** Same permission rules for web, WhatsApp, and Slack questions.

### Phase 4Z — WhatsApp via Zavu (BSP)

Official WhatsApp Business Platform through [Zavu](https://www.zavu.dev). Laravel remains the agent; Zavu is transport only.

| Slice | Deliverable | Exit check |
| --- | --- | --- |
| 4Z.0 | `WHATSAPP_ZAVU` flag (default off) + config | 404 when disabled |
| 4Z.1 | Webhook `POST /api/v1/webhooks/whatsapp-zavu` + `X-Zavu-Signature` | Invalid sig → 401 |
| 4Z.2 | Inbound → `WhatsAppZavuAdapter` → AI ask | Cited reply sent via Zavu API |
| 4Z.3 | Signed `JOIN-{token}` + optional `wa.me` link | Identity resolves to community |
| 4Z.4 | SHARE/EXPORT → knowledge draft | Draft in review queue |

Spikes (`WHATSAPP_WEB_SPIKE`, `TELEGRAM_SPIKE`) remain available and independently env-gated for hackathon/dev.

---

## Phase 4W — WhatsApp Web automation spike (DEV / hackathon ONLY)

Parallel to Phase 4 Cloud API. **Never** the sole production channel. Unofficial WA Web session (whatsapp-web.js / Chromium). ToS / ban / session-break risk.

| Slice | Deliverable | Exit check |
| --- | --- | --- |
| 4W.0 | Spike package + `WHATSAPP_WEB_SPIKE` (default off) | No boot / 404 when disabled |
| 4W.1 | Session bridge (QR, persist, reconnect) | QR once → reconnect without QR |
| 4W.2 | Inbound → `InboundMessage` + Laravel webhook | Message handled by spike adapter |
| 4W.3 | `JOIN-{token}` → community link | Identity resolves to community |
| 4W.4 | Ask → AI grounded answer + citation revalidation | Cited reply text returned |
| 4W.5 | `EXPORT` → knowledge draft | Draft in review queue |
| 4W.6 | Guardrails: flag, README banner, no Sail/prod compose | Risk explicit in docs/config |

Layout: `infrastructure/whatsapp-web-spike/` (sidecar) → `POST /api/v1/internal/whatsapp-web-spike/*` → `WhatsAppWebSpikeAdapter`. See [spike README](../infrastructure/whatsapp-web-spike/README.md).

**Exit criteria:** Linked session → private inbound → Laravel ask → cited reply → optional EXPORT draft, with flag off by default and no production compose wiring.

### 4W.7+ — Group listen + admin access (env)

| Slice | Deliverable | Exit check |
| --- | --- | --- |
| 4W.7 | `ChannelListenGate` + `ChannelCommandAccess` | Unit tests for private/group/mention/command |
| 4W.8 | Env: `WHATSAPP_WEB_SPIKE_ADMIN_PHONES`, `BOT_ALIASES`, `GROUP_LISTEN` | Multi-admin + multi-alias |
| 4W.9 | `/share` member vs `/export` admin-only; drop EXPORT≡SHARE | Non-admin `/export` denied |
| 4W.10 | Admin action idempotency (`resolved_at` / `resolved_by`) | Second `/approve` → polite already-handled |
| 4W.11 | Sidecar `chat_type` / group author; Laravel silence when gated | Group chatter → null reply |

Telegram stays always-listen. Zavu out of scope for this slice.

---

## Phase 4T — Telegram Bot spike (DEV / hackathon ONLY)

Parallel to Phase 4. Official Telegram Bot API sidecar. Align with production channel adapters later (identity linking, signed webhooks, retries).

| Slice | Deliverable | Exit check |
| --- | --- | --- |
| 4T.0 | Spike package + `TELEGRAM_SPIKE` (default off) | 404 when disabled |
| 4T.1 | Bot polling worker (`infrastructure/telegram-spike/`) | Bot receives DM |
| 4T.2 | Inbound → `InboundMessage` + Laravel webhook | Message handled by adapter |
| 4T.3 | `JOIN-{token}` → community link | Identity resolves to community |
| 4T.4 | Ask → grounded answer + citation revalidation | Cited reply in Telegram |
| 4T.5 | `EXPORT` → knowledge draft | Draft in review queue |
| 4T.6 | Guardrails: flag, README, no Sail/prod compose | Risk/scope explicit |

Layout: `infrastructure/telegram-spike/bot.py` → `POST /api/v1/internal/telegram-spike/*` → `TelegramSpikeAdapter`. See [spike README](../infrastructure/telegram-spike/README.md).

**Exit criteria:** Bot DM → Laravel ask → cited reply → optional EXPORT draft; flag off by default.

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

## Active slices (channels + temporal knowledge)

| Slice | Status | Notes |
| --- | --- | --- |
| Dual WhatsApp reach (`GET /api/v1/public/reach`, `zak_whatsapp.php`, WA outbound `*bold*`) | Done | Set distinct `WHATSAPP_ZAVU_PHONE` vs `WHATSAPP_WEB_SPIKE_BOT_NUMBER`; `ZAK_WHATSAPP_PRIMARY` for web chip |
| Session turn timestamps in knowledge query envelope | Done | `ChannelConversationService::remember()` + `buildKnowledgeQuery()` |
| Ingest-time `content_occurred_*` + retrieval `message_at` XML | Done (new imports) | Re-import chat exports after purge for existing index |
| Legacy `EXPORT`/`IMPORT` routing (no leading slash) | Done | `ChannelListenGate::startsWithImportOrExport()` |
| Full Zavu `MemberChannelPipeline` parity (voice/image, admin desk, group listen) | Done (Zavu) | `MemberChannelPipeline` + voice/image normalizers; groups N/A on Cloud DM path |
| Optional `/conversation/temporal-plan` preflight | Done | Classify `needs_temporal_resolution` + preflight before grounded ask |
| Purge + re-import UniPods `_chat.txt` | Ops | `zak:purge-knowledge --community=…` then `zak:import-knowledge-chats --path=…` |

## Immediate next step

Start **Phase 3 (Member experience)**: chat UI, citations drawer, community selector, TanStack Query client from OpenAPI.
