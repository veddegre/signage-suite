#!/usr/bin/env bash
# Park the hardware pointer off-screen. Cage draws a compositor cursor whenever
# a pointer-capable input exists (USB mouse, HDMI-CEC, some IR receivers) and
# CSS / Xcursor themes cannot suppress that layer.
set -euo pipefail

INTERVAL="${SIGNAGE_HIDE_CURSOR_INTERVAL:-1}"
# ydotool absolute coords use a 0..65535 virtual desktop.
OFF_X="${SIGNAGE_HIDE_CURSOR_X:-65534}"
OFF_Y="${SIGNAGE_HIDE_CURSOR_Y:-65534}"

if ! command -v ydotool >/dev/null 2>&1; then
  echo "ydotool not installed — run scripts/install-ydotool.sh" >&2
  exit 1
fi

start_ydotoold() {
  if pgrep -u "$(id -u)" -x ydotoold >/dev/null 2>&1; then
    return 0
  fi
  if command -v ydotoold >/dev/null 2>&1; then
    ydotoold >/dev/null 2>&1 &
  else
    return 1
  fi
  local i=0
  while [[ $i -lt 20 ]]; do
    ydotool mousemove -a 0 0 2>/dev/null && return 0
    sleep 0.2
    i=$((i + 1))
  done
  return 0
}

move_offscreen() {
  ydotool mousemove -a "$OFF_X" "$OFF_Y" 2>/dev/null \
    || ydotool mousemove --absolute "$OFF_X" "$OFF_Y" 2>/dev/null \
    || ydotool mousemove "$OFF_X" "$OFF_Y" 2>/dev/null \
    || true
}

start_ydotoold || true

# Burst moves at startup — cage often shows a centered pointer until the first move.
for _ in $(seq 1 15); do
  move_offscreen
  sleep 0.2
done

while true; do
  move_offscreen
  sleep "$INTERVAL"
done
