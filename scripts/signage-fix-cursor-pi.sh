#!/usr/bin/env bash
# Pi-safe compositor cursor fix — phantom HDMI udev rules only. Does NOT use ydotool.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-pi}}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "==> Pi cursor fix (libinput ignore vc4-hdmi — no ydotool, launcher unchanged)"

# ydotool breaks cage on Pi OS Trixie — remove any leftover pieces first.
systemctl stop signage-ydotoold.service 2>/dev/null || true
systemctl disable signage-ydotoold.service 2>/dev/null || true
rm -f /etc/systemd/system/signage-ydotoold.service
uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
if [[ -n "$uid" ]]; then
  pkill -u "$uid" -f 'signage-hide-cursor' 2>/dev/null || true
  pkill -u "$uid" -x ydotoold 2>/dev/null || true
fi
rm -f /usr/local/bin/signage-hide-cursor /usr/local/bin/signage-hide-cursor.disabled

bash "$SCRIPT_DIR/signage-apply-phantom-pointer-rules.sh"

if [[ -f "$SCRIPT_DIR/install-signage-blank-cursor.sh" ]]; then
  bash "$SCRIPT_DIR/install-signage-blank-cursor.sh"
fi

systemctl daemon-reload 2>/dev/null || true
echo "==> Restarting signage (no setup-kiosk re-run)"
systemctl restart signage.service

echo
echo "Done. The wall should keep working; the compositor cursor should be gone."
echo "If the cursor remains, check: libinput list-devices"
echo "Undo (keep display): sudo bash $SCRIPT_DIR/signage-undo-cursor-pi.sh"
echo "Full rollback:        sudo bash $SCRIPT_DIR/signage-restore-display.sh"
