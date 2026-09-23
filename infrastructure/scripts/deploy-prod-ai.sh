#!/usr/bin/env bash
# Rebuild and recreate the AI container on the Contabo VPS.
# Run as root after: git pull on /home/zak-app/htdocs/zak-app.xerotek.io
# Usage: bash infrastructure/scripts/deploy-prod-ai.sh
set -euo pipefail

REPO="${ZAK_REPO:-/home/zak-app/htdocs/zak-app.xerotek.io}"
COMPOSE_DIR="${ZAK_COMPOSE_DIR:-/opt/zak}"

cd "$REPO"
echo "==> git HEAD: $(git log -1 --oneline)"

echo "==> building zak-ai:latest from $REPO/ai-service"
cd "$REPO/ai-service"
docker build -t zak-ai .

echo "==> recreating AI only (host Redis owns :6379 — do not force-recreate redis)"
cd "$COMPOSE_DIR"
docker compose up -d ai --no-deps --force-recreate

echo "==> health"
sleep 3
curl -sS --fail http://127.0.0.1:8001/health/live
echo
curl -sS --fail http://127.0.0.1:8001/health/ready || true
echo
docker compose ps
echo "==> done. Also run as zak-app: php artisan queue:restart && pm2 restart zak-queue zak-telegram"
