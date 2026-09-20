#!/usr/bin/env bash
# Seed demo user + community + published knowledge, then call /assistant/ask.
# Usage (WSL, from backend/):
#   ./scripts/seed-and-ask.sh
#   ./scripts/seed-and-ask.sh --skip-ai

set -euo pipefail
cd "$(dirname "$0")/.."

SKIP_AI=""
if [[ "${1:-}" == "--skip-ai" ]]; then
  SKIP_AI="--skip-ai"
fi

./vendor/bin/sail artisan zak:seed-assistant-demo ${SKIP_AI}

# shellcheck disable=SC1091
set -a
source storage/app/zak-demo-ask.env
set +a

echo
echo "Calling POST ${ZAK_API_BASE}/api/v1/assistant/ask ..."
curl -sS -X POST "${ZAK_API_BASE}/api/v1/assistant/ask" \
  -H "Authorization: Bearer ${ZAK_DEMO_TOKEN}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"query\":\"When does the clinic open?\",\"community_ids\":[\"${ZAK_COMMUNITY_ID}\"],\"target_language\":\"en\"}" \
  | python3 -m json.tool 2>/dev/null || cat
echo
