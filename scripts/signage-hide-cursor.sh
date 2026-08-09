#!/usr/bin/env bash
# Park the hardware pointer off-screen when ydotoold is available.
# Prefer phantom HDMI udev rules on Pi (scripts/signage-apply-phantom-pointer-rules.sh).
set -euo pipefail

if [[ -f /etc/signage/phantom-pointer-fixed ]] \
   || [[ -f /etc/udev/rules.d/99-signage-phantom-pointer.rules ]]; then
  exit 0
fi

INTERVAL="${SIGNAGE_HIDE_CURSOR_INTERVAL:-1}"
OFF_X="${SIGNAGE_HIDE_CURSOR_X:-65534}"
OFF_Y="${SIGNAGE_HIDE_CURSOR_Y:-65534}"
UID_NUM="$(id -u)"
SOCKET="/run/user/${UID_NUM}/.ydotool_socket"

if ! command -v ydotool >/dev/null 2>&1; then
  exit 0
fi

start_ydotoold() {
  if pgrep -u "$UID_NUM" -x ydotoold >/dev/null 2>&1 || [[ -S "$SOCKET" ]]; then
    return 0
  fi
  command -v ydotoold >/dev/null 2>&1 || return 1
  ydotoold >/dev/null 2>&1 &
  local i=0
  while [[ $i -lt 25 ]]; do
    [[ -S "$SOCKET" ]] && return 0
    sleep 0.2
    i=$((i + 1))
  done
  return 1
}

move_offscreen() {
  ydotool mousemove -a "$OFF_X" "$OFF_Y" 2>/dev/null \
    || ydotool mousemove --absolute "$OFF_X" "$OFF_Y" 2>/dev/null \
    || ydotool mousemove "$OFF_X" "$OFF_Y" 2>/dev/null \
    || return 1
}

if ! start_ydotoold; then
  exit 0
fi

for _ in $(seq 1 5); do
  move_offscreen || true
  sleep 0.2
done

while true; do
  move_offscreen || exit 0
  sleep "$INTERVAL"
done
