#!/usr/bin/env bash
# Install VT cursor suppress for cage kiosks (Pi + x86). Safe — does not use udev/ydotool.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

install -m 755 "$SCRIPT_DIR/signage-suppress-cursor-vt.sh" /usr/local/bin/signage-suppress-cursor-vt

cat > /etc/systemd/system/signage-cursor-vt.service <<'EOF'
[Unit]
Description=Suppress cage phantom cursor (VT switch)
After=signage.service network-online.target
Wants=signage.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/bin/signage-suppress-cursor-vt

[Install]
WantedBy=multi-user.target
EOF

systemctl disable --now getty@tty2.service 2>/dev/null || true

systemctl daemon-reload
systemctl enable signage-cursor-vt.service

if [[ "${SIGNAGE_QUIET:-0}" != "1" ]]; then
  echo "Installed signage-cursor-vt.service (runs after boot when signage is up)."
  echo "Apply now on a running kiosk:"
  echo "  sudo systemctl start signage-cursor-vt.service"
  echo "Watch: journalctl -u signage-cursor-vt -f"
  echo "Undo:  sudo systemctl disable --now signage-cursor-vt.service"
fi
