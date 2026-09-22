# Infrastructure

Deploy and local sidecar tooling. Production Docker images land in Phase 7 under `docker/`.

## WhatsApp Web automation spike (DEV ONLY)

Unofficial WA Web session bridge (whatsapp-web.js). **Not for production.** Meta ToS / ban risk.

→ [whatsapp-web-spike/README.md](whatsapp-web-spike/README.md)

## Telegram Bot spike (DEV ONLY)

Official Telegram Bot API sidecar. Align with Phase 4 later.

→ [telegram-spike/README.md](telegram-spike/README.md)

## WhatsApp via Zavu (official)

No sidecar worker. Configure Zavu dashboard webhook → Laravel `POST /api/v1/webhooks/whatsapp-zavu`. See backend `config/whatsapp_zavu.php` and Phase 4Z in the implementation plan.

Do **not** add WA Web / Telegram workers to Sail or production Compose.
