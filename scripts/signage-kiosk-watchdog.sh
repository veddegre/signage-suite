#!/usr/bin/env bash
# Restart the local signage kiosk when the server or browser stops responding.
# Installed by setup-kiosk.sh — polls every 5 minutes (first check 2 min after boot).
set -euo pipefail

CONF=/etc/signage/kiosk.conf
if [[ ! -f "$CONF" ]]; then
  exit 0
fi
# shellcheck disable=SC1090
source "$CONF"

URL="${KIOSK_URL:-}"
SCREEN="${SCREEN:-main}"
if [[ -z "$URL" ]]; then
  exit 0
fi

STATE_DIR=/run/signage-watchdog
mkdir -p "$STATE_DIR"
FAIL_FILE="$STATE_DIR/failures"
BROWSER_FAIL_FILE="$STATE_DIR/browser-failures"

curl_args=(-fsS --max-time 20)
if [[ "${KIOSK_IGNORE_SSL:-1}" == "1" ]]; then
  curl_args+=(-k)
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

if ! curl "${curl_args[@]}" -o "$tmp" "$URL" || ! grep -q 'const PAGES' "$tmp"; then
  fails=0
  if [[ -f "$FAIL_FILE" ]]; then
    fails=$(cat "$FAIL_FILE")
  fi
  fails=$((fails + 1))
  echo "$fails" > "$FAIL_FILE"
  rm -f "$BROWSER_FAIL_FILE"

  if [[ "$fails" -ge 2 ]]; then
    rm -f "$FAIL_FILE"
    logger -t signage-watchdog "restarting signage.service after $fails server failures ($URL)"
    systemctl restart signage.service
  fi
  exit 0
fi

rm -f "$FAIL_FILE"

# Server is up — is the browser actually running board.php (heartbeat)?
service_uptime=999999
started="$(systemctl show signage.service -p ActiveEnterTimestamp --value 2>/dev/null || true)"
if [[ -n "$started" ]]; then
  started_epoch="$(date -d "$started" +%s 2>/dev/null || echo 0)"
  if [[ "$started_epoch" -gt 0 ]]; then
    service_uptime=$(( $(date +%s) - started_epoch ))
  fi
fi

# Grace period after boot/restart while Chromium loads and first heartbeat posts.
if [[ "$service_uptime" -lt 180 ]]; then
  rm -f "$BROWSER_FAIL_FILE"
  exit 0
fi

health_url="$URL"
if [[ "$health_url" == *'?'* ]]; then
  health_url="${health_url}&api=kiosk-health"
else
  health_url="${health_url}?api=kiosk-health"
fi

health_ok=0
online=0
pages=-1
if curl "${curl_args[@]}" -o "$tmp" "$health_url"; then
  # Without kiosk-health on the server, curl returns full board.php HTML (~50KB).
  # Treat that as "API unavailable" — not "empty playlist" (pages=0).
  if grep -q '"ok"[[:space:]]*:[[:space:]]*true' "$tmp" && ! grep -qi '<html' "$tmp"; then
    health_ok=1
    if grep -q '"online"[[:space:]]*:[[:space:]]*true' "$tmp"; then
      online=1
    fi
    if grep -q '"pages"[[:space:]]*:[[:space:]]*false' "$tmp"; then
      pages=0
    elif grep -q '"pages"[[:space:]]*:[[:space:]]*true' "$tmp"; then
      pages=1
    fi
  fi
fi

if [[ "$health_ok" -eq 0 ]]; then
  # Server has not been updated with ?api=kiosk-health yet — server-only checks apply.
  rm -f "$BROWSER_FAIL_FILE"
  exit 0
fi

if [[ "$pages" -eq 0 ]]; then
  rm -f "$BROWSER_FAIL_FILE"
  exit 0
fi

if [[ "$online" -eq 1 ]]; then
  rm -f "$BROWSER_FAIL_FILE"
  exit 0
fi

browser_fails=0
if [[ -f "$BROWSER_FAIL_FILE" ]]; then
  browser_fails=$(cat "$BROWSER_FAIL_FILE")
fi
browser_fails=$((browser_fails + 1))
echo "$browser_fails" > "$BROWSER_FAIL_FILE"

if [[ "$browser_fails" -ge 2 ]]; then
  rm -f "$BROWSER_FAIL_FILE"
  logger -t signage-watchdog "restarting signage.service — server OK but no heartbeat from screen ${SCREEN} (${browser_fails} checks, uptime ${service_uptime}s)"
  systemctl restart signage.service
fi
