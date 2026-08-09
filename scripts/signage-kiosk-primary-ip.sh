#!/usr/bin/env bash
# Best-effort primary LAN IPv4 for this kiosk (the address on the local network, not NAT/WAN).
set -euo pipefail

host_from_url() {
  local url="$1"
  python3 - "$url" <<'PY'
import sys
from urllib.parse import urlparse
u = urlparse(sys.argv[1])
print(u.hostname or '')
PY
}

pick_route_src() {
  local target="$1"
  [[ -n "$target" ]] || return 1
  if ! command -v ip >/dev/null 2>&1; then
    return 1
  fi
  ip -4 route get "$target" 2>/dev/null | awk '
    {
      for (i = 1; i <= NF; i++) {
        if ($i == "src") { print $(i + 1); exit }
      }
    }'
}

if [[ -f /etc/signage/kiosk.conf ]]; then
  # shellcheck disable=SC1091
  source /etc/signage/kiosk.conf
  host="$(host_from_url "${KIOSK_URL:-}" 2>/dev/null || true)"
  if [[ -z "$host" && -n "${SIGNAGE_SERVER:-}" ]]; then
    host="$(host_from_url "$SIGNAGE_SERVER" 2>/dev/null || true)"
  fi
  if [[ -n "$host" ]]; then
    ip="$(pick_route_src "$host" || true)"
    if [[ -n "$ip" ]]; then
      printf '%s\n' "$ip"
      exit 0
    fi
  fi
fi

if command -v ip >/dev/null 2>&1; then
  ip="$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1)"
  if [[ -n "$ip" ]]; then
    printf '%s\n' "$ip"
    exit 0
  fi
fi

if command -v hostname >/dev/null 2>&1; then
  hostname -I 2>/dev/null | awk '{print $1; exit}'
fi
