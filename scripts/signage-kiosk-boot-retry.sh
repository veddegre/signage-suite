#!/usr/bin/env bash
# One-shot boot helper: restart signage if cage never started (x86 VT/DRM race).
set -euo pipefail

if pgrep -x cage >/dev/null 2>&1; then
  exit 0
fi

if ! systemctl is-active --quiet signage.service 2>/dev/null; then
  logger -t signage-kiosk "boot retry — signage.service not active; starting"
  systemctl start signage.service || true
  exit 0
fi

service_uptime=999999
started="$(systemctl show signage.service -p ActiveEnterTimestamp --value 2>/dev/null || true)"
if [[ -n "$started" ]]; then
  started_epoch="$(date -d "$started" +%s 2>/dev/null || echo 0)"
  if [[ "$started_epoch" -gt 0 ]]; then
    service_uptime=$(( $(date +%s) - started_epoch ))
  fi
fi

# Still waiting for network/server at boot — don't interrupt (server wait up to ~4 min).
if [[ "$service_uptime" -lt 300 ]]; then
  if journalctl -u signage -b --no-pager 2>/dev/null | tail -8 | grep -qE 'waiting for server|waiting for runtime'; then
    exit 0
  fi
fi

logger -t signage-kiosk "boot retry — no cage after ${service_uptime}s; restarting signage.service"
systemctl restart signage.service || true
