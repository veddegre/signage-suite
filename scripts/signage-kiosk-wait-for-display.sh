#!/usr/bin/env bash
# Prepare the kernel VT + DRM before cage starts (x86 mini PCs / NUCs).
# Runs as root from signage.service ExecStartPre=+ — chvt needs CAP_SYS_TTY_CONFIG.
#
# First boot often exposes /dev/dri/card0 as simpledrm, then i915/xe/vc4 replaces
# it. If cage binds to simpledrm, HDMI stays black until a manual restart.
set -euo pipefail

shopt -s nullglob

MAX_WAIT="${1:-90}"

activate_vt() {
  if ! command -v chvt >/dev/null 2>&1; then
    return 0
  fi
  chvt 1 2>/dev/null || true
  sleep 1
  chvt 1 2>/dev/null || true
}

drm_driver_name() {
  local card="$1" link
  link="$card/device/driver"
  if [[ -L "$link" ]]; then
    basename "$(readlink -f "$link")"
    return 0
  fi
  echo ""
}

# True when a real KMS driver is bound (not the firmware simple-framebuffer).
drm_has_real_gpu() {
  local card driver
  for card in /sys/class/drm/card[0-9]*; do
    [[ -e "$card" ]] || continue
    driver="$(drm_driver_name "$card")"
    case "$driver" in
      simple-framebuffer|simpledrm|"") continue ;;
      *) return 0 ;;
    esac
  done
  if [[ -d /sys/module/i915 || -d /sys/module/xe || -d /sys/module/amdgpu \
     || -d /sys/module/vc4 || -d /sys/module/nvidia || -d /sys/module/virtio_gpu ]]; then
    if [[ -e /dev/dri/card0 || -e /dev/dri/card1 ]]; then
      return 0
    fi
  fi
  return 1
}

activate_vt

deadline=$(( $(date +%s) + MAX_WAIT ))
while [[ $(date +%s) -lt $deadline ]]; do
  if drm_has_real_gpu; then
    activate_vt
    logger -t signage-kiosk "display ready (KMS GPU, not simpledrm)"
    exit 0
  fi
  sleep 1
done

if [[ -e /dev/dri/card0 || -e /dev/dri/card1 ]]; then
  logger -t signage-kiosk "only firmware/simpledrm after ${MAX_WAIT}s — cage may need a later restart"
else
  logger -t signage-kiosk "no /dev/dri/card* after ${MAX_WAIT}s — continuing anyway"
fi
activate_vt
exit 0
