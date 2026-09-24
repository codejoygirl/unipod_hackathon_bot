# Purge knowledge base (Laravel + AI embeddings)

Wipe drafts, published docs, Drive assets, AI chunks, and vector embeddings so you can re-import cleanly.

## What gets deleted

| Layer | Tables / data |
| --- | --- |
| Laravel | `knowledge_documents` (drafts, published, `/asset` files) |
| AI service | `knowledge_sources`, `knowledge_source_versions`, `knowledge_chunks` (includes embeddings + lexical `tsv`) |
| Optional | `glossary_entries` (tenant / `--all` only; skipped for community-only) |

Members, communities, chats, and escalation cache are **not** touched.

## Prerequisites

- AI service running (unless you use `--laravel-only`)
- From the **backend** app directory (local Sail or VPS path below)
- Know the community ULID (`TELEGRAM_SPIKE_DEFAULT_COMMUNITY_ID` / `WHATSAPP_*_DEFAULT_COMMUNITY_ID` in `.env`, or `/knowledge` in admin DM)

## Local (Windows / Sail)

```powershell
cd C:\Users\USER\Desktop\Zak\backend

# One community (recommended)
php artisan zak:purge-knowledge --community=01m2y2ccq2p1f32y0hx4hp9pvz --force

# Whole tenant
php artisan zak:purge-knowledge --tenant=01… --force

# Everything (dangerous)
php artisan zak:purge-knowledge --all --force
```

If Sail wraps PHP:

```powershell
.\vendor\bin\sail artisan zak:purge-knowledge --community=01… --force
```

## Production (zak-app.xerotek.io)

SSH as `zak-app`, then:

```bash
cd /home/zak-app/htdocs/zak-app.xerotek.io/backend

# Show community id from env if needed
grep COMMUNITY_ID .env

# Purge that community's knowledge + embeddings
php artisan zak:purge-knowledge --community=PASTE_COMMUNITY_ULID --force
```

Full wipe (all communities — confirm the id first):

```bash
php artisan zak:purge-knowledge --all --force
```

### Useful flags

| Flag | Meaning |
| --- | --- |
| `--force` | Skip interactive “Continue?” prompt (required for non-interactive SSH) |
| `--laravel-only` | Delete Laravel rows only; leave AI index |
| `--ai-only` | Call AI `/ingestion/purge` only (retry if Laravel already wiped) |
| `--keep-glossary` | Do not delete `glossary_entries` on tenant/all |

### If AI purge fails after Laravel delete

```bash
php artisan zak:purge-knowledge --community=PASTE_COMMUNITY_ULID --ai-only --force
```

Check AI is up:

```bash
curl -sS http://127.0.0.1:8001/health
# or whatever AI_SERVICE_URL is from backend/.env
```

## After purge — re-seed knowledge

Admin DM with Zak:

1. Chat export: `/import` → paste **or** attach file (PDF, image, video, audio, .txt) with optional caption → `/publish latest`
2. Drive files: `/asset handbook Title https://drive.google.com/file/d/…`
3. Staged chats: `php artisan zak:import-knowledge-chats` (reads `storage/app/knowledge-import/`, mints token, publishes)
4. Or API: `POST /api/v1/knowledge-sources/import` then `submit-review` + `publish`
5. Optional demo seed: `php artisan zak:seed-assistant-demo`

List what is left:

```
/knowledge
/knowledge drafts
/knowledge published
/knowledge assets
```

## How it works

`zak:purge-knowledge` deletes matching `knowledge_documents`, then HMAC-calls AI `POST /ingestion/purge`, which deletes sources/versions/chunks (embeddings) for that scope.
