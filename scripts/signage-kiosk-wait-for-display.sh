#!/usr/bin/env bash
# Prepare the kernel VT + DRM before cage starts (x86 mini PCs / NUCs).
# Runs as root from signage.service ExecStartPre=+ — chvt needs CAP_SYS_TTY_CONFIG.
set -euo pipefail

MAX_WAIT="${1:-45}"

activate_vt() {
  if ! command -v chvt >/dev/null 2>&1; then
    return 0
  fi
  chvt 1 2>/dev/null || true
  sleep 1
  chvt 1 2>/dev/null || true
}

activate_vt

deadline=$(( $(date +%s) + MAX_WAIT ))
while [[ $(date +%s) -lt $deadline ]]; do
  if [[ -e /dev/dri/card0 ]] || [[ -e /dev/dri/card1 ]]; then
    activate_vt
    logger -t signage-kiosk "display ready (/dev/dri present)"
    exit 0
  fi
  sleep 1
done

logger -t signage-kiosk "no /dev/dri/card* after ${MAX_WAIT}s — continuing anyway"
activate_vt
exit 0
