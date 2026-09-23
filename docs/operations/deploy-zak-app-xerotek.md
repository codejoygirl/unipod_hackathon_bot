# Deploy Zak on this VPS (follow in order)

**Server:** `vmi3072365`  
**Site user:** `zak-app`  
**Repo (already cloned):** `/home/zak-app/htdocs/zak-app.xerotek.io`  
**Public URL (everything):** `https://zak-app.xerotek.io`

You need **two SSH logins**:

1. `zak-app` — Laravel, Telegram files  
2. `root` — Docker (and the one-line `pm2 startup` command if PM2 prints one)  

Do **not** skip steps. Finish a step before the next.

Channels on this server:

- Telegram spike = **ON**
- WhatsApp via **Zavu** (official Cloud API) = **ON**
- WhatsApp Web spike (Chrome / QR) = **OFF** (ban risk on a VPS)

---

## Step 1 — CloudPanel (browser)

1. Open CloudPanel → site **zak-app.xerotek.io**.
2. PHP version: **8.3**.
3. Enable extensions: **pgsql**, **redis**, plus mbstring, xml, curl, zip, gd, intl, bcmath.
4. SSL: Let’s Encrypt for `zak-app.xerotek.io`.
5. **Document Root** (required):

   `/home/zak-app/htdocs/zak-app.xerotek.io/backend/public`

   If this stays on the repo folder, `.env` and `.git` are public.

---

## Step 2 — Root: install Docker

SSH as **root**:

```bash
docker -v || curl -fsSL https://get.docker.com | sh
docker compose version
```

---

## Step 3 — Root: create passwords

SSH as **root**. Copy the three lines of output somewhere safe:

```bash
echo "HMAC=$(openssl rand -hex 32)"
echo "PGPASS=$(openssl rand -hex 24)"
echo "TGSECRET=$(openssl rand -hex 24)"
```

You will paste:

- `HMAC` into `/opt/zak/ai.env` **and** `backend/.env` (`INTERNAL_HMAC_SECRET`)
- `PGPASS` into Docker Compose **and** `backend/.env` (`DB_PASSWORD`)
- `TGSECRET` into `backend/.env` **and** Telegram `.env` (`SPIKE_SECRET`)

Also have ready:

- OpenAI (or Gemini) API key  
- Telegram BotFather token  
- Zavu API key + webhook secret (Zavu dashboard)

---

## Step 4 — Root: Docker Compose

```bash
mkdir -p /opt/zak
nano /opt/zak/docker-compose.yml
```

Paste this. Replace **both** `PASTE_PGPASS` with the same `PGPASS` from Step 3:

```yaml
services:
  postgres:
    image: pgvector/pgvector:pg16
    restart: unless-stopped
    environment:
      POSTGRES_DB: zak
      POSTGRES_USER: zak
      POSTGRES_PASSWORD: "PASTE_PGPASS"
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
      DATABASE_URL: postgresql+psycopg://zak:PASTE_PGPASS@postgres:5432/zak
    ports:
      - "127.0.0.1:8001:8000"

volumes:
  zak_pg:
```

Save. Then:

```bash
nano /opt/zak/ai.env
```

Paste (replace HMAC and your LLM key):

```env
INTERNAL_HMAC_SECRET=PASTE_HMAC
HMAC_TIMESTAMP_TOLERANCE_SECONDS=300
AI_TIMEZONE=Africa/Lagos
OPENAI_API_KEY=PASTE_OPENAI_KEY
LLM_PROVIDER=openai
EMBEDDING_PROVIDER=openai
TRANSCRIPTION_PROVIDER=openai
VISION_PROVIDER=openai
EMBEDDING_MODEL_NAME=text-embedding-3-small
EMBEDDING_DIMENSION=1536
CHAT_MODEL_NAME=gpt-4o-mini
```

```bash
chmod 600 /opt/zak/ai.env /opt/zak/docker-compose.yml
```

---

## Step 5 — Root: build AI and start containers

```bash
cd /home/zak-app/htdocs/zak-app.xerotek.io/ai-service
docker build -t zak-ai .

cd /opt/zak
docker compose up -d
docker compose ps
```

All three should be `running` (postgres, redis, ai).

Install uv once, then migrate the RAG schema:

```bash
curl -LsSf https://astral.sh/uv/install.sh | sh
source "$HOME/.local/bin/env"

cd /home/zak-app/htdocs/zak-app.xerotek.io/ai-service
DATABASE_URL=postgresql+psycopg://zak:PASTE_PGPASS@127.0.0.1:5432/zak \
  uv run alembic upgrade head

curl -sS http://127.0.0.1:8001/health/live
curl -sS http://127.0.0.1:8001/health/ready
```

You must see live/ready JSON. If ready fails, stop and fix Postgres/password before Laravel.

---

## Step 6 — `zak-app`: Laravel install

SSH as **zak-app**:

```bash
cd /home/zak-app/htdocs/zak-app.xerotek.io/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
nano .env
```

Set these keys (leave other lines unless you know you need them).  
Replace `PASTE_*` with Step 3 values.

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://zak-app.xerotek.io
APP_TIMEZONE=Africa/Lagos
FRONTEND_URL=https://zak-app.xerotek.io
ZAK_WEB_CHAT_URL=https://zak-app.xerotek.io

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=zak
DB_USERNAME=zak
DB_PASSWORD=PASTE_PGPASS

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis

SESSION_DRIVER=database
SESSION_DOMAIN=zak-app.xerotek.io
SANCTUM_STATEFUL_DOMAINS=zak-app.xerotek.io

AI_SERVICE_URL=http://127.0.0.1:8001
INTERNAL_HMAC_SECRET=PASTE_HMAC

WHATSAPP_WEB_SPIKE=false

TELEGRAM_SPIKE=true
TELEGRAM_SPIKE_PROCESS_SYNC=false
TELEGRAM_SPIKE_SECRET=PASTE_TGSECRET
TELEGRAM_SPIKE_BOT_TOKEN=PASTE_BOTFATHER_TOKEN
TELEGRAM_SPIKE_DEFAULT_USER_EMAIL=demo@zak.test
TELEGRAM_SPIKE_DEFAULT_COMMUNITY_ID=
TELEGRAM_SPIKE_ADMIN_CHAT_ID=
TELEGRAM_SPIKE_FORMATTING=plain

WHATSAPP_ZAVU=true
WHATSAPP_ZAVU_PROCESS_SYNC=false
WHATSAPP_ZAVU_API_KEY=PASTE_ZAVU_API_KEY
WHATSAPP_ZAVU_WEBHOOK_SECRET=PASTE_ZAVU_WEBHOOK_SECRET
WHATSAPP_ZAVU_ADMIN_SECRET=PASTE_ZAVU_ADMIN_SECRET
WHATSAPP_ZAVU_DEFAULT_USER_EMAIL=demo@zak.test
WHATSAPP_ZAVU_DEFAULT_COMMUNITY_ID=
WHATSAPP_ZAVU_PHONE=
```

Save, then:

```bash
php artisan migrate --force
php artisan storage:link
php artisan zak:seed-assistant-demo
```

The seed prints a **community id**. Put that same id in `.env` as:

- `TELEGRAM_SPIKE_DEFAULT_COMMUNITY_ID`
- `WHATSAPP_ZAVU_DEFAULT_COMMUNITY_ID`

Then:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
chmod -R ug+rwx storage bootstrap/cache
```

---

## Step 7 — PM2: queue (and optional scheduler)

Do this as **zak-app**. Do **not** use CloudPanel Supervisor for the queue.

### What each process is

| Process | Job |
| --- | --- |
| `zak-queue` | Sends Telegram + Zavu replies. **Required.** |
| `zak-scheduler` | Laravel’s minute clock (`schedule:work`). **Optional today.** |

`schedule:run` / `schedule:work` is **not** the queue. It is Laravel checking “is any timed task due?” (cleanup, future Drive sync, etc.). This repo has **no scheduled tasks yet**, so you can skip the scheduler. Telegram and WhatsApp still work with only `zak-queue`.

Do **not** add that cron line if you use PM2.

### Start the queue

```bash
cd /home/zak-app/htdocs/zak-app.xerotek.io/backend
pm2 start artisan --name zak-queue --interpreter php -- \
  queue:work redis \
  --queue=high,channels,ai,ingestion,meetings,notifications,default \
  --sleep=1 --tries=3 --timeout=120
```

Optional scheduler (skip unless you want it):

```bash
pm2 start artisan --name zak-scheduler --interpreter php -- schedule:work
```

Then persist so it survives reboot:

```bash
pm2 save
pm2 startup
```

`pm2 startup` prints a `sudo` command. Copy it, SSH as **root**, run that one line, then back as **zak-app**:

```bash
pm2 save
pm2 status
pm2 logs zak-queue --lines 50
```

`zak-queue` must be `online`.

---

## Step 8 — Check the API

From any machine:

```bash
curl -sS https://zak-app.xerotek.io/up
curl -sS https://zak-app.xerotek.io/api/v1/health/live
```

Live must return: `{"status":"ok","service":"zak-backend"}`.

---

## Step 9 — Telegram worker

As **zak-app**:

```bash
cd /home/zak-app/htdocs/zak-app.xerotek.io/infrastructure/telegram-spike
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
nano .env
```

```env
LARAVEL_BASE_URL=https://zak-app.xerotek.io
SPIKE_SECRET=PASTE_TGSECRET
TELEGRAM_BOT_TOKEN=PASTE_BOTFATHER_TOKEN
TELEGRAM_PARSE_MODE=plain
ZAK_SHOW_WEB_CHAT=true
ZAK_WEB_CHAT_URL=https://zak-app.xerotek.io
```

`SPIKE_SECRET` and `TELEGRAM_BOT_TOKEN` must match Laravel exactly.

Still as **zak-app**, start the bot with PM2 (not systemd):

```bash
cd /home/zak-app/htdocs/zak-app.xerotek.io/infrastructure/telegram-spike
pm2 start .venv/bin/python --name zak-telegram --cwd /home/zak-app/htdocs/zak-app.xerotek.io/infrastructure/telegram-spike -- bot.py
pm2 save
pm2 status
```

`zak-queue` and `zak-telegram` must both be `online`.

On Telegram: open **that same bot** → `/help`. You must get a reply from that bot.

---

## Step 10 — WhatsApp (Zavu)

In the Zavu dashboard, set the webhook URL to:

`https://zak-app.xerotek.io/api/v1/webhooks/whatsapp-zavu`

Send a WhatsApp message to the Zavu number. Laravel + the queue worker handle it. No Chrome. No QR.

Do **not** run `infrastructure/whatsapp-web-spike` on this server.

---

## Step 11 — Frontend (after API works)

As **zak-app**:

```bash
cd /home/zak-app/htdocs/zak-app.xerotek.io/frontend
cp .env.example .env.local
nano .env.local
```

```env
NEXT_PUBLIC_APP_NAME=Zak
NEXT_PUBLIC_API_URL=https://zak-app.xerotek.io
```

```bash
npm ci
npm run build
```

CloudPanel: add a **Node.js** site only if you have a **second** hostname (for example `app.zak-app.xerotek.io`). Do not point the PHP document root at `frontend/`. This PHP site must stay on `backend/public`.

If you have no second hostname yet, skip this step. Telegram and WhatsApp already work without the web UI.

---

## Done when

1. `https://zak-app.xerotek.io/api/v1/health/live` works  
2. `curl http://127.0.0.1:8001/health/ready` works on the VPS  
3. `pm2 status` shows `zak-queue` (and `zak-telegram`) `online`  
4. Telegram `/help` replies in the same bot  
5. Zavu webhook receives a WhatsApp message  
6. `WHATSAPP_WEB_SPIKE` is still `false`  

## Day-2: wipe knowledge + embeddings

See [purge-knowledge.md](./purge-knowledge.md). Quick community reset:

```bash
cd /home/zak-app/htdocs/zak-app.xerotek.io/backend
php artisan zak:purge-knowledge --community=PASTE_COMMUNITY_ULID --force
```

If something fails, stop and paste: **step number + full command + full error**.
