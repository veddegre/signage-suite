#!/usr/bin/env bash
# Restart the local signage kiosk when the server or browser stops responding,
# or when heartbeats continue but the same board stays on screen too long
# ("online but frozen" — compositor/JS stall that still posts presence).
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

# Same page (multi-page playlist) longer than this → count toward restart.
# Default 12 minutes; override with WATCHDOG_STALE_PAGE_SEC in kiosk.conf.
STALE_PAGE_SEC="${WATCHDOG_STALE_PAGE_SEC:-720}"
# Stuck in "loading" longer than this → count toward restart.
STALE_LOADING_SEC="${WATCHDOG_STALE_LOADING_SEC:-300}"

STATE_DIR=/run/signage-watchdog
mkdir -p "$STATE_DIR"
FAIL_FILE="$STATE_DIR/failures"
BROWSER_FAIL_FILE="$STATE_DIR/browser-failures"
STALE_FAIL_FILE="$STATE_DIR/stale-page-failures"

curl_args=(-fsS --max-time 20)
if [[ "${KIOSK_IGNORE_SSL:-1}" == "1" ]]; then
  curl_args+=(-k)
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

json_field() {
  # Extract a top-level JSON string/number/bool field without requiring jq.
  # Usage: json_field file key
  local file="$1" key="$2"
  python3 - "$file" "$key" <<'PY' 2>/dev/null || true
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as f:
        data = json.load(f)
except Exception:
    sys.exit(0)
val = data.get(sys.argv[2])
if val is None:
    sys.exit(0)
if isinstance(val, bool):
    print("true" if val else "false")
elif isinstance(val, (int, float)):
    print(int(val) if float(val).is_integer() else val)
else:
    print(val)
PY
}

if ! curl "${curl_args[@]}" -o "$tmp" "$URL" || ! grep -q 'const PAGES' "$tmp"; then
  fails=0
  if [[ -f "$FAIL_FILE" ]]; then
    fails=$(cat "$FAIL_FILE")
  fi
  fails=$((fails + 1))
  echo "$fails" > "$FAIL_FILE"
  rm -f "$BROWSER_FAIL_FILE" "$STALE_FAIL_FILE"

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
# Must exceed signage-kiosk-wait-for-runtime + wait-for-server (up to ~330s) before cage starts.
if [[ "$service_uptime" -lt 360 ]]; then
  rm -f "$BROWSER_FAIL_FILE" "$STALE_FAIL_FILE"
  exit 0
fi

# No cage process yet — still in launcher wait or crash loop settling; do not count as missing heartbeat.
if ! pgrep -x cage >/dev/null 2>&1; then
  rm -f "$BROWSER_FAIL_FILE" "$STALE_FAIL_FILE"
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
blank=0
schedule_blank=0
cec_enabled=0
page_total=0
page_age_sec=0
page_dwell=0
page_url=""
status=""
page_label=""
last_content_url=""
if curl "${curl_args[@]}" -o "$tmp" "$health_url"; then
  # Without kiosk-health on the server, curl returns full board.php HTML (~50KB).
  # Treat that as "API unavailable" — not "empty playlist" (pages=0).
  if grep -q '"ok"[[:space:]]*:[[:space:]]*true' "$tmp" && ! grep -qi '<html' "$tmp"; then
    health_ok=1
    online_raw="$(json_field "$tmp" online)"
    [[ "$online_raw" == "true" ]] && online=1
    pages_raw="$(json_field "$tmp" pages)"
    if [[ "$pages_raw" == "false" ]]; then
      pages=0
    elif [[ "$pages_raw" == "true" ]]; then
      pages=1
    fi
    blank_raw="$(json_field "$tmp" blank)"
    [[ "$blank_raw" == "true" ]] && blank=1
    schedule_blank_raw="$(json_field "$tmp" schedule_blank)"
    [[ "$schedule_blank_raw" == "true" ]] && schedule_blank=1
    cec_enabled_raw="$(json_field "$tmp" cec_enabled)"
    [[ "$cec_enabled_raw" == "true" ]] && cec_enabled=1
    page_total="$(json_field "$tmp" page_total)"
    page_total="${page_total:-0}"
    page_age_sec="$(json_field "$tmp" page_age_sec)"
    page_age_sec="${page_age_sec:-0}"
    page_dwell="$(json_field "$tmp" page_dwell)"
    page_dwell="${page_dwell:-0}"
    page_url="$(json_field "$tmp" page_url)"
    status="$(json_field "$tmp" status)"
    page_label="$(json_field "$tmp" page_label)"
    last_content_url="$(json_field "$tmp" last_content_url)"
  fi
fi

if [[ "$health_ok" -eq 0 ]]; then
  # Server has not been updated with ?api=kiosk-health yet — server-only checks apply.
  rm -f "$BROWSER_FAIL_FILE" "$STALE_FAIL_FILE"
  exit 0
fi

if [[ "$pages" -eq 0 ]]; then
  rm -f "$BROWSER_FAIL_FILE" "$STALE_FAIL_FILE"
  exit 0
fi

if [[ "$online" -eq 0 ]]; then
  rm -f "$STALE_FAIL_FILE"
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
  exit 0
fi

rm -f "$BROWSER_FAIL_FILE"

BLANK_RESTART_FILE="$STATE_DIR/blank-hours-restarted"

# Off-hours: the kiosk can still heartbeat and even report blank while HDMI
# holds the last RSS frame. Restart Chromium once per blank window so the
# overlay (and CEC) can take effect. signage-cec-sync re-asserts standby.
if [[ "$schedule_blank" -eq 1 ]]; then
  if [[ ! -f "$BLANK_RESTART_FILE" ]]; then
    echo 1 > "$BLANK_RESTART_FILE"
    rm -f "$STALE_FAIL_FILE"
    if [[ "$blank" -eq 0 && -n "$page_url" ]]; then
      logger -t signage-watchdog "restarting signage.service — screen ${SCREEN} should be blank but is still on '${page_label:-unknown}'"
    else
      logger -t signage-watchdog "restarting signage.service — screen ${SCREEN} entering blank hours (clear compositor)"
    fi
    systemctl restart signage.service
    exit 0
  fi
else
  rm -f "$BLANK_RESTART_FILE"
fi

# Online — detect frozen rotation: heartbeat alive, same board too long.
# Use kiosk-reported blank only (not the server schedule) so off-hours cannot
# hide a frozen RSS iframe that never actually blanked.
stale=0
stale_need=2
if [[ "$blank" -eq 0 ]]; then
  if [[ "$status" == "loading" && "$page_age_sec" -ge "$STALE_LOADING_SEC" ]]; then
    stale=1
  else
    limit="$STALE_PAGE_SEC"
    check_url="${page_url:-$last_content_url}"
    if [[ "$check_url" == *rss.php* ]]; then
      # RSS GPU hangs often freeze the shared renderer; don't wait 12+ minutes.
      limit=$((page_dwell + 90))
      if [[ "$limit" -lt 180 ]]; then limit=180; fi
      if [[ "$limit" -gt 480 ]]; then limit=480; fi
      stale_need=1
    fi
    if [[ "$page_total" -gt 1 && "$page_age_sec" -ge "$limit" ]]; then
      stale=1
    fi
  fi
fi

if [[ "$stale" -eq 0 ]]; then
  rm -f "$STALE_FAIL_FILE"
  exit 0
fi

stale_fails=0
if [[ -f "$STALE_FAIL_FILE" ]]; then
  stale_fails=$(cat "$STALE_FAIL_FILE")
fi
stale_fails=$((stale_fails + 1))
echo "$stale_fails" > "$STALE_FAIL_FILE"

if [[ "$stale_fails" -ge "$stale_need" ]]; then
  rm -f "$STALE_FAIL_FILE"
  logger -t signage-watchdog "restarting signage.service — screen ${SCREEN} online but stuck on '${page_label:-unknown}' for ${page_age_sec}s (status=${status:-?} pages=${page_total})"
  systemctl restart signage.service
fi
