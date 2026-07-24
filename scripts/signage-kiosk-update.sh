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

repo="${SIGNAGE_REPO:-}"
if [[ -n "$repo" && -d "$repo/.git" ]]; then
  old_head=""
  new_head=""
  old_head="$(git -C "$repo" rev-parse HEAD 2>/dev/null || true)"
  if git -C "$repo" pull --ff-only 2>&1 | logger -t signage-update; then
    new_head="$(git -C "$repo" rev-parse HEAD 2>/dev/null || true)"
    if [[ -n "$old_head" && -n "$new_head" && "$old_head" != "$new_head" ]]; then
      log "git updated $old_head -> $new_head — re-applying setup-kiosk"
      scale="${KIOSK_SCALE:-1}"
      cec_args=()
      if [[ "${KIOSK_WITH_CEC:-1}" == "0" ]]; then
        cec_args=(--no-cec)
      fi
      if [[ -x "$repo/setup-kiosk.sh" ]]; then
        bash "$repo/setup-kiosk.sh" --skip-apt --from-update "${cec_args[@]}" \
          "${KIOSK_URL:-}" "$scale"
      else
        log "setup-kiosk.sh missing in $repo"
      fi
      systemctl restart signage.service 2>/dev/null || true
    fi
  else
    log "git pull failed in $repo"
  fi
elif [[ -n "$repo" ]]; then
  log "SIGNAGE_REPO set but not a git directory: $repo"
fi

log "update run complete"
