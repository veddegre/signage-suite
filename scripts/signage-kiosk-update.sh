#!/usr/bin/env bash
# Nightly OS + optional signage-suite git refresh on kiosk displays.
# Installed by setup-kiosk.sh — run via signage-update.timer (default 03:30).
set -euo pipefail

CONF=/etc/signage/kiosk.conf
FLAG_DIR=/run/signage
PENDING="$FLAG_DIR/reboot-pending"

if [[ ! -f "$CONF" ]]; then
  exit 0
fi
# shellcheck disable=SC1090
source "$CONF"

if [[ "${SIGNAGE_AUTO_UPDATE:-1}" == "0" ]]; then
  exit 0
fi

mkdir -p "$FLAG_DIR"
log() { logger -t signage-update "$*"; echo "signage-update: $*"; }

log "starting update run"

export DEBIAN_FRONTEND=noninteractive
if apt-get update -qq; then
  :
else
  log "apt-get update failed"
  exit 1
fi

upgraded=0
if out="$(apt-get -s upgrade 2>/dev/null | grep -E '^Inst |^Conf ' || true)"; then
  if [[ -n "$out" ]]; then
    upgraded=1
  fi
fi

if apt-get upgrade -y -qq; then
  log "apt-get upgrade finished"
else
  log "apt-get upgrade failed"
  exit 1
fi

if [[ $upgraded -eq 1 ]] || [[ -f /var/run/reboot-required ]]; then
  touch "$PENDING"
  log "marked reboot pending (packages or kernel)"
fi

# Refresh kiosk scripts/systemd units; signage-maint runs this again before reboot/restart.
if [[ -x /usr/local/bin/signage-kiosk-sync-repo ]]; then
  if /usr/local/bin/signage-kiosk-sync-repo; then
    log "repo sync finished"
  else
    log "repo sync failed (continuing)"
  fi
fi

log "update run complete"
