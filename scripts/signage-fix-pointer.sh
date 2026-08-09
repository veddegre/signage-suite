#!/usr/bin/env bash
# One-shot pointer fix for cage kiosks (phantom HDMI pointer + ydotoold).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-pi}}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "==> Ensuring uinput permissions"
if [[ -f "$SCRIPT_DIR/install-ydotool.sh" ]]; then
  bash "$SCRIPT_DIR/install-ydotool.sh" || true
fi

echo "==> Ignoring phantom HDMI/CEC pointer devices"
bash "$SCRIPT_DIR/signage-apply-phantom-pointer-rules.sh"

echo "==> Ensuring kiosk user is in input group"
usermod -aG input,video,render "$KIOSK_USER" 2>/dev/null || true

echo "==> Installing ydotoold service"
cat > /etc/systemd/system/signage-ydotoold.service <<EOF
[Unit]
Description=ydotool daemon for signage kiosk
DefaultDependencies=no
Before=signage.service
After=dev-uinput.device systemd-udev-settle.service

[Service]
User=$KIOSK_USER
Group=$KIOSK_USER
SupplementaryGroups=input video render
RuntimeDirectory=ydotool
ExecStart=/usr/local/bin/ydotoold
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

if [[ -f "$SCRIPT_DIR/signage-hide-cursor.sh" ]]; then
  install -m 755 "$SCRIPT_DIR/signage-hide-cursor.sh" /usr/local/bin/signage-hide-cursor
fi
if [[ -f "$SCRIPT_DIR/install-signage-blank-cursor.sh" ]]; then
  bash "$SCRIPT_DIR/install-signage-blank-cursor.sh"
fi

systemctl daemon-reload
systemctl enable signage-ydotoold.service 2>/dev/null || true
systemctl restart signage-ydotoold.service 2>/dev/null || true

if [[ -f "$ROOT/setup-kiosk.sh" && -f /etc/signage/kiosk.conf ]]; then
  echo "==> Refreshing kiosk launcher"
  # shellcheck disable=SC1091
  source /etc/signage/kiosk.conf
  bash "$ROOT/setup-kiosk.sh" --skip-apt \
    --server="${SIGNAGE_SERVER:-}" \
    --screen="${SCREEN:-main}" \
    ${KIOSK_SCALE:+--scale="$KIOSK_SCALE"} \
    $([[ "${KIOSK_WITH_CEC:-1}" == "0" ]] && echo --no-cec) \
    $([[ "${KIOSK_IGNORE_SSL:-1}" == "0" ]] && echo --strict-ssl) \
    || true
fi

echo "==> Restarting signage"
systemctl restart signage.service

echo
echo "Done. If a cursor remains, run:"
echo "  sudo bash $SCRIPT_DIR/signage-diagnose-pointer.sh"
echo "  libinput list-devices"
