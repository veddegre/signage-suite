#!/usr/bin/env bash
# Fullscreen cage + Chromium kiosk — installed to /usr/local/bin/signage-kiosk by setup-kiosk.sh
set -euo pipefail

CLI_URL="${1:-}"
SCALE="1"
IGNORE_SSL="1"

if [[ -f /etc/signage/kiosk.conf ]]; then
  # shellcheck disable=SC1091
  source /etc/signage/kiosk.conf
  SCALE="${KIOSK_SCALE:-1}"
  IGNORE_SSL="${KIOSK_IGNORE_SSL:-1}"
fi

KIOSK_URL="${CLI_URL:-${KIOSK_URL:-}}"
if [[ -z "$KIOSK_URL" ]]; then
  logger -t signage-kiosk "FATAL: KIOSK_URL empty — check /etc/signage/kiosk.conf"
  exit 1
fi

export XCURSOR_PATH=/usr/share/icons
export XCURSOR_THEME=signage-blank
export XCURSOR_SIZE=24

signage_kiosk_blackout_tty() {
  if command -v setterm >/dev/null 2>&1; then
    setterm -background black -foreground black -clear all >/dev/tty1 2>/dev/null || true
  fi
  printf '\033[40m\033[2J\033[H\033[?25l' >/dev/tty1 2>/dev/null || true
}

signage_kiosk_chromium() {
  local b=""
  b="$(command -v chromium-browser 2>/dev/null || true)"
  [[ -n "$b" ]] && { printf '%s' "$b"; return 0; }
  b="$(command -v chromium 2>/dev/null || true)"
  [[ -n "$b" ]] && { printf '%s' "$b"; return 0; }
  [[ -x /snap/bin/chromium ]] && { printf '%s' /snap/bin/chromium; return 0; }
  return 1
}

CHROMIUM="$(signage_kiosk_chromium || true)"
if [[ -z "$CHROMIUM" ]]; then
  logger -t signage-kiosk "FATAL: Chromium not found — apt install chromium-browser"
  signage_kiosk_blackout_tty
  sleep 30
  exit 1
fi

if command -v signage-kiosk-wait-for-runtime >/dev/null; then
  signage-kiosk-wait-for-runtime 30
fi
if command -v signage-kiosk-wait-for-server >/dev/null; then
  signage-kiosk-wait-for-server 240
fi

# Phantom HDMI udev rules are the Pi cursor fix — skip ydotool hide-cursor.
if [[ ! -f /etc/signage/phantom-pointer-fixed ]] \
   && [[ ! -f /etc/udev/rules.d/99-signage-phantom-pointer.rules ]] \
   && [[ -x /usr/local/bin/signage-hide-cursor ]]; then
  pkill -u "$(id -u)" -f '^/usr/local/bin/signage-hide-cursor' 2>/dev/null || true
  /usr/local/bin/signage-hide-cursor &
fi

SSL_ARGS=()
if [[ "$IGNORE_SSL" == "1" ]]; then
  SSL_ARGS+=(--ignore-certificate-errors --allow-insecure-localhost)
fi

logger -t signage-kiosk "starting cage browser=$CHROMIUM url=$KIOSK_URL scale=$SCALE"

while true; do
  set +e
  cage -- "$CHROMIUM" \
    --kiosk "$KIOSK_URL" \
    --force-device-scale-factor="$SCALE" \
    --noerrdialogs \
    --disable-infobars \
    --disable-session-crashed-bubble \
    --disable-features=TranslateUI \
    --disable-dev-shm-usage \
    --autoplay-policy=no-user-gesture-required \
    --check-for-update-interval=31536000 \
    --ozone-platform=wayland \
    "${SSL_ARGS[@]}" \
    --start-fullscreen
  rc=$?
  set -e
  logger -t signage-kiosk "cage exited rc=$rc — restarting in 2s"
  signage_kiosk_blackout_tty
  if command -v signage-kiosk-wait-for-server >/dev/null; then
    signage-kiosk-wait-for-server 30
  fi
  sleep 2
done
