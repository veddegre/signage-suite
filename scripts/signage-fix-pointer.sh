#!/usr/bin/env bash
# Hide compositor cursor on Pi kiosks — phantom HDMI udev rules first; ydotool only as fallback.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-pi}}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "==> Applying phantom HDMI pointer udev rules (preferred fix — no ydotool)"
bash "$SCRIPT_DIR/signage-apply-phantom-pointer-rules.sh"

echo "==> Stopping ydotool helpers (they add a virtual pointer and can black-screen cage)"
systemctl stop signage-ydotoold.service 2>/dev/null || true
systemctl disable signage-ydotoold.service 2>/dev/null || true
rm -f /etc/systemd/system/signage-ydotoold.service
if [[ -n "$KIOSK_USER" ]]; then
  uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
  if [[ -n "$uid" ]]; then
    pkill -u "$uid" -f '^/usr/local/bin/signage-hide-cursor' 2>/dev/null || true
    pkill -u "$uid" -x ydotoold 2>/dev/null || true
  fi
fi

if [[ -f "$SCRIPT_DIR/install-signage-blank-cursor.sh" ]]; then
  bash "$SCRIPT_DIR/install-signage-blank-cursor.sh"
fi

systemctl daemon-reload
echo "==> Restarting signage (reboot once if the cursor or black screen persists)"
systemctl restart signage.service

echo
echo "Recovery if needed: sudo bash $SCRIPT_DIR/signage-recover-kiosk.sh"
echo "Diagnose:          sudo bash $SCRIPT_DIR/signage-diagnose-pointer.sh"
