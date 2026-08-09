#!/usr/bin/env bash
# Print pointer / cursor diagnostics for signage kiosks (cage + Wayland).
set -euo pipefail

KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-}}"

echo "=== Signage pointer diagnostics ==="
echo

echo "-- ydotool --"
if command -v ydotool >/dev/null 2>&1; then
  echo "ydotool: $(command -v ydotool)"
  command -v ydotoold >/dev/null 2>&1 && echo "ydotoold: $(command -v ydotoold)" || echo "ydotoold: missing"
else
  echo "ydotool: not installed"
fi
echo "signage-hide-cursor: $(command -v signage-hide-cursor 2>/dev/null || echo missing)"
if [[ -n "$KIOSK_USER" ]]; then
  uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
  if [[ -n "$uid" ]]; then
    pgrep -u "$uid" -af 'signage-hide-cursor|ydotoold' 2>/dev/null || echo "no hide-cursor/ydotoold process for $KIOSK_USER"
  fi
fi
systemctl is-active signage-ydotoold.service 2>/dev/null && echo "signage-ydotoold.service: active" \
  || echo "signage-ydotoold.service: not active"
echo

echo "-- /dev/uinput --"
if [[ -e /dev/uinput ]]; then
  ls -l /dev/uinput
else
  echo "/dev/uinput missing"
fi
if [[ -n "$KIOSK_USER" ]]; then
  echo "groups($KIOSK_USER): $(groups "$KIOSK_USER" 2>/dev/null || echo unknown)"
fi
echo

echo "-- blank cursor theme --"
if [[ -d /usr/share/icons/signage-blank/cursors ]]; then
  echo "signage-blank theme: installed"
else
  echo "signage-blank theme: MISSING — run scripts/install-signage-blank-cursor.sh"
fi
if [[ -f /etc/systemd/system/signage.service ]]; then
  systemctl show signage.service -p Environment --value 2>/dev/null | tr ' ' '\n' | grep '^XCURSOR' || true
fi
echo

echo "-- phantom pointer udev --"
if [[ -f /etc/udev/rules.d/99-signage-phantom-pointer.rules ]]; then
  grep -v '^#' /etc/udev/rules.d/99-signage-phantom-pointer.rules | grep -v '^$' || true
else
  echo "no 99-signage-phantom-pointer.rules — run scripts/signage-fix-pointer.sh"
fi
echo

echo "-- libinput pointer devices --"
if ! command -v libinput >/dev/null 2>&1; then
  echo "libinput not installed — apt install libinput-tools"
else
  libinput list-devices 2>/dev/null | awk '
    /^Device:/ { dev=$0; sub(/^Device:[[:space:]]*/, "", dev); caps=""; show=0 }
    /^Capabilities:/ {
      caps=$0
      if (caps ~ /pointer/) show=1
    }
    show && (/^Device:/ || /^Kernel:/ || /^Capabilities:/ || /^Size:/ || /^Tags:/) { print }
    show && /^$/ { show=0; print "" }
  ' || libinput list-devices 2>/dev/null || true
fi
echo

echo "-- /proc input names (hdmi/cec) --"
awk '
  /^N: Name=/ {
    if ($0 ~ /vc4-hdmi|HDMI|CEC/i) print $0
  }
' /proc/bus/input/devices 2>/dev/null || true
echo

echo "-- kiosk config --"
if [[ -f /etc/signage/kiosk.conf ]]; then
  grep -E '^(KIOSK_URL|SCREEN)=' /etc/signage/kiosk.conf || true
else
  echo "/etc/signage/kiosk.conf not found"
fi
echo

echo "Fix:"
echo "  sudo bash scripts/signage-fix-cursor-pi.sh"
echo "  sudo bash scripts/signage-undo-cursor-pi.sh"
