#!/usr/bin/env bash
# Block until the rotation shell responds — avoids launching Chromium into a blank
# error page when the network or signage server is not ready at boot.
set -euo pipefail

CONF=/etc/signage/kiosk.conf
MAX_WAIT="${1:-240}"
SLEEP_SEC="${2:-2}"

if [[ ! -f "$CONF" ]]; then
  exit 0
fi
# shellcheck disable=SC1090
source "$CONF"

URL="${KIOSK_URL:-}"
if [[ -z "$URL" ]]; then
  exit 0
fi

curl_args=(-fsS --max-time 15)
if [[ "${KIOSK_IGNORE_SSL:-1}" == "1" ]]; then
  curl_args+=(-k)
fi

deadline=$(( $(date +%s) + MAX_WAIT ))
attempt=0
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

while [[ $(date +%s) -lt $deadline ]]; do
  attempt=$((attempt + 1))
  if curl "${curl_args[@]}" -o "$tmp" "$URL" && grep -q 'const PAGES' "$tmp"; then
    logger -t signage-kiosk "server ready after ${attempt} attempt(s): $URL"
    exit 0
  fi
  if (( attempt == 1 || attempt % 15 == 0 )); then
    logger -t signage-kiosk "waiting for server ($attempt): $URL"
  fi
  sleep "$SLEEP_SEC"
done

logger -t signage-kiosk "server not ready after ${MAX_WAIT}s — launching browser anyway ($URL)"
exit 0
