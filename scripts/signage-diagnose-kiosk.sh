#!/usr/bin/env bash
# Diagnose black-screen / cage / Chromium issues on a signage kiosk.
set -euo pipefail

KIOSK_USER="${SUDO_USER:-${SIGNAGE_KIOSK_USER:-}}"

echo "=== Signage kiosk diagnostics ==="
echo

echo "-- service --"
systemctl is-active signage.service 2>/dev/null || echo "signage.service not active"
systemctl show signage.service -p ExecStart,MainPID,ActiveState,SubState --value 2>/dev/null | paste - - - - || true
if [[ -n "$KIOSK_USER" ]]; then
  uid="$(id -u "$KIOSK_USER" 2>/dev/null || true)"
  if [[ -n "$uid" ]]; then
    echo "user@${uid}.service: $(systemctl is-active "user@${uid}.service" 2>/dev/null || echo inactive)"
    echo "XDG runtime: $([[ -d /run/user/$uid ]] && echo present || echo MISSING)"
  fi
fi
echo "seatd.sock: $([[ -S /run/seatd.sock || -S /var/run/seatd.sock ]] && echo present || echo missing)"
echo "DRM: $(ls /dev/dri/card* 2>/dev/null | tr '\n' ' ' || echo none)"
echo

echo "-- processes --"
pgrep -a cage 2>/dev/null || echo "no cage process"
pgrep -a chromium 2>/dev/null || echo "no chromium process"
pgrep -a signage-hide-cursor 2>/dev/null || echo "no hide-cursor process"
pgrep -a ydotoold 2>/dev/null || echo "no ydotoold process"
echo

echo "-- config --"
grep -E '^(KIOSK_URL|SIGNAGE_SERVER|SCREEN|KIOSK_IGNORE_SSL)=' /etc/signage/kiosk.conf 2>/dev/null || echo "no kiosk.conf"
echo

echo "-- chromium --"
command -v chromium-browser 2>/dev/null || command -v chromium 2>/dev/null || echo "Chromium NOT FOUND"
echo

echo "-- launcher --"
if [[ -x /usr/local/bin/signage-kiosk ]]; then
  head -3 /usr/local/bin/signage-kiosk
  if grep -q 'signage-kiosk-launcher' /usr/local/bin/signage-kiosk 2>/dev/null; then
    echo "(bundled launcher script)"
  elif grep -q 'signage_kiosk_blackout_tty' /usr/local/bin/signage-kiosk 2>/dev/null; then
    echo "WARNING: old inline launcher — run setup-kiosk.sh --skip-apt to refresh"
  fi
else
  echo "/usr/local/bin/signage-kiosk missing"
fi
echo

echo "-- server reachability --"
if [[ -f /etc/signage/kiosk.conf ]]; then
  # shellcheck disable=SC1091
  source /etc/signage/kiosk.conf
  url="${KIOSK_URL:-}"
  if [[ -n "$url" ]]; then
    args=(-fsS --max-time 15)
    [[ "${KIOSK_IGNORE_SSL:-1}" == "1" ]] && args+=(-k)
    if curl "${args[@]}" "$url" | grep -q 'const PAGES'; then
      echo "OK: $url responds with rotation shell"
    else
      echo "FAIL: $url did not return board.php rotation shell"
    fi
  fi
fi
echo

echo "-- recent logs --"
journalctl -u signage -n 25 --no-pager 2>/dev/null || true
echo

echo "Recovery:"
echo "  cd ~/signage-suite && git pull"
echo "  sudo bash scripts/signage-stop-hide-cursor.sh"
echo "  sudo bash setup-kiosk.sh --skip-apt --server=https://YOUR-SERVER --screen=YOURSCREEN"
echo "  sudo systemctl restart signage"
