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

curl_args=(-fsS --max-time 20)
if [[ "${KIOSK_IGNORE_SSL:-1}" == "1" ]]; then
  curl_args+=(-k)
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
if curl "${curl_args[@]}" -o "$tmp" "$URL" && grep -q 'const PAGES' "$tmp"; then
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
  logger -t signage-watchdog "restarting signage.service after $fails failed health checks ($URL)"
  systemctl restart signage.service
fi
