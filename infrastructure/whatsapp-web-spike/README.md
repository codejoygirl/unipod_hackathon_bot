# WhatsApp Web automation spike (DEV ONLY)

Unofficial WhatsApp Web automation via [whatsapp-web.js](https://github.com/pedroslopez/whatsapp-web.js) (Chromium + `web.whatsapp.com`).

**Not for production.** Meta ToS / account ban / session-break risk. Production channel path remains **WhatsApp Cloud API** (Phase 4).

### Why whatsapp-web.js?

It drives a real Chromium session against WhatsApp Web (closer to normal linked-device Web use than a raw protocol client like Baileys). Still unofficial — same ToS class; heavier runtime (Chrome). Not a substitute for Cloud API.

## What it does

1. QR-link a personal WhatsApp as a linked device  
2. Private inbound text → `POST /api/v1/internal/whatsapp-web-spike/inbound`  
3. Laravel runs the same grounded ask + citation checks as the web API  
4. Worker sends the reply back in WhatsApp  

Also: `JOIN-{token}` (link phone → community), `/share` (member suggest), `/export` (admin ingest draft).

Group listen (when sidecar `PRIVATE_CHATS_ONLY=false`): mention any `WHATSAPP_WEB_SPIKE_BOT_ALIASES` **or** a recognized command; otherwise Laravel stays silent.

## Clean run (end-to-end)

Assumes Docker Desktop is up. Secrets must match on both sides (`SPIKE_SECRET` = `WHATSAPP_WEB_SPIKE_SECRET`).

### 1. Backend (Sail) + seed

**PowerShell**

```powershell
cd backend
.\vendor\bin\sail up -d
.\vendor\bin\sail artisan migrate
.\vendor\bin\sail artisan zak:seed-assistant-demo
```

Copy the printed **community id** into `backend/.env`:

```env
WHATSAPP_WEB_SPIKE=true
WHATSAPP_WEB_SPIKE_SECRET=your-long-shared-secret
WHATSAPP_WEB_SPIKE_DEFAULT_USER_EMAIL=demo@zak.test
WHATSAPP_WEB_SPIKE_DEFAULT_COMMUNITY_ID=01…   # from seed output
```

```powershell
.\vendor\bin\sail artisan config:clear
```

**Linux / macOS** — same with `./vendor/bin/sail`.

### 2. AI service (for real RAG answers)

```powershell
cd ai-service
uv run fastapi dev src/ai_service/main.py --port 8001
```

### 3. Spike worker

Needs a local Chrome/Chromium (used instead of Puppeteer’s bundled browser). Default on Windows: `C:\Program Files\Google\Chrome\Application\chrome.exe`. Override with `CHROME_PATH` in `.env`.

```powershell
cd infrastructure/whatsapp-web-spike
Copy-Item .env.example .env   # first time only
# Edit .env: SPIKE_SECRET = same as WHATSAPP_WEB_SPIKE_SECRET
#          LARAVEL_BASE_URL=http://localhost
$env:PUPPETEER_SKIP_DOWNLOAD='true'   # use system Chrome; skip long Chromium download
npm install                   # first time / after dependency change
npm start
```

First boot can take a while while Chromium starts. Scan the QR: WhatsApp → **Linked Devices** → Link a device.

Leave this terminal open. Session files land in `.wwebjs_auth/` (gitignored). Next start should reconnect without QR unless you logged out.

### 4. Try it on your phone

Private chat to the linked number:

| You send | What happens |
| --- | --- |
| `When does the clinic open?` | Grounded ask → cited reply (default community from `.env`) |
| `JOIN-…` from mint endpoint | Links this phone → community (optional if default community is set) |
| `EXPORT Water off tomorrow` | Creates a knowledge **draft** for review |

Mint a JOIN token (optional):

```powershell
$secret = "your-long-shared-secret"
Invoke-RestMethod -Method Post `
  -Uri http://localhost/api/v1/internal/whatsapp-web-spike/join-token `
  -Headers @{ "X-Spike-Secret" = $secret } `
  -ContentType "application/json" `
  -Body '{"community_id":"01…"}'
```

### 5. Stop / reset session

- Stop worker: `Ctrl+C`  
- Force new QR: delete `infrastructure/whatsapp-web-spike/.wwebjs_auth/` then `npm start`  
- Disable entirely: `WHATSAPP_WEB_SPIKE=false` in `backend/.env` + `config:clear`

## Layout

```text
infrastructure/whatsapp-web-spike/
  src/index.js          # whatsapp-web.js + Laravel bridge
  .wwebjs_auth/         # gitignored session (do not commit)
  .env                  # gitignored
```

Laravel: `config/whatsapp_web_spike.php`, `WhatsAppWebSpikeAdapter`, routes under `/api/v1/internal/whatsapp-web-spike/*` (404 unless flag on).

## Explicit non-goals

- Production deploy / Sail compose service / prod Docker  
- Replacing Cloud API Phase 4  
- Group history scraping as a product feature  
