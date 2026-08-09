#!/usr/bin/env bash
# Suppress cage's phantom HDMI cursor on Raspberry Pi via a one-time VT switch.
# cage keeps running — no udev ignore, no ydotool. See cage-kiosk/cage#299 (magicalmarc, 2026).
set -euo pipefail

CONF=/etc/signage/kiosk.conf
KIOSK_VT="${SIGNAGE_KIOSK_VT:-1}"
SCRATCH_VT="${SIGNAGE_CURSOR_SCRATCH_VT:-2}"
MAX_ATTEMPTS="${SIGNAGE_CURSOR_VT_ATTEMPTS:-6}"
CONFIRM_WAIT="${SIGNAGE_CURSOR_VT_CONFIRM:-45}"
SETTLE_SEC="${SIGNAGE_CURSOR_VT_SETTLE:-90}"

if [[ -f "$CONF" ]]; then
  # shellcheck disable=SC1091
  source "$CONF"
fi

URL="${KIOSK_URL:-}"
if [[ -z "$URL" && -f "$CONF" ]]; then
  URL="$(grep -E '^KIOSK_URL=' "$CONF" | head -1 | cut -d= -f2- | tr -d '"')"
fi

curl_args=(-fsS --max-time 20)
[[ "${KIOSK_IGNORE_SSL:-1}" == "1" ]] && curl_args+=(-k)

wait_for_kiosk_url() {
  [[ -n "$URL" ]] || return 0
  local deadline=$(( $(date +%s) + 300 ))
  while [[ $(date +%s) -lt $deadline ]]; do
    if curl "${curl_args[@]}" "$URL" | grep -q 'const PAGES'; then
      logger -t signage-cursor-vt "rotation shell ready — settling ${SETTLE_SEC}s before VT switch"
      sleep "$SETTLE_SEC"
      return 0
    fi
    sleep 5
  done
  logger -t signage-cursor-vt "rotation shell not confirmed — proceeding anyway after timeout"
  return 0
}

cursor_plane_present() {
  local f
  for f in /sys/kernel/debug/dri/*/state; do
    [[ -r "$f" ]] || continue
    if grep -qE 'size=64x64|cursor' "$f" 2>/dev/null && grep -q 'size=64x64' "$f" 2>/dev/null; then
      return 0
    fi
  done
  return 1
}

have_drm_debug=0
if cursor_plane_present 2>/dev/null; then
  have_drm_debug=1
elif [[ -r /sys/kernel/debug/dri/0/state ]] || [[ -r /sys/kernel/debug/dri/1/state ]]; then
  have_drm_debug=1
fi

wait_for_cage() {
  local deadline=$(( $(date +%s) + 600 ))
  while [[ $(date +%s) -lt $deadline ]]; do
    pgrep -x cage >/dev/null 2>&1 && return 0
    sleep 3
  done
  return 1
}

if ! wait_for_cage; then
  logger -t signage-cursor-vt "cage not running after wait — skip"
  exit 0
fi

wait_for_kiosk_url

logger -t signage-cursor-vt "VT switch ${KIOSK_VT}<->${SCRATCH_VT} (cage stays running)"

for attempt in $(seq 1 "$MAX_ATTEMPTS"); do
  chvt "$SCRATCH_VT"
  sleep 1
  chvt "$KIOSK_VT"
  sleep 2

  if [[ $have_drm_debug -eq 1 ]]; then
    if cursor_plane_present; then
      logger -t signage-cursor-vt "cursor plane still present after attempt $attempt/$MAX_ATTEMPTS"
      sleep 10
      continue
    fi
    sleep "$CONFIRM_WAIT"
    if ! cursor_plane_present; then
      logger -t signage-cursor-vt "cursor plane suppressed and stable (attempt $attempt)"
      break
    fi
    logger -t signage-cursor-vt "cursor plane returned during confirm window — retry"
  else
    logger -t signage-cursor-vt "no DRM debug state — single VT cycle done (attempt $attempt)"
    break
  fi
done

systemctl stop "getty@tty${SCRATCH_VT}.service" 2>/dev/null || true
systemctl disable "getty@tty${SCRATCH_VT}.service" 2>/dev/null || true

exit 0
