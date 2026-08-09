#!/usr/bin/env bash
# POST this kiosk's LAN IP to the signage server (for Status when NAT hides REMOTE_ADDR).
set -euo pipefail

CONF=/etc/signage/kiosk.conf
if [[ ! -f "$CONF" ]]; then
  logger -t signage-kiosk "local-ip report skipped — no kiosk.conf"
  exit 0
fi
# shellcheck disable=SC1091
source "$CONF"

URL="${KIOSK_URL:-}"
SCREEN="${SCREEN:-main}"
if [[ -z "$URL" ]]; then
  logger -t signage-kiosk "local-ip report skipped — KIOSK_URL empty"
  exit 0
fi

LOCAL_IP=""
if [[ -x /usr/local/bin/signage-kiosk-primary-ip ]]; then
  LOCAL_IP="$(/usr/local/bin/signage-kiosk-primary-ip 2>/dev/null || true)"
fi
if [[ -z "$LOCAL_IP" ]]; then
  logger -t signage-kiosk "local-ip report skipped — could not detect LAN address"
  exit 0
fi

# Persist for launcher fallback across restarts.
if grep -q '^KIOSK_LOCAL_IP=' "$CONF" 2>/dev/null; then
  sed -i "s/^KIOSK_LOCAL_IP=.*/KIOSK_LOCAL_IP=\"$LOCAL_IP\"/" "$CONF"
else
  printf 'KIOSK_LOCAL_IP="%s"\n' "$LOCAL_IP" >>"$CONF"
fi

STATE=/run/signage/local-ip-reported
mkdir -p /run/signage
if [[ -f "$STATE" ]] && [[ "$(cat "$STATE" 2>/dev/null)" == "$LOCAL_IP" ]]; then
  exit 0
fi

curl_args=(--max-time 20 -X POST -H 'Content-Type: application/json')
if [[ "${KIOSK_IGNORE_SSL:-1}" == "1" ]]; then
  curl_args+=(-k)
fi

payload="$(printf '{"local_ip":"%s","local_ip_only":true}' "$LOCAL_IP")"

post_local_ip() {
  local base="$1"
  local api="$2"
  local u="$base"
  if [[ "$u" != *'screen='* && "$SCREEN" != "main" ]]; then
    if [[ "$u" == *'?'* ]]; then u="${u}&screen=${SCREEN}"; else u="${u}?screen=${SCREEN}"; fi
  fi
  if [[ "$u" == *'?'* ]]; then u="${u}&api=${api}"; else u="${u}?api=${api}"; fi
  local tmp http_code body
  tmp="$(mktemp)"
  http_code="$(curl "${curl_args[@]}" -d "$payload" -o "$tmp" -w '%{http_code}' "$u" 2>/dev/null || printf '000')"
  body="$(tr -d '\n' <"$tmp" | head -c 240)"
  rm -f "$tmp"
  if [[ "$http_code" == "200" ]] && [[ "$body" == *'"ok":true'* || "$body" == *'"ok": true'* ]]; then
    printf '%s' "$LOCAL_IP" >"$STATE"
    logger -t signage-kiosk "reported local IP $LOCAL_IP via api=${api} (HTTP $http_code)"
    return 0
  fi
  logger -t signage-kiosk "local-ip via api=${api} failed HTTP $http_code url=$u body=$body"
  return 1
}

if post_local_ip "$URL" "presence-local"; then exit 0; fi
if post_local_ip "$URL" "presence"; then exit 0; fi
exit 1
