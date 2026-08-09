#!/usr/bin/env bash
# Remove ydotool hide-cursor from a running kiosk (stops journal spam / black screen).
set -euo pipefail

KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-pi}}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "==> Stopping ydotool helpers"
systemctl stop signage-ydotoold.service 2>/dev/null || true
systemctl disable signage-ydotoold.service 2>/dev/null || true
rm -f /etc/systemd/system/signage-ydotoold.service

uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
if [[ -n "$uid" ]]; then
  pkill -u "$uid" -f '^/usr/local/bin/signage-hide-cursor' 2>/dev/null || true
  pkill -u "$uid" -x ydotoold 2>/dev/null || true
fi

echo "==> Disabling hide-cursor launcher (phantom HDMI udev rules are the Pi fix)"
if [[ -x /usr/local/bin/signage-hide-cursor ]]; then
  mv /usr/local/bin/signage-hide-cursor /usr/local/bin/signage-hide-cursor.disabled
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$SCRIPT_DIR/signage-apply-phantom-pointer-rules.sh" ]]; then
  bash "$SCRIPT_DIR/signage-apply-phantom-pointer-rules.sh"
fi

if [[ -f "$SCRIPT_DIR/../setup-kiosk.sh" && -f /etc/signage/kiosk.conf ]]; then
  echo "==> Refreshing signage-kiosk launcher"
  # shellcheck disable=SC1091
  source /etc/signage/kiosk.conf
  bash "$SCRIPT_DIR/../setup-kiosk.sh" --skip-apt \
    --server="${SIGNAGE_SERVER:-${BOARDS_URL:-}}" \
    --screen="${SCREEN:-main}" \
    ${KIOSK_SCALE:+--scale="$KIOSK_SCALE"} \
    $([[ "${KIOSK_WITH_CEC:-1}" == "0" ]] && echo --no-cec) \
    $([[ "${KIOSK_IGNORE_SSL:-1}" == "0" ]] && echo --strict-ssl) \
    || true
fi

systemctl daemon-reload
systemctl restart signage.service

echo "Done. Cursor fix uses udev only; ydotool is not used."
