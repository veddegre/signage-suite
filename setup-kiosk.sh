#!/usr/bin/env bash
#
# setup-kiosk.sh — turn a fresh Raspberry Pi OS Lite (Bookworm) or Ubuntu
# Server (24.04+) box into a signage kiosk pointed at the rotation shell.
# Run once as the default user:
#
#     sudo bash setup-kiosk.sh http://your-server/boards/board.php [scale]
#
# The optional [scale] argument handles displays that aren't 1080p: the boards
# are designed at 1920x1080, so on a 4K display pass 2 (everything renders
# pixel-doubled and fills the screen). Omit it for a 1080p display.
#
# What it sets up:
#   * cage (a minimal Wayland kiosk compositor) running Chromium fullscreen
#   * a systemd service that starts it at boot and restarts it if it crashes
#   * a nightly 04:00 service restart to flush browser memory
#   * optional screen on/off schedule (see SCREEN SCHEDULE below)
#
# Works on Pi 4/5 and on x86 mini PCs running Ubuntu Server — the script
# handles both distros' Chromium packaging (Ubuntu's is a snap).

set -euo pipefail

KIOSK_URL="${1:-}"
SCALE="${2:-1}"
if [[ -z "$KIOSK_URL" ]]; then
  echo "Usage: sudo bash setup-kiosk.sh http://server/boards/board.php [scale]" >&2
  exit 1
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo." >&2
  exit 1
fi

KIOSK_USER="${SUDO_USER:-pi}"
echo "==> Kiosk user: $KIOSK_USER"
echo "==> Kiosk URL:  $KIOSK_URL"
echo "==> Scale:      $SCALE (use 2 for a 4K display)"

echo "==> Installing packages"
apt-get update -q
apt-get install -y -q cage seatd
# Chromium packaging differs by distro: Pi OS has a real deb named
# chromium-browser; Ubuntu's chromium-browser/chromium packages are snap
# shims. Try them in order, then fall back to installing the snap directly.
apt-get install -y -q chromium-browser 2>/dev/null \
  || apt-get install -y -q chromium 2>/dev/null \
  || snap install chromium
CHROMIUM=$(command -v chromium-browser || command -v chromium || echo /snap/bin/chromium)
if [[ ! -x "$CHROMIUM" ]]; then
  echo "Could not find a Chromium binary after install — aborting." >&2
  exit 1
fi
echo "==> Using browser: $CHROMIUM"
usermod -aG video,render,input "$KIOSK_USER"

echo "==> Writing /usr/local/bin/signage-kiosk"
cat > /usr/local/bin/signage-kiosk <<EOF
#!/usr/bin/env bash
# Launched by signage.service — cage runs Chromium as the sole fullscreen app.
exec cage -- "$CHROMIUM" \\
  --kiosk "\$1" \\
  --force-device-scale-factor=$SCALE \\
  --noerrdialogs \\
  --disable-infobars \\
  --disable-session-crashed-bubble \\
  --disable-features=TranslateUI \\
  --autoplay-policy=no-user-gesture-required \\
  --check-for-update-interval=31536000 \\
  --enable-features=VaapiVideoDecoder \\
  --ozone-platform=wayland \\
  --start-fullscreen
EOF
chmod +x /usr/local/bin/signage-kiosk

echo "==> Writing systemd service"
cat > /etc/systemd/system/signage.service <<EOF
[Unit]
Description=Signage kiosk (cage + Chromium)
After=network-online.target systemd-user-sessions.service
Wants=network-online.target

[Service]
User=$KIOSK_USER
PAMName=login
TTYPath=/dev/tty1
StandardInput=tty
StandardOutput=journal
Environment=XDG_RUNTIME_DIR=/run/user/%U
ExecStart=/usr/local/bin/signage-kiosk "$KIOSK_URL"
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

echo "==> Nightly restart timer (04:00) to flush browser memory"
cat > /etc/systemd/system/signage-restart.service <<'EOF'
[Unit]
Description=Restart signage kiosk

[Service]
Type=oneshot
ExecStart=/usr/bin/systemctl restart signage.service
EOF
cat > /etc/systemd/system/signage-restart.timer <<'EOF'
[Unit]
Description=Nightly signage restart

[Timer]
OnCalendar=*-*-* 04:00:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

echo "==> Disabling console getty on tty1 (kiosk owns the display)"
systemctl disable --now getty@tty1.service || true

systemctl daemon-reload
systemctl enable signage.service signage-restart.timer
systemctl start signage-restart.timer

cat <<'NOTES'

============================================================
Done. Reboot to start the kiosk:  sudo reboot

Useful afterwards:
  systemctl status signage          # is it running
  journalctl -u signage -f          # watch the browser logs
  sudo systemctl restart signage    # manual restart

SCREEN SCHEDULE (optional)
Turn the display off at night without stopping the rotation.
HDMI-CEC (best, if the TV supports it — apt install cec-utils):
  0 23 * * *  echo 'standby 0' | cec-client -s -d 1
  0 6  * * *  echo 'on 0'      | cec-client -s -d 1
DPMS via wlr-randr inside the cage session is fiddly; CEC or the
display's own sleep timer is the reliable route. Add the lines
with:  sudo crontab -e

UPDATING
The kiosk is just a browser — all content updates happen on the
server through admin.php. The Pi only ever needs OS updates:
  sudo apt update && sudo apt full-upgrade
============================================================
NOTES
