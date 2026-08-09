#!/usr/bin/env bash
# Install a transparent Xcursor theme for signage kiosks (cage / Wayland).
# Uses a prebuilt cursor shipped in the repo — no xcursorgen / X11 packages needed.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
THEME_DIR="${1:-/usr/share/icons/signage-blank}"
SRC_CURSOR="$SCRIPT_DIR/signage-blank-cursor/cursors/left_ptr"
PATCH_SYSTEM="${SIGNAGE_PATCH_SYSTEM_CURSORS:-1}"

if [[ ! -f "$SRC_CURSOR" ]]; then
  echo "Missing bundled cursor: $SRC_CURSOR" >&2
  exit 1
fi

mkdir -p "$THEME_DIR/cursors"

CURSOR_NAMES=(
  left_ptr default arrow right_ptr hand hand1 hand2 handgrabbing
  grab grabbing move crosshair text ibeam vertical-text
  zoom-in zoom-out col-resize row-resize nw-resize ne-resize sw-resize se-resize
  n-resize s-resize e-resize w-resize pointer wait progress not-allowed
  help copy alias cell context-menu cross no-drop all-scroll
)

install_cursor_set() {
  local dir="$1"
  mkdir -p "$dir"
  local name
  for name in "${CURSOR_NAMES[@]}"; do
    install -m 644 "$SRC_CURSOR" "$dir/$name"
  done
}

install_cursor_set "$THEME_DIR/cursors"

if [[ -f "$SCRIPT_DIR/signage-blank-cursor/index.theme" ]]; then
  install -m 644 "$SCRIPT_DIR/signage-blank-cursor/index.theme" "$THEME_DIR/index.theme"
else
  cat > "$THEME_DIR/index.theme" <<EOF
[Icon Theme]
Name=Signage Blank
Comment=Transparent cursor for signage kiosks
EOF
fi

if [[ "$PATCH_SYSTEM" == "1" ]]; then
  # Cage/wlroots may ignore XCURSOR_THEME and load distro defaults — patch common themes.
  for theme_dir in /usr/share/icons/*/cursors; do
    [[ -d "$theme_dir" ]] || continue
    install_cursor_set "$theme_dir"
  done
  mkdir -p /usr/share/icons/default
  cat > /usr/share/icons/default/index.theme <<EOF
[Icon Theme]
Inherits=signage-blank
EOF
  if command -v update-alternatives >/dev/null 2>&1; then
    update-alternatives --install /usr/share/icons/default/index.theme x-cursor-theme "$THEME_DIR/index.theme" 100 2>/dev/null \
      || true
    update-alternatives --set x-cursor-theme "$THEME_DIR/index.theme" 2>/dev/null || true
  fi
fi

echo "Installed blank cursor theme at $THEME_DIR"
