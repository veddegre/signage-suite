#!/usr/bin/env bash
# Remove Pi phantom-pointer udev rules only — does not touch the kiosk launcher.
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "==> Removing phantom pointer udev rules"
rm -f /etc/udev/rules.d/99-signage-phantom-pointer.rules
rm -f /etc/signage/phantom-pointer-fixed
udevadm control --reload-rules 2>/dev/null || true
udevadm trigger --subsystem-match=input --action=change 2>/dev/null || true

systemctl restart signage.service 2>/dev/null || true

echo "Cursor fix undone. Compositor cursor may return."
