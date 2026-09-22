# Contabo + CloudPanel (interim production)

This repo does **not** yet have finished Phase 7 production Docker for Laravel or the Next.js app (`infrastructure/docker/`). Until that lands, the practical Contabo path is:

| Piece | Where |
| --- | --- |
| Domain + SSL + reverse proxy | CloudPanel |
| Laravel API (`backend/`) | CloudPanel PHP 8.3 site |
| Next.js frontend (`frontend/`) | CloudPanel **Node.js** site (`next start`) |
| Postgres + **pgvector** | Docker (not CloudPanel MySQL) |
| Redis | Docker |
| AI service | Docker (`ai-service/Dockerfile`) |
| `queue:work` + `schedule:run` | Supervisor / systemd + cron |
| Telegram spike | systemd (optional) |
| Production WhatsApp | **Zavu / Cloud API** webhook → Laravel |

**Do not** put `infrastructure/whatsapp-web-spike` on Contabo. WhatsApp Web automation is ToS/ban risk and is gated `WHATSAPP_WEB_SPIKE` for local/dev only.

Substitute your hostnames everywhere below:

| Placeholder | Example |
| --- | --- |
| `api.yourdomain.com` | Laravel API |
| `app.yourdomain.com` | Next.js PWA |
| `YOUR_SITE_USER` | CloudPanel site user for the API |

### This VPS (`vmi3072365` / `zak-app.xerotek.io`)

Repo is already cloned. Use these values:

| Item | Value |
| --- | --- |
| SSH user | `zak-app` (site) and `root` (Docker / systemd) |
| Checkout | `/home/zak-app/htdocs/zak-app.xerotek.io` |
| Public site (today) | `https://zak-app.xerotek.io` |
| Recommended API host | `https://api.zak-app.xerotek.io` (add DNS + CloudPanel PHP site) |

**Do not leave CloudPanel’s document root on the repo root.** That would publish `.env`, `.git`, and `backend/`. Point the PHP site at `backend/public` only.

Step-by-step runbook for this clone: follow the numbered commands in chat, or start at [§4](#4-postgres--redis--ai-service-docker) after CloudPanel SSL + document root.

---

## 0. Invariants (do not skip)

- Browser and channels call **Laravel only**. The AI service is private (loopback).
- Laravel and the AI service must share the same `INTERNAL_HMAC_SECRET` (not a made-up `AI_SERVICE_HMAC_SECRET`).
- Postgres must be **PostgreSQL + pgvector**. CloudPanel’s MySQL/MariaDB is only for CloudPanel itself.
- Bind Docker Postgres, Redis, and the AI service to `127.0.0.1`. Do not publish `5432`, `6379`, or `8001` on the public interface.
- Keep knowledge separated by **community** + `JOIN-…` tokens (same bot, different communities).
- Never put secrets in `NEXT_PUBLIC_*`.

---

## 1. Contabo VPS

1. Create a VPS: **Ubuntu 22.04 or 24.04**, **≥4 GB RAM** recommended (PHP + Node + Postgres + Redis + AI + embeddings).
2. Point DNS **A records** to the VPS IP:
   - `api.yourdomain.com`
   - `app.yourdomain.com`
3. SSH in as root.
4. Open firewall: `22` (SSH), `80`, `443`, `8443` (CloudPanel). Leave database/AI ports closed.

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 8443/tcp
ufw enable
```

---

## 2. Install CloudPanel

Use a **fresh** Ubuntu image. Copy the current installer (with checksum) from the [official CloudPanel “other / dedicated server” docs](https://www.cloudpanel.io/docs/v2/getting-started/other/) — the SHA256 changes between releases.

```bash
apt update && apt -y upgrade && apt -y install curl wget sudo
```

Then run the official `install.sh` + `sha256sum -c` command from that page. Zak does **not** use CloudPanel’s MySQL/MariaDB; any `DB_ENGINE` they offer is fine.

1. Open `https://YOUR_SERVER_IP:8443`
2. Create the CloudPanel admin user
3. Install Docker if the installer did not:

```bash
curl -fsSL https://get.docker.com | sh
```

---

## 3. Create sites in CloudPanel

### A. API site (Laravel)

- Sites → **Add Site** → **Create a PHP Site**
- Domain: `api.yourdomain.com`
- PHP: **8.3** (required; `backend/composer.json` is `^8.3`)
- Enable SSL (Let’s Encrypt) after DNS has propagated

Enable PHP extensions for this site (CloudPanel → site → PHP): **pgsql / pdo_pgsql**, **redis** (`phpredis`), plus the usual `mbstring`, `xml`, `curl`, `zip`, `gd`, `intl`, `bcmath`.

Laravel uses `REDIS_CLIENT=phpredis`. Without `php-redis`, the queue/cache will fail.

### B. App site (frontend)

- Add Site → **Node.js** (not Static HTML)
- Domain: `app.yourdomain.com`
- Node **20+**
- SSL on

This Next app has **no** `output: 'export'` in `frontend/next.config.ts`. A static copy of `.next` will not work. CloudPanel must run `npm start` (`next start`) behind the proxy.

CloudPanel creates a site user and a web root (often `/home/YOUR_SITE_USER/htdocs/…`).

---

## 4. Postgres + Redis + AI service (Docker)

Put all three on one Compose network so the AI container can reach Postgres by **service name**. From inside a container, `127.0.0.1` is the container itself — not the VPS.

```bash
mkdir -p /opt/zak && cd /opt/zak
```

Create `/opt/zak/docker-compose.yml`:

```yaml
services:
  postgres:
    image: pgvector/pgvector:pg16
    restart: unless-stopped
    environment:
      POSTGRES_DB: zak
      POSTGRES_USER: zak
      POSTGRES_PASSWORD: "STRONG_PASSWORD_HERE"
    ports:
      - "127.0.0.1:5432:5432"
    volumes:
      - zak_pg:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U zak -d zak"]
      interval: 5s
      timeout: 5s
      retries: 10

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    ports:
      - "127.0.0.1:6379:6379"
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 10

  ai:
    image: zak-ai:latest
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
    env_file:
      - /opt/zak/ai.env
    environment:
      ENVIRONMENT: production
      DEBUG: "false"
      DATABASE_URL: postgresql+psycopg://zak:STRONG_PASSWORD_HERE@postgres:5432/zak
    ports:
      - "127.0.0.1:8001:8000"

volumes:
  zak_pg:
```

Do **not** use `ai-service/docker-compose.yml` as-is on Contabo: it publishes `5432` and `8000` on all interfaces and does not include Redis.

### Clone the repo and build the AI image

```bash
cd /opt/zak
git clone YOUR_REPO_URL app
cd app/ai-service
docker build -t zak-ai .
```

Create `/opt/zak/ai.env` (never commit this file):

```env
INTERNAL_HMAC_SECRET=long-random-secret-min-32-bytes
HMAC_TIMESTAMP_TOLERANCE_SECONDS=300
AI_TIMEZONE=Africa/Lagos

OPENAI_API_KEY=
GEMINI_API_KEY=
LLM_PROVIDER=openai
EMBEDDING_PROVIDER=openai
TRANSCRIPTION_PROVIDER=openai
VISION_PROVIDER=openai
EMBEDDING_MODEL_NAME=text-embedding-3-small
EMBEDDING_DIMENSION=1536
CHAT_MODEL_NAME=gpt-4o-mini
```

`INTERNAL_HMAC_SECRET` must match `backend/.env` exactly. Generate one:

```bash
openssl rand -hex 32
```

Start infra:

```bash
cd /opt/zak
docker compose up -d
```

### RAG schema (Alembic)

The AI `Dockerfile` does not copy `alembic.ini` / `migrations/` and does not migrate on boot. Run Alembic from the checkout against **host** Postgres (`127.0.0.1`):

```bash
# on the VPS
apt-get install -y python3.12-venv
curl -LsSf https://astral.sh/uv/install.sh | sh
cd /opt/zak/app/ai-service
# DATABASE_URL here uses 127.0.0.1 because this runs on the host, not in the container
DATABASE_URL=postgresql+psycopg://zak:STRONG_PASSWORD_HERE@127.0.0.1:5432/zak \
  uv run alembic upgrade head
```

Check the AI service (loopback only):

```bash
curl -sS http://127.0.0.1:8001/health/live
curl -sS http://127.0.0.1:8001/health/ready
```

Do not proxy `/docs` or port `8001` through CloudPanel.

---

## 5. Deploy Laravel (`backend/`)

As the CloudPanel site user (or root, then `chown` to that user):

```bash
# Option A: deploy the API tree into the site htdocs
cd /home/YOUR_SITE_USER/htdocs/api.yourdomain.com
# clone or rsync the repo so `backend/` is here, then:
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Option B (often cleaner): keep one checkout at `/opt/zak/app` and point the CloudPanel document root at `/opt/zak/app/backend/public`.

### `.env` (minimum)

Copy from `backend/.env.example` and change at least:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Africa/Lagos
FRONTEND_URL=https://app.yourdomain.com
ZAK_WEB_CHAT_URL="${FRONTEND_URL}"

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=zak
DB_USERNAME=zak
DB_PASSWORD=STRONG_PASSWORD_HERE

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis

SESSION_DRIVER=database
SESSION_DOMAIN=yourdomain.com
SANCTUM_STATEFUL_DOMAINS=app.yourdomain.com,api.yourdomain.com

AI_SERVICE_URL=http://127.0.0.1:8001
INTERNAL_HMAC_SECRET=same-value-as-ai.env

# Leave the WhatsApp Web spike off
WHATSAPP_WEB_SPIKE=false

# Telegram (only if you run the spike worker)
TELEGRAM_SPIKE=true
TELEGRAM_SPIKE_PROCESS_SYNC=false
TELEGRAM_SPIKE_SECRET=long-shared-secret
TELEGRAM_SPIKE_BOT_TOKEN=same-token-as-telegram-spike
TELEGRAM_SPIKE_ADMIN_CHAT_ID=
TELEGRAM_SPIKE_DEFAULT_COMMUNITY_ID=
TELEGRAM_SPIKE_FORMATTING=plain

# Production WhatsApp (Zavu / Cloud API) — independent of spikes
WHATSAPP_ZAVU=false
WHATSAPP_ZAVU_PROCESS_SYNC=false
WHATSAPP_ZAVU_API_KEY=
WHATSAPP_ZAVU_WEBHOOK_SECRET=
```

`FRONTEND_URL` is required for CORS (`backend/config/cors.php`). If it still points at `localhost:3000`, the browser app on `app.yourdomain.com` cannot call the API.

Then:

```bash
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Optional demo tenant/community (prints a Sanctum token):

```bash
php artisan zak:seed-assistant-demo
```

Copy `TELEGRAM_SPIKE_DEFAULT_COMMUNITY_ID` / Zavu default community from that output if you use the spikes.

### Document root + permissions

CloudPanel → site → Vhost / **Document Root** → Laravel’s `public/` folder (not the repo root).

```bash
chown -R YOUR_SITE_USER:YOUR_SITE_USER /path/to/backend
chmod -R ug+rwx /path/to/backend/storage /path/to/backend/bootstrap/cache
```

---

## 6. Queue worker + scheduler

CloudPanel → site → **Supervisor** (or a systemd unit). Inbound Telegram/Zavu jobs use the `channels` queue (`TELEGRAM_SPIKE_PROCESS_SYNC=false` / `WHATSAPP_ZAVU_PROCESS_SYNC=false`).

**Worker**

```bash
php /path/to/backend/artisan queue:work redis \
  --queue=high,channels,ai,ingestion,meetings,notifications,default \
  --sleep=1 --tries=3 --timeout=120
```

**Scheduler** (cron every minute, as the site user)

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php` currently has no scheduled tasks. Keep the cron anyway — it is the Laravel production default and will pick up jobs when they are added.

---

## 7. Frontend

On the Node site (or in `/opt/zak/app/frontend`):

```bash
cd frontend
cp .env.example .env.local
```

`.env.local`:

```env
NEXT_PUBLIC_APP_NAME=Zak
NEXT_PUBLIC_API_URL=https://api.yourdomain.com
```

```bash
npm ci
npm run build
```

CloudPanel Node site:

- Application root: `frontend/`
- Start command: `npm start` (or `npx next start -p $PORT` if CloudPanel injects `PORT`)
- After each deploy: `npm ci && npm run build`, then restart the Node app

---

## 8. Telegram bot worker (optional)

Still labeled a **dev/hackathon spike** in `infrastructure/telegram-spike/README.md` (Phase 4 product adapters are not done). It is the practical Telegram path today: Laravel stays the brain; the worker only does Bot I/O.

Do **not** put this under the public web root.

```bash
cd /opt/zak/app/infrastructure/telegram-spike
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
```

`.env`:

```env
LARAVEL_BASE_URL=https://api.yourdomain.com
SPIKE_SECRET=same-as-TELEGRAM_SPIKE_SECRET
TELEGRAM_BOT_TOKEN=same-as-TELEGRAM_SPIKE_BOT_TOKEN
TELEGRAM_PARSE_MODE=plain
ZAK_SHOW_WEB_CHAT=true
ZAK_WEB_CHAT_URL=https://app.yourdomain.com
```

Use the **same** BotFather token in Laravel `TELEGRAM_SPIKE_BOT_TOKEN`. A mismatch means members talk to one bot and escalations go out from another (or nowhere).

Example systemd unit `/etc/systemd/system/zak-telegram.service`:

```ini
[Unit]
Description=Zak Telegram spike
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/zak/app/infrastructure/telegram-spike
EnvironmentFile=/opt/zak/app/infrastructure/telegram-spike/.env
ExecStart=/opt/zak/app/infrastructure/telegram-spike/.venv/bin/python bot.py
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now zak-telegram
```

---

## 9. Production WhatsApp (Zavu)

Keep `WHATSAPP_WEB_SPIKE=false`. For official WhatsApp:

1. Set `WHATSAPP_ZAVU=true` and the Zavu keys in `backend/.env`.
2. Point the Zavu webhook at:

   `https://api.yourdomain.com/api/v1/webhooks/whatsapp-zavu`

3. Keep `WHATSAPP_ZAVU_PROCESS_SYNC=false` and run the queue worker from §6.

---

## 10. Smoke check

1. `https://api.yourdomain.com/up` — Laravel framework health
2. `https://api.yourdomain.com/api/v1/health/live` — `{ "status": "ok", "service": "zak-backend" }`
3. `curl -sS http://127.0.0.1:8001/health/live` and `/health/ready` on the VPS
4. `https://app.yourdomain.com` loads
5. Register / login / ask a question on web (CORS + Sanctum domains)
6. If Telegram is on: `/help` replies in the **same** bot; `queue:work` is running
7. `php artisan queue:failed` is empty
8. Confirm `WHATSAPP_WEB_SPIKE` is still `false`

---

## Limits and follow-ups

- WhatsApp Web spike → **local/dev only**.
- Production WhatsApp → **Zavu / Cloud API**.
- Telegram spike is operational, not the finished Phase 4 adapter.
- Phase 7 still needs dedicated production images + workers + reverse proxy under `infrastructure/docker/`.
- Back up the Docker volume `zak_pg` (and `backend/storage`) on a schedule.
- After `php artisan config:cache`, `.env` edits do nothing until you cache again.
- Set `APP_TIMEZONE` (and AI `AI_TIMEZONE`) to `Africa/Lagos` or your zone so admin timestamps match local time.
