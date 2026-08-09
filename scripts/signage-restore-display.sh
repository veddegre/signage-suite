#!/usr/bin/env bash
# Restore kiosk display after pointer-fix experiments broke the wall.
set -euo pipefail

KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-pi}}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "==> Removing pointer-fix udev rules"
rm -f /etc/udev/rules.d/99-signage-phantom-pointer.rules
rm -f /etc/signage/phantom-pointer-fixed
udevadm control --reload-rules 2>/dev/null || true
udevadm trigger --subsystem-match=input --action=change 2>/dev/null || true

echo "==> Stopping ydotool / hide-cursor"
systemctl stop signage-ydotoold.service 2>/dev/null || true
systemctl disable signage-ydotoold.service 2>/dev/null || true
rm -f /etc/systemd/system/signage-ydotoold.service
uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
if [[ -n "$uid" ]]; then
  pkill -u "$uid" -f 'signage-hide-cursor' 2>/dev/null || true
  pkill -u "$uid" -x ydotoold 2>/dev/null || true
fi
rm -f /usr/local/bin/signage-hide-cursor /usr/local/bin/signage-hide-cursor.disabled

if [[ ! -f /etc/signage/kiosk.conf ]]; then
  echo "Missing /etc/signage/kiosk.conf" >&2
  exit 1
fi

# shellcheck disable=SC1091
source /etc/signage/kiosk.conf

CHROMIUM="$(command -v chromium-browser 2>/dev/null || command -v chromium 2>/dev/null || true)"
[[ -z "$CHROMIUM" && -x /snap/bin/chromium ]] && CHROMIUM=/snap/bin/chromium
if [[ -z "$CHROMIUM" ]]; then
  echo "Chromium not found" >&2
  exit 1
fi

SCALE="${KIOSK_SCALE:-1}"
IGNORE_SSL="${KIOSK_IGNORE_SSL:-1}"
URL="${KIOSK_URL:-}"

echo "==> Installing original-style signage-kiosk (Vaapi, no ydotool)"
if [[ "$IGNORE_SSL" == "1" ]]; then
  cat > /usr/local/bin/signage-kiosk <<EOF
#!/usr/bin/env bash
set -euo pipefail
export XCURSOR_THEME=signage-blank
export XCURSOR_SIZE=24

signage_kiosk_blackout_tty() {
  if command -v setterm >/dev/null 2>&1; then
    setterm -background black -foreground black -clear all >/dev/tty1 2>/dev/null || true
  fi
  printf '\033[40m\033[2J\033[H\033[?25l' >/dev/tty1 2>/dev/null || true
}

signage_kiosk_blackout_tty

if command -v signage-kiosk-wait-for-runtime >/dev/null; then
  signage-kiosk-wait-for-runtime 30
fi
if command -v signage-kiosk-wait-for-server >/dev/null; then
  signage-kiosk-wait-for-server 240
fi

KIOSK_URL="\$1"
while true; do
  cage -- "$CHROMIUM" \\
    --kiosk "\$KIOSK_URL" \\
    --force-device-scale-factor=$SCALE \\
    --noerrdialogs \\
    --disable-infobars \\
    --disable-session-crashed-bubble \\
    --disable-features=TranslateUI \\
    --disable-dev-shm-usage \\
    --autoplay-policy=no-user-gesture-required \\
    --check-for-update-interval=31536000 \\
    --enable-features=VaapiVideoDecoder \\
    --ozone-platform=wayland \\
    --ignore-certificate-errors \\
    --allow-insecure-localhost \\
    --start-fullscreen || true
  signage_kiosk_blackout_tty
  if command -v signage-kiosk-wait-for-server >/dev/null; then
    signage-kiosk-wait-for-server 60
  fi
  sleep 1
done
EOF
else
  cat > /usr/local/bin/signage-kiosk <<EOF
#!/usr/bin/env bash
set -euo pipefail
export XCURSOR_THEME=signage-blank
export XCURSOR_SIZE=24

signage_kiosk_blackout_tty() {
  if command -v setterm >/dev/null 2>&1; then
    setterm -background black -foreground black -clear all >/dev/tty1 2>/dev/null || true
  fi
  printf '\033[40m\033[2J\033[H\033[?25l' >/dev/tty1 2>/dev/null || true
}

signage_kiosk_blackout_tty

if command -v signage-kiosk-wait-for-runtime >/dev/null; then
  signage-kiosk-wait-for-runtime 30
fi
if command -v signage-kiosk-wait-for-server >/dev/null; then
  signage-kiosk-wait-for-server 240
fi

KIOSK_URL="\$1"
while true; do
  cage -- "$CHROMIUM" \\
    --kiosk "\$KIOSK_URL" \\
    --force-device-scale-factor=$SCALE \\
    --noerrdialogs \\
    --disable-infobars \\
    --disable-session-crashed-bubble \\
    --disable-features=TranslateUI \\
    --disable-dev-shm-usage \\
    --autoplay-policy=no-user-gesture-required \\
    --check-for-update-interval=31536000 \\
    --enable-features=VaapiVideoDecoder \\
    --ozone-platform=wayland \\
    --start-fullscreen || true
  signage_kiosk_blackout_tty
  if command -v signage-kiosk-wait-for-server >/dev/null; then
    signage-kiosk-wait-for-server 60
  fi
  sleep 1
done
EOF
fi
chmod +x /usr/local/bin/signage-kiosk

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SIGNAGE_EXEC_START_POST=""
if [[ -f /proc/device-tree/model ]] && grep -qi 'raspberry pi' /proc/device-tree/model 2>/dev/null; then
  if [[ -f "$SCRIPT_DIR/signage-install-cursor-vt-fix.sh" ]]; then
    echo "==> Installing Pi phantom cursor suppress (VT switch)"
    SIGNAGE_QUIET=1 bash "$SCRIPT_DIR/signage-install-cursor-vt-fix.sh"
    SIGNAGE_EXEC_START_POST="ExecStartPost=-/bin/systemctl start signage-cursor-vt.service"
    systemctl enable signage-cursor-vt.service 2>/dev/null || true
  fi
fi

cat > /etc/systemd/system/signage.service <<EOF
[Unit]
Description=Signage kiosk (cage + Chromium)
After=network-online.target systemd-user-sessions.service seatd.service
Wants=network-online.target seatd.service

[Service]
User=$KIOSK_USER
PAMName=login
TTYPath=/dev/tty1
StandardInput=tty
StandardOutput=journal
Environment=XDG_RUNTIME_DIR=/run/user/%U
Environment=XCURSOR_THEME=signage-blank
Environment=XCURSOR_SIZE=24
ExecStart=/usr/local/bin/signage-kiosk "$URL"
${SIGNAGE_EXEC_START_POST}
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl restart signage.service
if systemctl is-enabled signage-cursor-vt.service >/dev/null 2>&1; then
  systemctl start signage-cursor-vt.service 2>/dev/null || true
fi

echo
echo "Restored. URL: $URL"
echo "Browser: $CHROMIUM"
if [[ -n "$SIGNAGE_EXEC_START_POST" ]]; then
  echo "Pi cursor suppress: enabled (VT switch ~2 min after wall is up)"
else
  echo "Pointer may be visible on Pi until setup-kiosk.sh is re-run."
fi
echo "If still black after 10s: sudo reboot"
