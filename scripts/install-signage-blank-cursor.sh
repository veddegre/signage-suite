#!/usr/bin/env bash
# Install a transparent Xcursor theme for signage kiosks (cage / Wayland).
# Uses a prebuilt cursor shipped in the repo — no xcursorgen / X11 packages needed.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
THEME_DIR="${1:-/usr/share/icons/signage-blank}"
SRC_CURSOR="$SCRIPT_DIR/signage-blank-cursor/cursors/left_ptr"

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

for name in "${CURSOR_NAMES[@]}"; do
  install -m 644 "$SRC_CURSOR" "$THEME_DIR/cursors/$name"
done

if [[ -f "$SCRIPT_DIR/signage-blank-cursor/index.theme" ]]; then
  install -m 644 "$SCRIPT_DIR/signage-blank-cursor/index.theme" "$THEME_DIR/index.theme"
else
  cat > "$THEME_DIR/index.theme" <<EOF
[Icon Theme]
Name=Signage Blank
Comment=Transparent cursor for signage kiosks
EOF
fi

echo "Installed blank cursor theme at $THEME_DIR"
