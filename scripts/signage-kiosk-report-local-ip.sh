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

if [[ ! -x /usr/local/bin/signage-kiosk-primary-ip ]]; then
  logger -t signage-kiosk "local-ip report skipped — signage-kiosk-primary-ip missing"
  exit 0
fi

LOCAL_IP="$(/usr/local/bin/signage-kiosk-primary-ip 2>/dev/null || true)"
if [[ -z "$LOCAL_IP" ]]; then
  logger -t signage-kiosk "local-ip report skipped — could not detect LAN address"
  exit 0
fi

STATE=/run/signage/local-ip-reported
mkdir -p /run/signage
if [[ -f "$STATE" ]] && [[ "$(cat "$STATE" 2>/dev/null)" == "$LOCAL_IP" ]]; then
  exit 0
fi

presence_url="$URL"
if [[ "$presence_url" != *'screen='* && "$SCREEN" != "main" ]]; then
  if [[ "$presence_url" == *'?'* ]]; then
    presence_url="${presence_url}&screen=${SCREEN}"
  else
    presence_url="${presence_url}?screen=${SCREEN}"
  fi
fi
if [[ "$presence_url" == *'?'* ]]; then
  presence_url="${presence_url}&api=presence-local"
else
  presence_url="${presence_url}?api=presence-local"
fi

curl_args=(--max-time 20 -X POST -H 'Content-Type: application/json')
if [[ "${KIOSK_IGNORE_SSL:-1}" == "1" ]]; then
  curl_args+=(-k)
fi

payload="$(printf '{"local_ip":"%s"}' "$LOCAL_IP")"
response="$(mktemp)"
http_code=0
http_code="$(curl "${curl_args[@]}" -d "$payload" -o "$response" -w '%{http_code}' "$presence_url" 2>/dev/null || printf '000')"
body="$(tr -d '\n' <"$response" | head -c 200)"
rm -f "$response"

if [[ "$http_code" == "200" ]] && [[ "$body" == *'"ok":true'* || "$body" == *'"ok": true'* ]]; then
  printf '%s' "$LOCAL_IP" >"$STATE"
  logger -t signage-kiosk "reported local IP $LOCAL_IP (HTTP $http_code)"
  exit 0
fi

logger -t signage-kiosk "local-ip report failed HTTP $http_code url=$presence_url body=$body"
exit 1
