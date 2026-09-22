#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
LOG="${TMPDIR:-/tmp}/zak-ai-service.log"
: > "$LOG"
nohup .venv/bin/uvicorn ai_service.main:app --host 127.0.0.1 --port 8001 --log-level info >>"$LOG" 2>&1 &
echo "pid=$! log=$LOG"
sleep 2
head -n 5 "$LOG" || true
