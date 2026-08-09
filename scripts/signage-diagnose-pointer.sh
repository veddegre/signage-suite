#!/usr/bin/env bash
# Print pointer / cursor diagnostics for signage kiosks (cage + Wayland).
set -euo pipefail

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
echo

echo "-- blank cursor theme --"
if [[ -d /usr/share/icons/signage-blank/cursors ]]; then
  echo "signage-blank theme: installed"
  ls -1 /usr/share/icons/signage-blank/cursors | head -5
  echo "..."
else
  echo "signage-blank theme: MISSING — run scripts/install-signage-blank-cursor.sh"
fi
echo "XCURSOR_THEME=${XCURSOR_THEME:-"(not set in this shell)"}"
echo

echo "-- libinput pointer devices --"
if command -v libinput >/dev/null 2>&1; then
  libinput list-devices 2>/dev/null | awk '
    /^Device:/ { dev=$0; show=0 }
    /Capabilities:/ && /pointer/ { show=1 }
    show && (/Device:/ || /Kernel:/ || /Capabilities:/ || /Size:/ || /Tags:/) { print }
    show && /^$/ { show=0; print "" }
  ' || libinput list-devices 2>/dev/null || true
else
  echo "libinput not installed — apt install libinput-tools"
fi
echo

echo "-- kiosk config --"
if [[ -f /etc/signage/kiosk.conf ]]; then
  grep -E '^(KIOSK_URL|SCREEN)=' /etc/signage/kiosk.conf || true
else
  echo "/etc/signage/kiosk.conf not found"
fi
echo

echo "Tips:"
echo "  • Cage draws a compositor cursor when any pointer-capable input exists."
echo "  • ydotool parks the pointer off-screen; blank Xcursor alone is often not enough."
echo "  • Phantom HDMI/CEC devices: ignore via udev — see docs/kiosk-setup.md"
echo "  • Unplug unused USB mice."
