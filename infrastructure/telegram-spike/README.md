# Telegram Bot spike (DEV / hackathon ONLY)

Official [Telegram Bot API](https://core.telegram.org/bots) sidecar for Zak.

**Not production productization** — align with Phase 4 channel adapters later. Laravel stays the brain; this worker only does Bot I/O.

## What it does

1. Poll Telegram for private text  
2. `POST /api/v1/internal/telegram-spike/inbound`  
3. Laravel runs grounded ask + citation checks  
4. Bot replies in Telegram  

Also: `/join <code>`, `/share <note>` (propose a knowledge draft for admin review).

## Setup

### 1. Create a bot

1. Open Telegram → [@BotFather](https://t.me/BotFather)  
2. `/newbot` → copy the token  

### 2. Backend flag

In `backend/.env`:

```env
TELEGRAM_SPIKE=true
TELEGRAM_SPIKE_SECRET=some-long-shared-secret
TELEGRAM_SPIKE_DEFAULT_USER_EMAIL=demo@zak.test
TELEGRAM_SPIKE_DEFAULT_COMMUNITY_ID=01…   # from zak:seed-assistant-demo
TELEGRAM_SPIKE_TARGET_LANGUAGE=en
TELEGRAM_SPIKE_BOT_TOKEN=…               # same BotFather token (admin escalation DMs)
TELEGRAM_SPIKE_ADMIN_CONTACT=08117084647 # configurable admin contact (phone for now)
TELEGRAM_SPIKE_ADMIN_CHAT_ID=…           # Telegram user/chat id that receives escalations
```

To get `TELEGRAM_SPIKE_ADMIN_CHAT_ID`: open a private chat with your bot, send any message, then open  
`https://api.telegram.org/bot<TOKEN>/getUpdates` and copy your `message.chat.id`.

When Zak cannot answer, Laravel stores an escalation and DMs that admin chat on Telegram (the platform this spike runs on).

```powershell
cd backend
.\vendor\bin\sail artisan config:clear
```

### 3. Worker

```powershell
cd infrastructure/telegram-spike
Copy-Item .env.example .env
# TELEGRAM_BOT_TOKEN=...
# SPIKE_SECRET=same as TELEGRAM_SPIKE_SECRET
# LARAVEL_BASE_URL=http://localhost
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
python bot.py
```

Linux/macOS: `python3 -m venv .venv && source .venv/bin/activate && pip install -r requirements.txt && python bot.py`

### 4. Try it

Open your bot → `/start`, then ask normally, or use `/join` / `/share` / `/help`.

Mint a join code (optional if default community is set):

```powershell
Invoke-RestMethod -Method Post `
  -Uri http://localhost/api/v1/internal/telegram-spike/join-token `
  -Headers @{ "X-Spike-Secret" = "your-secret" } `
  -ContentType "application/json" `
  -Body '{"community_id":"01…"}'
```

Then in Telegram: `/join <code from response>`.

## Explicit non-goals

- Sail / prod Compose service  
- Replacing Phase 4 official channel adapters  
- Calling AI service directly from the bot (`ai-service/test_bot.py` is obsolete for this flow)
