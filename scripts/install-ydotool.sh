#!/usr/bin/env bash
# Install ydotool (apt, Bookworm .deb fallback, or source build) for signage-hide-cursor.
set -euo pipefail

if command -v ydotool >/dev/null 2>&1; then
  echo "ydotool already installed: $(command -v ydotool)"
  install_uinput_udev
  exit 0
fi

install_uinput_udev() {
  local rules=/etc/udev/rules.d/99-signage-uinput.rules
  if [[ ! -f "$rules" ]]; then
    cat > "$rules" <<'EOF'
# Allow ydotoold to use /dev/uinput without root (logind uaccess).
KERNEL=="uinput", MODE="0660", GROUP="input", TAG+="uaccess"
EOF
    udevadm control --reload-rules 2>/dev/null || true
    udevadm trigger --subsystem-match=misc --action=add 2>/dev/null || true
  fi
}

try_apt() {
  apt-get install -y -q ydotool
}

try_bookworm_deb() {
  local arch
  arch="$(dpkg --print-architecture 2>/dev/null || true)"
  [[ -n "$arch" ]] || return 1

  local tmp
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN

  local base="https://deb.debian.org/debian/pool/main/y/ydotool"
  local ver="1.0.4-3"
  if ! curl -fsSL -o "$tmp/ydotool.deb" "$base/ydotool_${ver}_${arch}.deb"; then
    return 1
  fi
  if ! curl -fsSL -o "$tmp/ydotoold.deb" "$base/ydotoold_${ver}_${arch}.deb"; then
    return 1
  fi

  dpkg -i "$tmp/ydotoold.deb" "$tmp/ydotool.deb" 2>/dev/null \
    || apt-get install -y -f -q
}

try_source_build() {
  local tmp
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN

  local deps=(
    cmake pkg-config g++ git
    libevdevplus-dev libuinputplus-dev libboost-program-options-dev
  )
  apt-get install -y -q "${deps[@]}" 2>/dev/null \
    || apt-get install -y -q cmake pkg-config g++ git

  git clone --depth 1 --branch v1.0.4 --recursive \
    https://github.com/ReimuNotMoe/ydotool.git "$tmp/ydotool" 2>/dev/null \
    || git clone --depth 1 --recursive https://github.com/ReimuNotMoe/ydotool.git "$tmp/ydotool"

  cmake -S "$tmp/ydotool" -B "$tmp/build" \
    -DBUILD_DOCS=OFF \
    -DSYSTEMD_USER_SERVICE=OFF \
    -DSYSTEMD_SYSTEM_SERVICE=OFF
  cmake --build "$tmp/build" -j"$(nproc 2>/dev/null || echo 2)"

  install -m 755 "$tmp/build/ydotool" /usr/local/bin/ydotool
  if [[ -x "$tmp/build/ydotoold" ]]; then
    install -m 755 "$tmp/build/ydotoold" /usr/local/bin/ydotoold
  fi
}

echo "==> Installing ydotool (off-screen pointer helper for cage)"
install_uinput_udev

if try_apt 2>/dev/null; then
  echo "==> ydotool installed from apt"
  exit 0
fi

echo "==> ydotool not in apt — trying Bookworm package"
if try_bookworm_deb 2>/dev/null; then
  echo "==> ydotool installed from Debian Bookworm package"
  exit 0
fi

echo "==> Building ydotool from source (may take a few minutes on a Pi)"
if try_source_build; then
  echo "==> ydotool built and installed to /usr/local/bin"
  exit 0
fi

echo "Could not install ydotool — compositor cursor may remain visible." >&2
exit 1
