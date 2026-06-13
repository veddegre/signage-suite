#!/usr/bin/env bash
# Poll the server for this screen's CEC schedule and send HDMI-CEC standby/on.
# Installed by setup-kiosk.sh — reads /etc/signage/kiosk.conf.

set -euo pipefail

CONF=/etc/signage/kiosk.conf
STATE_FILE=/run/signage-cec.state

if [[ ! -f "$CONF" ]]; then
  exit 0
fi
# shellcheck disable=SC1090
source "$CONF"

: "${BOARDS_URL:?BOARDS_URL missing in $CONF}"
SCREEN="${SCREEN:-main}"
SCREEN="$(echo "$SCREEN" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
[[ "$SCREEN" == "" ]] && SCREEN=main

API_URL="${BOARDS_URL%/}/board.php?api=cec&screen=${SCREEN}"
JSON="$(curl -sf --max-time 12 "$API_URL" 2>/dev/null)" || exit 0

read -r ENABLED STANDBY DEVICE <<EOF
$(python3 - <<'PY' "$JSON"
import json, sys
try:
    d = json.loads(sys.argv[1])
except Exception:
    print("0 0 0")
    raise SystemExit
cec = d.get("cec") or {}
print(
    "1" if cec.get("enabled") else "0",
    "1" if cec.get("standby") else "0",
    int(cec.get("device") or 0),
)
PY
)
EOF

if [[ "$ENABLED" != "1" ]]; then
  exit 0
fi
if ! command -v cec-client >/dev/null 2>&1; then
  exit 0
fi

WANT=on
[[ "$STANDBY" == "1" ]] && WANT=standby
PREV="$(cat "$STATE_FILE" 2>/dev/null || true)"
if [[ "$PREV" == "$WANT" ]]; then
  exit 0
fi

if [[ "$WANT" == "standby" ]]; then
  echo "standby ${DEVICE}" | cec-client -s -d 1 >/dev/null 2>&1 || true
else
  echo "on ${DEVICE}" | cec-client -s -d 1 >/dev/null 2>&1 || true
fi
echo "$WANT" >"$STATE_FILE"
