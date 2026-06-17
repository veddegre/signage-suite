#!/usr/bin/env bash
# Park the hardware pointer off-screen. Cage draws a compositor cursor whenever
# a pointer-capable input exists (USB mouse, HDMI-CEC, some IR receivers) and
# CSS / Xcursor themes cannot suppress that layer.
set -euo pipefail

INTERVAL="${SIGNAGE_HIDE_CURSOR_INTERVAL:-3}"
# ydotool absolute coords use a 0..65535 virtual desktop.
OFF_X="${SIGNAGE_HIDE_CURSOR_X:-65534}"
OFF_Y="${SIGNAGE_HIDE_CURSOR_Y:-65534}"

if ! command -v ydotool >/dev/null 2>&1; then
  echo "ydotool not installed" >&2
  exit 1
fi

if ! pgrep -u "$(id -u)" -x ydotoold >/dev/null 2>&1; then
  ydotoold >/dev/null 2>&1 &
  sleep 0.4
fi

while true; do
  ydotool mousemove -a "$OFF_X" "$OFF_Y" 2>/dev/null \
    || ydotool mousemove --absolute "$OFF_X" "$OFF_Y" 2>/dev/null \
    || ydotool mousemove "$OFF_X" "$OFF_Y" 2>/dev/null \
    || true
  sleep "$INTERVAL"
done
