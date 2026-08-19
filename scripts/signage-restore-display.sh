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

echo "==> Stopping pointer helpers"
systemctl stop signage-ydotoold.service 2>/dev/null || true
systemctl disable signage-ydotoold.service 2>/dev/null || true
rm -f /etc/systemd/system/signage-ydotoold.service
uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
if [[ -n "$uid" ]]; then
  pkill -u "$uid" -f 'signage-hide-cursor' 2>/dev/null || true
  pkill -u "$uid" -x ydotoold 2>/dev/null || true
fi
IS_PI=0
if [[ -f /proc/device-tree/model ]] && grep -qi 'raspberry pi' /proc/device-tree/model 2>/dev/null; then
  IS_PI=1
  rm -f /usr/local/bin/signage-hide-cursor /usr/local/bin/signage-hide-cursor.disabled
fi

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
KIOSK_UID="$(id -u "$KIOSK_USER")"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> Installing signage-kiosk launcher"
if [[ -f "$SCRIPT_DIR/signage-kiosk-launcher.sh" ]]; then
  install -m 755 "$SCRIPT_DIR/signage-kiosk-launcher.sh" /usr/local/bin/signage-kiosk
else
  echo "signage-kiosk-launcher.sh not found — re-run setup-kiosk.sh instead." >&2
  exit 1
fi

for helper in signage-kiosk-wait-for-server.sh signage-kiosk-wait-for-runtime.sh signage-kiosk-wait-for-display.sh signage-kiosk-boot-retry.sh; do
  if [[ -f "$SCRIPT_DIR/$helper" ]]; then
    install -m 755 "$SCRIPT_DIR/$helper" "/usr/local/bin/${helper%.sh}"
  fi
done

if [[ "$IS_PI" -eq 0 ]] && [[ -f "$SCRIPT_DIR/signage-hide-cursor.sh" ]]; then
  echo "==> Restoring x86 cursor hide (signage-hide-cursor)"
  install -m 755 "$SCRIPT_DIR/signage-hide-cursor.sh" /usr/local/bin/signage-hide-cursor
fi

SIGNAGE_EXEC_START_POST=""
if [[ -f "$SCRIPT_DIR/signage-install-cursor-vt-fix.sh" ]]; then
  echo "==> Installing cage cursor suppress (VT switch)"
  SIGNAGE_QUIET=1 bash "$SCRIPT_DIR/signage-install-cursor-vt-fix.sh"
  SIGNAGE_EXEC_START_POST="ExecStartPost=-/bin/systemctl start signage-cursor-vt.service"
  systemctl enable signage-cursor-vt.service 2>/dev/null || true
fi

SIGNAGE_AFTER="network-online.target systemd-logind.service systemd-user-sessions.service seatd.service user@${KIOSK_UID}.service"
SIGNAGE_WANTS="network-online.target seatd.service user@${KIOSK_UID}.service"
SIGNAGE_REQUIRES="seatd.service"
SIGNAGE_EXEC_START_PRE=""
if [[ -x /usr/local/bin/signage-kiosk-wait-for-display ]]; then
  SIGNAGE_EXEC_START_PRE="ExecStartPre=+/usr/local/bin/signage-kiosk-wait-for-display 90"
fi
if [[ "$CHROMIUM" == *"/snap/"* ]] || [[ -x /snap/bin/chromium ]]; then
  SIGNAGE_AFTER="$SIGNAGE_AFTER snapd.service snapd.seeded.service"
  SIGNAGE_WANTS="$SIGNAGE_WANTS snapd.seeded.service"
fi

if command -v loginctl >/dev/null 2>&1; then
  mkdir -p /var/lib/systemd/linger
  touch "/var/lib/systemd/linger/$KIOSK_USER"
  loginctl enable-linger "$KIOSK_USER" || true
  systemctl start "user@${KIOSK_UID}.service" 2>/dev/null || true
fi

cat > /etc/systemd/system/signage.service <<EOF
[Unit]
Description=Signage kiosk (cage + Chromium)
After=$SIGNAGE_AFTER
Wants=$SIGNAGE_WANTS
Requires=$SIGNAGE_REQUIRES
Conflicts=getty@tty1.service

[Service]
User=$KIOSK_USER
PAMName=login
TTYPath=/dev/tty1
TTYReset=yes
TTYVTDisallocate=yes
StandardInput=tty
StandardOutput=journal
Environment=XCURSOR_THEME=signage-blank
Environment=XCURSOR_SIZE=24
${SIGNAGE_EXEC_START_PRE}
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
