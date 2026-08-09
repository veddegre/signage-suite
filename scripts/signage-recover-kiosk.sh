#!/usr/bin/env bash
# Undo pointer experiments and restart the kiosk (black screen recovery).
set -euo pipefail

KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-pi}}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "==> Stopping pointer helpers (ydotool can destabilize cage on some Pis)"
systemctl stop signage-ydotoold.service 2>/dev/null || true
systemctl disable signage-ydotoold.service 2>/dev/null || true
if [[ -n "$KIOSK_USER" ]]; then
  uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
  if [[ -n "$uid" ]]; then
    pkill -u "$uid" -f '^/usr/local/bin/signage-hide-cursor' 2>/dev/null || true
    pkill -u "$uid" -x ydotoold 2>/dev/null || true
  fi
fi

if [[ "${1:-}" == "--remove-phantom-rules" ]]; then
  echo "==> Removing phantom pointer udev rules"
  rm -f /etc/udev/rules.d/99-signage-phantom-pointer.rules
  rm -f /etc/signage/phantom-pointer-fixed
  udevadm control --reload-rules 2>/dev/null || true
  udevadm trigger --subsystem-match=input --action=change 2>/dev/null || true
else
  echo "==> Keeping phantom HDMI udev rules (cursor fix). Pass --remove-phantom-rules to drop them."
fi

echo "==> Restarting signage"
systemctl restart signage.service

echo
echo "If the screen is still black, check:"
echo "  systemctl status signage.service"
echo "  journalctl -u signage -n 60 --no-pager"
echo "  grep KIOSK_URL /etc/signage/kiosk.conf"
echo "  curl -k \"\$(grep KIOSK_URL= /etc/signage/kiosk.conf | cut -d= -f2- | tr -d '\"')\""
