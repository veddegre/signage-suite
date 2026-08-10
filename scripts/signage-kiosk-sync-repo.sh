#!/usr/bin/env bash
# git pull SIGNAGE_REPO and re-apply setup-kiosk.sh --skip-apt (scripts + systemd units).
# Called nightly before reboot/browser restart (signage-maint) and after apt (signage-update).
set -euo pipefail

CONF=/etc/signage/kiosk.conf
TAG=signage-sync

log() { logger -t "$TAG" "$*"; echo "$TAG: $*"; }

if [[ ! -f "$CONF" ]]; then
  exit 0
fi
# shellcheck disable=SC1090
source "$CONF"

repo="${SIGNAGE_REPO:-}"
if [[ -z "$repo" ]]; then
  log "SIGNAGE_REPO not set in kiosk.conf — clone signage-suite and re-run setup-kiosk.sh once"
  exit 0
fi
if [[ ! -d "$repo/.git" ]]; then
  log "SIGNAGE_REPO is not a git directory: $repo"
  exit 0
fi

old_head="$(git -C "$repo" rev-parse HEAD 2>/dev/null || true)"
if ! git -C "$repo" pull --ff-only 2>&1 | logger -t "$TAG"; then
  log "git pull failed in $repo"
  exit 1
fi
new_head="$(git -C "$repo" rev-parse HEAD 2>/dev/null || true)"
if [[ -n "$old_head" && -n "$new_head" && "$old_head" != "$new_head" ]]; then
  log "git updated $old_head -> $new_head"
else
  log "git pull complete (already up to date)"
fi

if [[ ! -x "$repo/setup-kiosk.sh" ]]; then
  log "setup-kiosk.sh missing in $repo"
  exit 1
fi

scale="${KIOSK_SCALE:-1}"
cec_args=()
if [[ "${KIOSK_WITH_CEC:-1}" == "0" ]]; then
  cec_args=(--no-cec)
fi
tz_args=()
if [[ -n "${SIGNAGE_TIMEZONE:-}" ]]; then
  tz_args=(--timezone="$SIGNAGE_TIMEZONE")
fi
server="${SIGNAGE_SERVER:-${BOARDS_URL:-}}"
screen="${SCREEN:-main}"

if [[ -n "$server" ]]; then
  bash "$repo/setup-kiosk.sh" --skip-apt --from-update "${cec_args[@]}" "${tz_args[@]}" \
    --server="$server" --screen="$screen" --scale="$scale"
else
  bash "$repo/setup-kiosk.sh" --skip-apt --from-update "${cec_args[@]}" "${tz_args[@]}" \
    --scale="$scale" "${KIOSK_URL:-}"
fi

log "setup-kiosk --skip-apt complete"
exit 0
