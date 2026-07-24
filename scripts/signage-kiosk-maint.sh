#!/usr/bin/env bash
# Daily maintenance: reboot when updates require it, otherwise restart the browser.
# Installed by setup-kiosk.sh — signage-maint.timer (default 04:00).
set -euo pipefail

CONF=/etc/signage/kiosk.conf
FLAG_DIR=/run/signage
PENDING="$FLAG_DIR/reboot-pending"

log() { logger -t signage-maint "$*"; echo "signage-maint: $*"; }

reboot_needed=0
if [[ -f /var/run/reboot-required ]]; then
  reboot_needed=1
fi
if [[ -f "$PENDING" ]]; then
  reboot_needed=1
fi

if [[ $reboot_needed -eq 1 ]]; then
  log "rebooting (kernel/package updates pending)"
  rm -f "$PENDING"
  /sbin/reboot
  exit 0
fi

if systemctl is-active --quiet signage.service 2>/dev/null; then
  log "restarting signage.service (memory flush)"
  systemctl restart signage.service
else
  log "signage.service not active — starting"
  systemctl start signage.service || true
fi
