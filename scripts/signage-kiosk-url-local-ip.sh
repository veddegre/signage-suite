#!/usr/bin/env bash
# Append or replace kiosk_local_ip= on a board.php URL (no Python required).
set -euo pipefail

url="${1:-}"
ip="${2:-}"
if [[ -z "$url" || -z "$ip" ]]; then
  exit 1
fi

# Strip any existing kiosk_local_ip query param.
url="$(printf '%s' "$url" | sed -E 's/([?&])kiosk_local_ip=[^&]*(&|$)/\1/g; s/[?&]$//')"

if [[ "$url" == *'?'* ]]; then
  printf '%s&kiosk_local_ip=%s' "$url" "$ip"
else
  printf '%s?kiosk_local_ip=%s' "$url" "$ip"
fi
