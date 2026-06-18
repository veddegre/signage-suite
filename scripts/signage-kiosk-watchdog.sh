#!/usr/bin/env bash
# Restart the local signage kiosk if the rotation shell stops responding.
# Installed by setup-kiosk.sh — polls every 5 minutes.
set -euo pipefail

CONF=/etc/signage/kiosk.conf
if [[ ! -f "$CONF" ]]; then
  exit 0
fi
# shellcheck disable=SC1090
source "$CONF"

URL="${KIOSK_URL:-}"
if [[ -z "$URL" ]]; then
  exit 0
fi

STATE_DIR=/run/signage-watchdog
mkdir -p "$STATE_DIR"
FAIL_FILE="$STATE_DIR/failures"

if curl -fsS --max-time 20 "$URL" | grep -q 'const PAGES'; then
  rm -f "$FAIL_FILE"
  exit 0
fi

fails=0
if [[ -f "$FAIL_FILE" ]]; then
  fails=$(cat "$FAIL_FILE")
fi
fails=$((fails + 1))
echo "$fails" > "$FAIL_FILE"

if [[ "$fails" -ge 3 ]]; then
  rm -f "$FAIL_FILE"
  systemctl restart signage.service
fi
