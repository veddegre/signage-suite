#!/usr/bin/env bash
# Pi kiosk cursor — cleanup only. Udev/ydotool fixes are NOT safe on all Pis (black screen).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-pi}}"

usage() {
  cat <<EOF
Pi compositor cursor (cage + HDMI phantom pointer)

setup-kiosk.sh installs signage-cursor-vt.service on Raspberry Pi (VT switch after wall loads).
Unsafe on Pi (black screen): ydotool, libinput udev ignore of vc4-hdmi-*.

Commands:
  sudo bash $0 --cleanup     Remove failed cursor experiments; restart signage
  sudo bash $0 --status       Show pointer-related config

Do NOT run old cursor "fix" scripts on a working kiosk unless you can SSH in to roll back:
  sudo bash scripts/signage-undo-cursor-pi.sh
  sudo bash scripts/signage-restore-display.sh && sudo reboot

EOF
}

cleanup() {
  echo "==> Removing cursor experiments"
  rm -f /etc/udev/rules.d/99-signage-phantom-pointer.rules
  rm -f /etc/signage/phantom-pointer-fixed
  udevadm control --reload-rules 2>/dev/null || true
  udevadm trigger --subsystem-match=input --action=change 2>/dev/null || true

  systemctl stop signage-ydotoold.service 2>/dev/null || true
  systemctl disable signage-ydotoold.service 2>/dev/null || true
  rm -f /etc/systemd/system/signage-ydotoold.service

  uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
  if [[ -n "$uid" ]]; then
    pkill -u "$uid" -f 'signage-hide-cursor' 2>/dev/null || true
    pkill -u "$uid" -x ydotoold 2>/dev/null || true
  fi
  rm -f /usr/local/bin/signage-hide-cursor /usr/local/bin/signage-hide-cursor.disabled

  systemctl daemon-reload 2>/dev/null || true
  systemctl restart signage.service 2>/dev/null || true
  echo "Cleanup done. Display should match pre-experiment state (cursor may be visible)."
}

status() {
  echo "==> Phantom udev rules"
  [[ -f /etc/udev/rules.d/99-signage-phantom-pointer.rules ]] && cat /etc/udev/rules.d/99-signage-phantom-pointer.rules || echo "(none)"
  echo
  echo "==> ydotool / hide-cursor"
  pgrep -af 'ydotool|signage-hide-cursor' 2>/dev/null || echo "(none running)"
  command -v ydotool 2>/dev/null && echo "ydotool installed: $(command -v ydotool)" || echo "ydotool not installed"
  [[ -x /usr/local/bin/signage-hide-cursor ]] && echo "signage-hide-cursor: present" || echo "signage-hide-cursor: absent"
  echo
  echo "==> signage"
  systemctl is-active signage.service 2>/dev/null || true
  grep KIOSK_URL= /etc/signage/kiosk.conf 2>/dev/null || true
  echo
  echo "==> Pi VT cursor suppress"
  if systemctl is-enabled signage-cursor-vt.service >/dev/null 2>&1; then
    systemctl is-active signage-cursor-vt.service 2>/dev/null || echo "(enabled; runs after boot / signage restart)"
  else
    echo "(not installed — re-run setup-kiosk.sh on Pi)"
  fi
}

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0 [--cleanup|--status]" >&2
  exit 1
fi

case "${1:-}" in
  --cleanup) cleanup ;;
  --status) status ;;
  --help|-h) usage ;;
  *)
    usage
    echo "Pi cursor fix is installed by setup-kiosk.sh (signage-cursor-vt.service)."
    echo "Re-run: sudo systemctl start signage-cursor-vt.service"
    exit 0
    ;;
esac
