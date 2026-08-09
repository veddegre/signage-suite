#!/usr/bin/env bash
# Best-effort primary LAN IPv4 for this kiosk (local network, not NAT/WAN).
set -euo pipefail

IP_CMD=""
for c in /sbin/ip /usr/bin/ip /bin/ip; do
  if [[ -x "$c" ]]; then
    IP_CMD="$c"
    break
  fi
done

route_src() {
  local target="$1"
  [[ -n "$IP_CMD" && -n "$target" ]] || return 1
  "$IP_CMD" -4 route get "$target" 2>/dev/null | awk '
    /unreachable/ { exit 1 }
    {
      for (i = 1; i <= NF; i++) {
        if ($i == "src") { print $(i + 1); exit }
      }
    }'
}

host_from_url() {
  local url="$1" host
  [[ -n "$url" ]] || return 1
  host="${url#*://}"
  host="${host%%/*}"
  host="${host%%:*}"
  host="${host%%\?*}"
  [[ -n "$host" && "$host" != "$url" ]] || return 1
  printf '%s' "$host"
}

# Default-route source IP — most reliable "what LAN am I on?" on kiosks.
for target in 1.1.1.1 8.8.8.8; do
  ip="$(route_src "$target" || true)"
  if [[ -n "$ip" ]]; then
    printf '%s\n' "$ip"
    exit 0
  fi
done

if [[ -f /etc/signage/kiosk.conf ]]; then
  # shellcheck disable=SC1091
  source /etc/signage/kiosk.conf
  if [[ -n "${KIOSK_LOCAL_IP:-}" ]] && [[ "${KIOSK_LOCAL_IP}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf '%s\n' "$KIOSK_LOCAL_IP"
    exit 0
  fi
  host="$(host_from_url "${KIOSK_URL:-}" 2>/dev/null || true)"
  if [[ -z "$host" && -n "${SIGNAGE_SERVER:-}" ]]; then
    host="$(host_from_url "$SIGNAGE_SERVER" 2>/dev/null || true)"
  fi
  if [[ -n "$host" ]]; then
    ip="$(route_src "$host" || true)"
    if [[ -n "$ip" ]]; then
      printf '%s\n' "$ip"
      exit 0
    fi
  fi
fi

if [[ -n "$IP_CMD" ]]; then
  ip="$("$IP_CMD" -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1)"
  if [[ -n "$ip" ]]; then
    printf '%s\n' "$ip"
    exit 0
  fi
fi

if command -v hostname >/dev/null 2>&1; then
  hostname -I 2>/dev/null | awk '{print $1; exit}'
fi
