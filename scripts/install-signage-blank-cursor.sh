#!/usr/bin/env bash
# Install a transparent Xcursor theme for signage kiosks (cage / Wayland).
# The compositor draws its own pointer — CSS cursor:none is not enough.
set -euo pipefail

THEME_DIR="${1:-/usr/share/icons/signage-blank}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if ! command -v xcursorgen >/dev/null 2>&1; then
  echo "xcursorgen not found — install x11-utils or xcursorgen" >&2
  exit 1
fi

python3 - "$TMP/blank.png" <<'PY'
import struct, sys, zlib

path = sys.argv[1]
w, h = 1, 1
raw = b'\x00' + b'\x00\x00\x00\x00'

def chunk(tag, data):
    crc = zlib.crc32(tag + data) & 0xffffffff
    return struct.pack('>I', len(data)) + tag + data + struct.pack('>I', crc)

with open(path, 'wb') as f:
    f.write(b'\x89PNG\r\n\x1a\n')
    f.write(chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0)))
    f.write(chunk(b'IDAT', zlib.compress(raw)))
    f.write(chunk(b'IEND', b''))
PY

printf '1 0 0 blank.png\n' > "$TMP/blank.cfg"
xcursorgen -p "$TMP" "$TMP/blank.cfg" "$TMP/left_ptr"
mkdir -p "$THEME_DIR/cursors"

CURSOR_NAMES=(
  left_ptr default arrow right_ptr hand hand1 hand2 handgrabbing
  grab grabbing move crosshair text ibeam vertical-text
  zoom-in zoom-out col-resize row-resize nw-resize ne-resize sw-resize se-resize
  n-resize s-resize e-resize w-resize pointer wait progress not-allowed
  help copy alias cell context-menu cross no-drop all-scroll
)

for name in "${CURSOR_NAMES[@]}"; do
  install -m 644 "$TMP/left_ptr" "$THEME_DIR/cursors/$name"
done

cat > "$THEME_DIR/index.theme" <<EOF
[Icon Theme]
Name=Signage Blank
Comment=Transparent cursor for signage kiosks
EOF

echo "Installed blank cursor theme at $THEME_DIR"
