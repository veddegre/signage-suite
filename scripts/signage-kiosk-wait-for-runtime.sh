#!/usr/bin/env bash
# Wait for the kiosk user's XDG runtime dir (needed by cage/Wayland at boot).
set -euo pipefail

MAX_WAIT="${1:-120}"
uid="$(id -u)"
runtime="/run/user/$uid"
deadline=$(( $(date +%s) + MAX_WAIT ))

while [[ $(date +%s) -lt $deadline ]]; do
  if [[ -d "$runtime" ]]; then
    exit 0
  fi
  sleep 1
done

logger -t signage-kiosk "XDG runtime dir missing after ${MAX_WAIT}s ($runtime)"
exit 0
