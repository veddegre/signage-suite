#!/usr/bin/env bash
# Wait for the kiosk user's XDG runtime dir and seatd (needed by cage/Wayland at boot).
# Must run under ExecStart (PAM session), not ExecStartPre — the dir is created at login.
set -euo pipefail

MAX_WAIT="${1:-90}"
uid="$(id -u)"
runtime="/run/user/$uid"
deadline=$(( $(date +%s) + MAX_WAIT ))

while [[ $(date +%s) -lt $deadline ]]; do
  seatd_ok=0
  if [[ -S /run/seatd.sock ]] || [[ -S /var/run/seatd.sock ]]; then
    seatd_ok=1
  fi
  if [[ -d "$runtime" && "$seatd_ok" -eq 1 ]]; then
    exit 0
  fi
  sleep 1
done

logger -t signage-kiosk "runtime/seatd not ready after ${MAX_WAIT}s (runtime=$runtime seatd=${seatd_ok:-0})"
exit 1
