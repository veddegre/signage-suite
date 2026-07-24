#!/usr/bin/env bash
#
# setup-kiosk.sh — turn a fresh Raspberry Pi OS Lite (Bookworm) or Ubuntu
# Server (24.04+) box into a signage kiosk pointed at the rotation shell.
# Run once as the default user:
#
#     sudo bash setup-kiosk.sh http://your-server/boards/board.php [scale] [--no-cec]
#
# Options:
#   --no-cec           Skip HDMI-CEC power scheduling (TV on/off from admin)
#   --no-auto-update   Skip unattended-upgrades and nightly update/reboot timers
#   --repo-path=DIR    Git checkout to pull for kiosk script updates (default: this repo if .git)
#   --update-time=HH:MM  Daily apt + git pull (default 03:30)
#   --maint-time=HH:MM   Daily reboot-if-needed else browser restart (default 04:00)
#   --skip-apt         Internal: refresh systemd/scripts only (used by signage-kiosk-update.sh)
#
# Full guide: docs/kiosk-setup.md
#
# The optional [scale] argument handles displays that aren't 1080p: the boards
# are designed at 1920x1080, so on a 4K display pass 2 (everything renders
# pixel-doubled and fills the screen). Omit it for a 1080p display.
#
# What it sets up:
#   * cage (a minimal Wayland kiosk compositor) running Chromium fullscreen
#   * a systemd service that starts it at boot and restarts it if it crashes
#   * nightly OS updates + optional git pull (signage-update.timer)
#   * scheduled maintenance reboot or browser restart (signage-maint.timer)
#   * HDMI-CEC sync (polls admin schedule every minute via board.php?api=cec)
#
# Works on Pi 4/5 and on x86 mini PCs running Ubuntu Server — the script
# handles both distros' Chromium packaging (Ubuntu's is a snap).

set -euo pipefail

WITH_CEC=1
AUTO_UPDATE=1
SKIP_APT=0
FROM_UPDATE=0
REPO_PATH=""
UPDATE_TIME="03:30"
MAINT_TIME="04:00"
ARGS=()
for arg in "$@"; do
  case "$arg" in
    --no-cec) WITH_CEC=0 ;;
    --no-auto-update) AUTO_UPDATE=0 ;;
    --skip-apt) SKIP_APT=1 ;;
    --from-update) FROM_UPDATE=1; SKIP_APT=1 ;;
    --repo-path=*) REPO_PATH="${arg#*=}" ;;
    --update-time=*) UPDATE_TIME="${arg#*=}" ;;
    --maint-time=*) MAINT_TIME="${arg#*=}" ;;
    *) ARGS+=("$arg") ;;
  esac
done

KIOSK_URL="${ARGS[0]:-}"
SCALE="${ARGS[1]:-1}"
if [[ -z "$KIOSK_URL" ]]; then
  echo "Usage: sudo bash setup-kiosk.sh http://server/boards/board.php [scale] [--no-cec] [--no-auto-update] [--repo-path=DIR] [--update-time=HH:MM] [--maint-time=HH:MM]" >&2
  exit 1
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "$REPO_PATH" && -d "$SCRIPT_DIR/.git" ]]; then
  REPO_PATH="$SCRIPT_DIR"
fi

calendar_time() {
  local t="$1"
  if [[ ! "$t" =~ ^([0-9]{1,2}):([0-9]{2})$ ]]; then
    echo "Invalid time (use HH:MM): $t" >&2
    exit 1
  fi
  local h="${BASH_REMATCH[1]}"
  local m="${BASH_REMATCH[2]}"
  h=$((10#$h))
  m=$((10#$m))
  printf '*-*-* %02d:%02d:00' "$h" "$m"
}

UPDATE_CAL="$(calendar_time "$UPDATE_TIME")"
MAINT_CAL="$(calendar_time "$MAINT_TIME")"

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
echo "==> Auto update: $([[ $AUTO_UPDATE -eq 1 ]] && echo "on ($UPDATE_TIME apt/git, $MAINT_TIME maint)" || echo disabled)"
[[ -n "$REPO_PATH" ]] && echo "==> Git repo:   $REPO_PATH"

CHROMIUM=""
if [[ $SKIP_APT -eq 0 ]]; then
echo "==> Installing packages"
apt-get update -q
apt-get install -y -q cage seatd curl python3 ydotool
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
echo "==> Purging CUPS (no printing on a kiosk)"
apt-get purge -y -q cups cups-daemon cups-browsed cups-common 2>/dev/null || true
apt-get autoremove -y -q --purge 2>/dev/null || true
systemctl disable --now cups.service cups.socket cups-browsed.service 2>/dev/null || true
usermod -aG video,render,input "$KIOSK_USER"

if [[ $WITH_CEC -eq 1 ]]; then
  apt-get install -y -q cec-utils 2>/dev/null || echo "==> cec-utils not available — CEC scheduling disabled on this box."
fi

if [[ $AUTO_UPDATE -eq 1 ]]; then
  echo "==> Configuring unattended security upgrades"
  apt-get install -y -q unattended-upgrades apt-listchanges 2>/dev/null || true
  cat > /etc/apt/apt.conf.d/20signage-auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
EOF
  mkdir -p /etc/apt/apt.conf.d
  if [[ ! -f /etc/apt/apt.conf.d/50unattended-upgrades ]]; then
    dpkg-reconfigure -f noninteractive unattended-upgrades 2>/dev/null || true
  fi
fi
else
echo "==> Skipping package install (--skip-apt)"
CHROMIUM=$(command -v chromium-browser || command -v chromium || echo /snap/bin/chromium)
if [[ ! -x "$CHROMIUM" ]]; then
  CHROMIUM=/snap/bin/chromium
fi
if [[ ! -x "$CHROMIUM" ]]; then
  echo "Could not find Chromium — run setup without --skip-apt once." >&2
  exit 1
fi
echo "==> Using browser: $CHROMIUM"
fi

if [[ -f "$SCRIPT_DIR/scripts/install-signage-blank-cursor.sh" ]]; then
  echo "==> Installing transparent cursor theme (hide mouse on kiosk)"
  bash "$SCRIPT_DIR/scripts/install-signage-blank-cursor.sh"
else
  echo "==> Warning: scripts/install-signage-blank-cursor.sh not found — cursor may remain visible." >&2
fi

if [[ -f "$SCRIPT_DIR/scripts/signage-hide-cursor.sh" ]]; then
  echo "==> Installing pointer off-screen helper (cage compositor cursor)"
  install -m 755 "$SCRIPT_DIR/scripts/signage-hide-cursor.sh" /usr/local/bin/signage-hide-cursor
else
  echo "==> Warning: scripts/signage-hide-cursor.sh not found — compositor cursor may remain visible." >&2
fi

echo "==> Writing /etc/signage/kiosk.conf"
mkdir -p /etc/signage
cat > /etc/signage/kiosk.conf <<EOF
# Signage kiosk — sourced by CEC sync, watchdog, and update scripts
KIOSK_URL="$KIOSK_URL"
BOARDS_URL="$BOARDS_URL"
SCREEN="$SCREEN"
KIOSK_SCALE="$SCALE"
KIOSK_WITH_CEC="$WITH_CEC"
SIGNAGE_AUTO_UPDATE="$AUTO_UPDATE"
SIGNAGE_REPO="$REPO_PATH"
SIGNAGE_UPDATE_TIME="$UPDATE_TIME"
SIGNAGE_MAINT_TIME="$MAINT_TIME"
EOF
chmod 644 /etc/signage/kiosk.conf

echo "==> Writing /usr/local/bin/signage-kiosk"
cat > /usr/local/bin/signage-kiosk <<EOF
#!/usr/bin/env bash
# Launched by signage.service — cage runs Chromium as the sole fullscreen app.
export XCURSOR_THEME=signage-blank
export XCURSOR_SIZE=24

# Cage always draws a compositor cursor when a pointer device is present.
# Park it off-screen (ydotool) — CSS / blank Xcursor are not enough alone.
if command -v signage-hide-cursor >/dev/null; then
  pkill -u "\$(id -u)" -f '^/usr/local/bin/signage-hide-cursor' 2>/dev/null || true
  signage-hide-cursor &
fi

exec cage -- "$CHROMIUM" \\
  --kiosk "\$1" \\
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

echo "==> Nightly maintenance timers"
if [[ -f "$SCRIPT_DIR/scripts/signage-kiosk-update.sh" ]]; then
  install -m 755 "$SCRIPT_DIR/scripts/signage-kiosk-update.sh" /usr/local/bin/signage-kiosk-update
fi
if [[ -f "$SCRIPT_DIR/scripts/signage-kiosk-maint.sh" ]]; then
  install -m 755 "$SCRIPT_DIR/scripts/signage-kiosk-maint.sh" /usr/local/bin/signage-kiosk-maint
fi

systemctl disable --now signage-restart.timer 2>/dev/null || true

if [[ $AUTO_UPDATE -eq 1 ]] && [[ -x /usr/local/bin/signage-kiosk-update ]] && [[ -x /usr/local/bin/signage-kiosk-maint ]]; then
  cat > /etc/systemd/system/signage-update.service <<'EOF'
[Unit]
Description=Signage kiosk OS and git update

[Service]
Type=oneshot
ExecStart=/usr/local/bin/signage-kiosk-update
EOF
  cat > /etc/systemd/system/signage-update.timer <<EOF
[Unit]
Description=Daily signage OS/git update

[Timer]
OnCalendar=$UPDATE_CAL
Persistent=true

[Install]
WantedBy=timers.target
EOF
  cat > /etc/systemd/system/signage-maint.service <<'EOF'
[Unit]
Description=Signage kiosk maintenance (reboot or browser restart)

[Service]
Type=oneshot
ExecStart=/usr/local/bin/signage-kiosk-maint
EOF
  cat > /etc/systemd/system/signage-maint.timer <<EOF
[Unit]
Description=Daily signage maintenance window

[Timer]
OnCalendar=$MAINT_CAL
Persistent=true

[Install]
WantedBy=timers.target
EOF
else
  echo "==> Legacy nightly browser restart only (04:00, no auto apt/git)"
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
fi

if [[ -f "$SCRIPT_DIR/scripts/signage-kiosk-watchdog.sh" ]]; then
  echo "==> Installing kiosk health watchdog (every 5 min)"
  install -m 755 "$SCRIPT_DIR/scripts/signage-kiosk-watchdog.sh" /usr/local/bin/signage-kiosk-watchdog
  cat > /etc/systemd/system/signage-watchdog.service <<'EOF'
[Unit]
Description=Signage kiosk health check

[Service]
Type=oneshot
ExecStart=/usr/local/bin/signage-kiosk-watchdog
EOF
  cat > /etc/systemd/system/signage-watchdog.timer <<'EOF'
[Unit]
Description=Poll signage kiosk health every 5 minutes

[Timer]
OnBootSec=5min
OnUnitActiveSec=5min
Persistent=true

[Install]
WantedBy=timers.target
EOF
fi

echo "==> Disabling console getty on tty1 (kiosk owns the display)"
systemctl disable --now getty@tty1.service || true

systemctl daemon-reload
systemctl enable signage.service
if [[ $AUTO_UPDATE -eq 1 ]] && [[ -x /usr/local/bin/signage-kiosk-update ]] && [[ -x /usr/local/bin/signage-kiosk-maint ]]; then
  systemctl enable signage-update.timer signage-maint.timer
  systemctl start signage-update.timer signage-maint.timer
else
  systemctl enable signage-restart.timer
  systemctl start signage-restart.timer
fi
if [[ -x /usr/local/bin/signage-kiosk-watchdog ]]; then
  systemctl enable signage-watchdog.timer
  systemctl start signage-watchdog.timer
fi
if [[ $WITH_CEC -eq 1 ]] && [[ -x /usr/local/bin/signage-cec-sync ]]; then
  systemctl enable signage-cec.timer
  systemctl start signage-cec.timer
fi

if [[ $FROM_UPDATE -eq 0 ]]; then
cat <<NOTES

============================================================
Done. Reboot to start the kiosk:  sudo reboot

Useful afterwards:
  systemctl status signage          # is it running
  journalctl -u signage -f          # watch the browser logs
  sudo systemctl restart signage    # manual restart

AUTO UPDATES (default on)
  $UPDATE_TIME daily — apt upgrade + git pull in SIGNAGE_REPO (if set)
  $MAINT_TIME daily — reboot when kernel/packages need it, else restart browser
  unattended-upgrades — security patches between nightly runs
  Timers:  systemctl list-timers 'signage-*'
  Logs:    journalctl -u signage-update -u signage-maint
  Disable: re-run setup with --no-auto-update

HDMI-CEC (TV power from admin → Rotation → Displays):
  Schedules are set per screen in admin.php (CEC / Off hr / On hr).
  This box polls every minute as screen "$SCREEN".
  Test:  sudo /usr/local/bin/signage-cec-sync
  Logs:  journalctl -u signage-cec -f
  Disable: sudo systemctl disable --now signage-cec.timer

  TV must have CEC enabled (Anynet+, Simplink, Bravia Sync, etc.).
  Re-run setup with --no-cec to skip CEC entirely.

GIT / SCRIPT UPDATES
  Clone signage-suite on the Pi and re-run setup once — SIGNAGE_REPO is saved in
  /etc/signage/kiosk.conf. Nightly git pull re-runs setup-kiosk.sh (--skip-apt).
  Content on the wall still comes from the server (admin.php).

CURSOR (if the mouse pointer is still visible after a server update):
  sudo apt install -y ydotool
  sudo bash $SCRIPT_DIR/scripts/install-signage-blank-cursor.sh
  sudo install -m 755 $SCRIPT_DIR/scripts/signage-hide-cursor.sh /usr/local/bin/signage-hide-cursor
  sudo systemctl restart signage

WATCHDOG (auto-restart if the browser stops serving board.php):
  systemctl status signage-watchdog.timer
  journalctl -u signage-watchdog -f

Docs: docs/kiosk-setup.md
============================================================
NOTES
fi
