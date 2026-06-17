#!/usr/bin/env bash
#
# setup-kiosk.sh — turn a fresh Raspberry Pi OS Lite (Bookworm) or Ubuntu
# Server (24.04+) box into a signage kiosk pointed at the rotation shell.
# Run once as the default user:
#
#     sudo bash setup-kiosk.sh http://your-server/boards/board.php [scale]
#
# Options:
#   --no-cec     Skip HDMI-CEC power scheduling (TV on/off from admin)
#
# The optional [scale] argument handles displays that aren't 1080p: the boards
# are designed at 1920x1080, so on a 4K display pass 2 (everything renders
# pixel-doubled and fills the screen). Omit it for a 1080p display.
#
# What it sets up:
#   * cage (a minimal Wayland kiosk compositor) running Chromium fullscreen
#   * a systemd service that starts it at boot and restarts it if it crashes
#   * a nightly 04:00 service restart to flush browser memory
#   * HDMI-CEC sync (polls admin schedule every minute via board.php?api=cec)
#
# Works on Pi 4/5 and on x86 mini PCs running Ubuntu Server — the script
# handles both distros' Chromium packaging (Ubuntu's is a snap).

set -euo pipefail

WITH_CEC=1
ARGS=()
for arg in "$@"; do
  case "$arg" in
    --no-cec) WITH_CEC=0 ;;
    *) ARGS+=("$arg") ;;
  esac
done

KIOSK_URL="${ARGS[0]:-}"
SCALE="${ARGS[1]:-1}"
if [[ -z "$KIOSK_URL" ]]; then
  echo "Usage: sudo bash setup-kiosk.sh http://server/boards/board.php [scale] [--no-cec]" >&2
  exit 1
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Derive boards base URL and ?screen= key from the kiosk URL.
KIOSK_PATH="${KIOSK_URL%%\?*}"
BOARDS_URL="$(dirname "$KIOSK_PATH")"
SCREEN=main
if [[ "$KIOSK_URL" == *"screen="* ]]; then
  SCREEN="$(printf '%s' "$KIOSK_URL" | sed -n 's/.*[?&]screen=\([^&]*\).*/\1/p' | tr '[:upper:]' '[:lower:]')"
  SCREEN="$(printf '%s' "$SCREEN" | tr -cd 'a-z0-9_-')"
fi
[[ -z "$SCREEN" ]] && SCREEN=main

KIOSK_USER="${SUDO_USER:-pi}"
echo "==> Kiosk user: $KIOSK_USER"
echo "==> Kiosk URL:  $KIOSK_URL"
echo "==> Screen key: $SCREEN"
echo "==> Boards API: $BOARDS_URL"
echo "==> Scale:      $SCALE (use 2 for a 4K display)"
echo "==> HDMI-CEC:   $([[ $WITH_CEC -eq 1 ]] && echo enabled || echo skipped)"

echo "==> Installing packages"
apt-get update -q
apt-get install -y -q cage seatd curl python3
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

if [[ $WITH_CEC -eq 1 ]]; then
  apt-get install -y -q cec-utils 2>/dev/null || echo "==> cec-utils not available — CEC scheduling disabled on this box."
fi

if [[ -f "$SCRIPT_DIR/scripts/install-signage-blank-cursor.sh" ]]; then
  echo "==> Installing transparent cursor theme (hide mouse on kiosk)"
  bash "$SCRIPT_DIR/scripts/install-signage-blank-cursor.sh"
else
  echo "==> Warning: scripts/install-signage-blank-cursor.sh not found — cursor may remain visible." >&2
fi

echo "==> Writing /etc/signage/kiosk.conf"
mkdir -p /etc/signage
cat > /etc/signage/kiosk.conf <<EOF
# Signage kiosk — used by signage-cec-sync
KIOSK_URL="$KIOSK_URL"
BOARDS_URL="$BOARDS_URL"
SCREEN="$SCREEN"
EOF
chmod 644 /etc/signage/kiosk.conf

echo "==> Writing /usr/local/bin/signage-kiosk"
cat > /usr/local/bin/signage-kiosk <<EOF
#!/usr/bin/env bash
# Launched by signage.service — cage runs Chromium as the sole fullscreen app.
export XCURSOR_THEME=signage-blank
export XCURSOR_SIZE=24
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

if [[ $WITH_CEC -eq 1 ]]; then
  echo "==> Installing signage-cec-sync"
  if [[ -f "$SCRIPT_DIR/scripts/signage-cec-sync.sh" ]]; then
    install -m 755 "$SCRIPT_DIR/scripts/signage-cec-sync.sh" /usr/local/bin/signage-cec-sync
  else
    echo "==> Warning: scripts/signage-cec-sync.sh not found — run setup from the signage-suite repo." >&2
  fi

  if [[ -x /usr/local/bin/signage-cec-sync ]]; then
  cat > /etc/systemd/system/signage-cec.service <<'EOF'
[Unit]
Description=Signage HDMI-CEC power sync

[Service]
Type=oneshot
ExecStart=/usr/local/bin/signage-cec-sync
EOF
  cat > /etc/systemd/system/signage-cec.timer <<'EOF'
[Unit]
Description=Poll signage CEC schedule every minute

[Timer]
OnBootSec=2min
OnUnitActiveSec=1min
Persistent=true

[Install]
WantedBy=timers.target
EOF
  fi
fi

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
Environment=XCURSOR_THEME=signage-blank
Environment=XCURSOR_SIZE=24
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
if [[ $WITH_CEC -eq 1 ]] && [[ -x /usr/local/bin/signage-cec-sync ]]; then
  systemctl enable signage-cec.timer
  systemctl start signage-cec.timer
fi

cat <<NOTES

============================================================
Done. Reboot to start the kiosk:  sudo reboot

Useful afterwards:
  systemctl status signage          # is it running
  journalctl -u signage -f          # watch the browser logs
  sudo systemctl restart signage    # manual restart

HDMI-CEC (TV power from admin → Rotation → Displays):
  Schedules are set per screen in admin.php (CEC / Off hr / On hr).
  This box polls every minute as screen "$SCREEN".
  Test:  sudo /usr/local/bin/signage-cec-sync
  Logs:  journalctl -u signage-cec -f
  Disable: sudo systemctl disable --now signage-cec.timer

  TV must have CEC enabled (Anynet+, Simplink, Bravia Sync, etc.).
  Re-run setup with --no-cec to skip CEC entirely.

UPDATING
The kiosk is just a browser — all content updates happen on the
server through admin.php. The Pi only needs OS updates:
  sudo apt update && sudo apt full-upgrade

CURSOR (if the mouse pointer is still visible after a server update):
  sudo bash $SCRIPT_DIR/scripts/install-signage-blank-cursor.sh
  sudo systemctl restart signage
============================================================
NOTES
