# Frontend (Next.js)

Next.js TypeScript PWA for Community Assistant: member portal, administrator dashboard, and platform UI.

See [root README](../README.md), [AGENTS.md](../AGENTS.md), [PRD](../docs/prd.md), and [implementation plan](../docs/implementation-plan.md).

## Stack

| Piece | Choice |
| --- | --- |
| Framework | Next.js (App Router) |
| Language | TypeScript |
| Styling | Tailwind CSS |
| Components | shadcn/ui (Phase 4) |
| Server state | TanStack Query (Phase 4) |
| Forms | React Hook Form + Zod (Phase 4) |
| E2E | Playwright (Phase 4+) |

## Prerequisites

- Node.js 20+ (22 recommended)
- Backend reachable (Sail or `php artisan serve`) after Phase 1 auth

## Setup

**Linux / macOS**

```bash
cp .env.example .env.local
npm install
npm run dev
```

**Windows (PowerShell)**

```powershell
Copy-Item .env.example .env.local
npm install
npm run dev
```

App: http://localhost:3000

Never put Laravel, AI, or database secrets in `NEXT_PUBLIC_*` variables.

## Scripts

| Command | Purpose |
| --- | --- |
| `npm run dev` | Next.js dev server |
| `npm run build` | Production build |
| `npm run start` | Serve production build |
| `npm run lint` | ESLint |

## Layout

Scaffold matches the agreed App Router structure:

```text
src/
  app/
    (auth)/
    (dashboard)/{communities,knowledge,conversations,meetings,
                 integrations,escalations,analytics,settings}/
  components/{ui,chat,knowledge,meetings,admin-copilot}/
  features/{auth,communities,chat,sources,integrations,escalations}/
  lib/{api,auth,query,validation,i18n}/
  hooks/ types/ tests/
```

## Conventions

- Call Laravel only; never call `ai-service` from the browser
- URL search params for filters/tabs; TanStack Query for server data
- Client components only where interactivity requires them
