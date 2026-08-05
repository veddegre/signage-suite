#!/usr/bin/env bash
#
# setup-kiosk.sh — turn a fresh Raspberry Pi OS Lite (Bookworm) or Ubuntu
# Server (24.04+) box into a signage kiosk pointed at the rotation shell.
# Run once as the default user:
#
#     sudo bash setup-kiosk.sh
#
# Setup prompts for signage server URL, screen name, timezone, and 4K scale,
# tests board.php, then installs. Flags and legacy positional args still work.
#
#     sudo bash setup-kiosk.sh --server=https://your-server --screen=garage
#     sudo bash setup-kiosk.sh https://your-server/board.php?screen=garage [scale] [--no-cec]
#
# Options:
#   --server=URL       Signage server base (https://host — no /boards path)
#   --screen=KEY       Rotation screen name (default main)
#   --no-cec           Skip HDMI-CEC power scheduling (TV on/off from admin)
#   --strict-ssl       Enforce certificate validation (default: accept self-signed LAN HTTPS)
#   --no-auto-update   Skip unattended-upgrades and nightly update/reboot timers
#   --repo-path=DIR    Git checkout to pull for kiosk script updates (default: this repo if .git)
#   --update-time=HH:MM  Daily apt + git pull (default 03:30)
#   --maint-time=HH:MM   Daily reboot-if-needed else browser restart (default 04:00)
#   --timezone=ZONE      IANA timezone (e.g. America/Detroit); prompts if omitted
#   --skip-apt         Internal: refresh systemd/scripts only (used by signage-kiosk-update.sh)
#
# Full guide: docs/kiosk-setup.md
#
# The optional [scale] argument handles displays that aren't 1080p: the boards
# are designed at 1920x1080, so on a 4K display pass 2 (everything renders
# pixel-doubled and fills the screen). Omit it for a 1080p display.
#
# What it sets up:
#   * cage (a minimal Wayland kiosk compositor) running Chromium fullscreen
#   * a systemd service that starts it at boot and restarts it if it crashes
#   * nightly OS updates + optional git pull (signage-update.timer)
#   * scheduled maintenance reboot or browser restart (signage-maint.timer)
#   * HDMI-CEC sync (polls admin schedule every minute via board.php?api=cec)
#
# Works on Pi 4/5 and on x86 mini PCs running Ubuntu Server — the script
# handles both distros' Chromium packaging (Ubuntu's is a snap).

set -euo pipefail

WITH_CEC=1
AUTO_UPDATE=1
SKIP_APT=0
FROM_UPDATE=0
IGNORE_SSL=1
REPO_PATH=""
UPDATE_TIME="03:30"
MAINT_TIME="04:00"
TIMEZONE=""
SIGNAGE_SERVER=""
SCREEN=""
KIOSK_URL=""
ARGS=()
for arg in "$@"; do
  case "$arg" in
    --no-cec) WITH_CEC=0 ;;
    --strict-ssl) IGNORE_SSL=0 ;;
    --no-auto-update) AUTO_UPDATE=0 ;;
    --skip-apt) SKIP_APT=1 ;;
    --from-update) FROM_UPDATE=1; SKIP_APT=1 ;;
    --repo-path=*) REPO_PATH="${arg#*=}" ;;
    --update-time=*) UPDATE_TIME="${arg#*=}" ;;
    --maint-time=*) MAINT_TIME="${arg#*=}" ;;
    --timezone=*) TIMEZONE="${arg#*=}" ;;
    --server=*) SIGNAGE_SERVER="${arg#*=}" ;;
    --screen=*) SCREEN="${arg#*=}" ;;
    --scale=*) SCALE="${arg#*=}" ;;
    *) ARGS+=("$arg") ;;
  esac
done

LEGACY_KIOSK_URL="${ARGS[0]:-}"
if [[ -z "$KIOSK_URL" && -n "$LEGACY_KIOSK_URL" && "$LEGACY_KIOSK_URL" != -* ]]; then
  KIOSK_URL="$LEGACY_KIOSK_URL"
fi
SCALE="${ARGS[1]:-1}"
if [[ "$SCALE" != "1" && "$SCALE" != "2" && -n "${ARGS[1]:-}" ]]; then
  if [[ "$SCALE" == -* ]]; then
    SCALE="1"
  fi
fi

if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "$REPO_PATH" && -d "$SCRIPT_DIR/.git" ]]; then
  REPO_PATH="$SCRIPT_DIR"
fi

load_kiosk_conf() {
  [[ -f /etc/signage/kiosk.conf ]] || return 0
  # shellcheck disable=SC1091
  source /etc/signage/kiosk.conf
  SIGNAGE_SERVER="${SIGNAGE_SERVER:-${BOARDS_URL:-}}"
  SCREEN="${SCREEN:-main}"
  KIOSK_URL="${KIOSK_URL:-}"
  SCALE="${KIOSK_SCALE:-$SCALE}"
  TIMEZONE="${TIMEZONE:-${SIGNAGE_TIMEZONE:-}}"
}

kiosk_conf_exists() {
  [[ -f /etc/signage/kiosk.conf ]]
}

sanitize_screen() {
  local s
  s="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
  s="$(printf '%s' "$s" | tr -cd 'a-z0-9_-')"
  [[ -n "$s" ]] && printf '%s' "$s" || printf '%s' "main"
}

normalize_server_url() {
  local u="$1"
  u="${u#"${u%%[![:space:]]*}"}"
  u="${u%"${u##*[![:space:]]}"}"
  u="${u%/}"
  u="${u%%\?*}"
  u="${u%/boards/board.php}"
  u="${u%/board.php}"
  u="${u%/boards}"
  printf '%s' "$u"
}

ensure_server_scheme() {
  local u="$1"
  if [[ "$u" != http://* && "$u" != https://* ]]; then
    u="https://$u"
  fi
  printf '%s' "$u"
}

parse_legacy_kiosk_url() {
  local url="$1"
  local path screen
  path="${url%%\?*}"
  SIGNAGE_SERVER="$(normalize_server_url "$(dirname "$path")")"
  SCREEN=main
  if [[ "$url" == *"screen="* ]]; then
    screen="$(printf '%s' "$url" | sed -n 's/.*[?&]screen=\([^&]*\).*/\1/p')"
    SCREEN="$(sanitize_screen "$screen")"
  fi
}

build_kiosk_url() {
  local server screen
  server="$(normalize_server_url "$(ensure_server_scheme "$SIGNAGE_SERVER")")"
  screen="$(sanitize_screen "$SCREEN")"
  SIGNAGE_SERVER="$server"
  SCREEN="$screen"
  BOARDS_URL="$server"
  KIOSK_URL="${server}/board.php?screen=${screen}"
}

curl_test_args() {
  local args=(-fsS --max-time 20)
  [[ $IGNORE_SSL -eq 1 ]] && args+=(-k)
  printf '%s\n' "${args[@]}"
}

test_kiosk_url() {
  local url="$1"
  local curl_args=()
  local tmp=""
  mapfile -t curl_args < <(curl_test_args)
  if ! command -v curl >/dev/null 2>&1; then
    echo "    curl not installed yet — skipping connectivity test" >&2
    return 0
  fi
  echo ""
  echo "==> Testing $url"
  tmp="$(mktemp)"
  # Write to a file — curl | grep -q triggers SIGPIPE (exit 23) under pipefail even
  # when grep finds a match and the page is valid.
  if ! curl "${curl_args[@]}" -o "$tmp" "$url"; then
    rm -f "$tmp"
    echo "    Could not verify board.php (server unreachable, wrong screen, or not signage yet)" >&2
    return 1
  fi
  if grep -q 'const PAGES' "$tmp"; then
    rm -f "$tmp"
    echo "    OK — rotation shell responded"
    return 0
  fi
  rm -f "$tmp"
  echo "    Could not verify board.php (server unreachable, wrong screen, or not signage yet)" >&2
  return 1
}

prompt_yes_no() {
  local prompt="$1" default="${2:-y}" input=""
  local hint="Y/n"
  [[ "$default" == "n" ]] && hint="y/N"
  read -r -p "$prompt [$hint]: " input || true
  input="${input:-$default}"
  [[ "$input" =~ ^[Yy] ]]
}

prompt_signage_server() {
  local guess="${1:-}"
  echo ""
  echo "==> Signage server"
  echo "    Base URL of your signage server (HTTPS recommended)."
  echo "    Example: https://192.168.1.50 or https://signage.lan"
  local input=""
  while [[ -z "$input" ]]; do
    read -r -p "    Server URL${guess:+ [$guess]}: " input || true
    input="${input:-$guess}"
    input="$(normalize_server_url "$(ensure_server_scheme "$input")")"
    if [[ -z "$input" || "$input" == "https://" || "$input" == "http://" ]]; then
      echo "    Enter the server hostname or URL." >&2
      input=""
    fi
  done
  SIGNAGE_SERVER="$input"
}

prompt_screen_name() {
  local guess
  guess="$(sanitize_screen "${1:-main}")"
  echo ""
  echo "==> Screen name"
  echo "    Same key as admin → Rotation → Displays (default: main)."
  local input=""
  read -r -p "    Screen [$guess]: " input || true
  SCREEN="$(sanitize_screen "${input:-$guess}")"
}

prompt_scale() {
  local guess="${1:-1}"
  echo ""
  echo "==> Display resolution"
  echo "    Boards are designed for 1080p. Choose 2 for a 4K panel (pixel-doubled)."
  local input=""
  read -r -p "    Scale (1=1080p, 2=4K) [$guess]: " input || true
  input="${input:-$guess}"
  case "$input" in
    1|2) SCALE="$input" ;;
    *) SCALE="$guess" ;;
  esac
}

prompt_kiosk_wizard() {
  local guess_server guess_screen
  guess_server="$(normalize_server_url "${SIGNAGE_SERVER:-}")"
  guess_screen="$(sanitize_screen "${SCREEN:-main}")"
  prompt_signage_server "$guess_server"
  prompt_screen_name "$guess_screen"
  build_kiosk_url
  prompt_timezone
  prompt_scale "${SCALE:-1}"
  while ! test_kiosk_url "$KIOSK_URL"; do
    if ! prompt_yes_no "    Continue anyway?"; then
      echo "Aborted." >&2
      exit 1
    fi
    break
  done
  echo ""
  echo "==> Ready to install"
  echo "    Server:  $SIGNAGE_SERVER"
  echo "    Screen:  $SCREEN"
  echo "    URL:     $KIOSK_URL"
  echo "    Scale:   $SCALE"
  echo "    TZ:      $TIMEZONE"
  if ! prompt_yes_no "    Proceed?"; then
    echo "Aborted." >&2
    exit 1
  fi
}

prompt_existing_kiosk_config() {
  echo ""
  echo "==> Existing kiosk configuration"
  echo "    Server:  ${SIGNAGE_SERVER:-?}"
  echo "    Screen:  ${SCREEN:-main}"
  echo "    URL:     ${KIOSK_URL:-?}"
  if [[ -n "${SCALE:-}" && "$SCALE" != "1" ]]; then
    echo "    Scale:   $SCALE (4K)"
  fi
  echo ""
  echo "    Re-run setup to point at a new server or display (e.g. after moving hardware)."
  if prompt_yes_no "    Keep this server and screen?" "y"; then
    return 0
  fi
  prompt_kiosk_wizard
  return 0
}

resolve_kiosk_target() {
  local cli_server="$SIGNAGE_SERVER"
  local cli_screen="$SCREEN"
  local cli_url="$KIOSK_URL"
  local cli_scale="$SCALE"
  local had_existing=0
  local cli_explicit=0

  kiosk_conf_exists && had_existing=1
  [[ -n "$cli_server" || -n "$cli_screen" || -n "$cli_url" ]] && cli_explicit=1

  load_kiosk_conf

  [[ -n "$cli_server" ]] && SIGNAGE_SERVER="$cli_server"
  [[ -n "$cli_screen" ]] && SCREEN="$cli_screen"
  [[ -n "$cli_url" ]] && KIOSK_URL="$cli_url"
  if [[ -n "${ARGS[1]:-}" && "${ARGS[1]}" =~ ^[12]$ ]]; then
    SCALE="${ARGS[1]}"
  elif [[ -n "$cli_scale" ]]; then
    SCALE="$cli_scale"
  fi

  if [[ -n "$cli_server" || -n "$cli_screen" ]]; then
    [[ -z "$SCREEN" ]] && SCREEN=main
    build_kiosk_url
  elif [[ -n "$cli_url" || -n "$KIOSK_URL" ]]; then
    [[ -n "$cli_url" ]] && KIOSK_URL="$cli_url"
    parse_legacy_kiosk_url "$KIOSK_URL"
    build_kiosk_url
  elif [[ -n "$SIGNAGE_SERVER" ]]; then
    [[ -z "$SCREEN" ]] && SCREEN=main
    build_kiosk_url
  fi

  if [[ -n "$KIOSK_URL" ]]; then
    if [[ $had_existing -eq 1 && $cli_explicit -eq 0 && $FROM_UPDATE -eq 0 && -t 0 ]]; then
      prompt_existing_kiosk_config
    fi
    return 0
  fi

  if [[ $FROM_UPDATE -eq 1 ]]; then
    echo "Missing kiosk URL in /etc/signage/kiosk.conf — re-run setup interactively." >&2
    exit 1
  fi

  if [[ -t 0 ]]; then
    prompt_kiosk_wizard
    return 0
  fi

  echo "Usage: sudo bash setup-kiosk.sh" >&2
  echo "       sudo bash setup-kiosk.sh --server=https://HOST --screen=KEY [options]" >&2
  echo "       sudo bash setup-kiosk.sh https://HOST/board.php?screen=KEY [scale] [options]" >&2
  exit 1
}

calendar_time() {
  local t="$1"
  if [[ ! "$t" =~ ^([0-9]{1,2}):([0-9]{2})$ ]]; then
    echo "Invalid time (use HH:MM): $t" >&2
    exit 1
  fi
  local h="${BASH_REMATCH[1]}"
  local m="${BASH_REMATCH[2]}"
  h=$((10#$h))
  m=$((10#$m))
  printf '*-*-* %02d:%02d:00' "$h" "$m"
}

current_timezone() {
  if command -v timedatectl >/dev/null 2>&1; then
    timedatectl show -p Timezone --value 2>/dev/null || true
  elif [[ -f /etc/timezone ]]; then
    tr -d '[:space:]' < /etc/timezone
  else
    echo "UTC"
  fi
}

valid_timezone() {
  local tz="$1"
  [[ -n "$tz" && -e "/usr/share/zoneinfo/$tz" ]]
}

prompt_timezone() {
  local guess
  guess="$(current_timezone)"
  [[ -z "$guess" ]] && guess="America/Detroit"
  echo ""
  echo "==> System timezone"
  echo "    Nightly update timers and the local clock use the kiosk OS timezone."
  echo "    Enter an IANA name (e.g. America/Detroit, America/Chicago, America/Los_Angeles)."
  echo "    Current: $guess"
  local input=""
  read -r -p "    Timezone [$guess]: " input || true
  input="${input:-$guess}"
  if ! valid_timezone "$input"; then
    echo "Unknown timezone: $input" >&2
    echo "List zones: timedatectl list-timezones | grep America" >&2
    exit 1
  fi
  TIMEZONE="$input"
}

apply_timezone() {
  local tz="$1"
  if ! valid_timezone "$tz"; then
    echo "Unknown timezone: $tz" >&2
    exit 1
  fi
  local cur
  cur="$(current_timezone)"
  if [[ "$cur" == "$tz" ]]; then
    echo "==> Timezone already $tz"
    return
  fi
  if command -v timedatectl >/dev/null 2>&1; then
    timedatectl set-timezone "$tz"
    echo "==> Timezone set to $tz"
  else
    echo "$tz" > /etc/timezone
    ln -sf "/usr/share/zoneinfo/$tz" /etc/localtime
    echo "==> Timezone set to $tz (via /etc/timezone)"
  fi
}

UPDATE_CAL="$(calendar_time "$UPDATE_TIME")"
MAINT_CAL="$(calendar_time "$MAINT_TIME")"

resolve_kiosk_target

if [[ -z "$TIMEZONE" && $FROM_UPDATE -eq 0 && -t 0 ]]; then
  prompt_timezone
fi
if [[ -z "$TIMEZONE" ]]; then
  TIMEZONE="$(current_timezone)"
fi

KIOSK_USER="${SUDO_USER:-pi}"
echo "==> Kiosk user: $KIOSK_USER"
echo "==> Server:     $SIGNAGE_SERVER"
echo "==> Kiosk URL:  $KIOSK_URL"
echo "==> Screen key: $SCREEN"
echo "==> Scale:      $SCALE (use 2 for a 4K display)"
echo "==> HDMI-CEC:   $([[ $WITH_CEC -eq 1 ]] && echo enabled || echo skipped)"
echo "==> TLS certs:  $([[ $IGNORE_SSL -eq 1 ]] && echo 'ignore self-signed (use --strict-ssl to enforce)' || echo strict)"
echo "==> Auto update: $([[ $AUTO_UPDATE -eq 1 ]] && echo "on ($UPDATE_TIME apt/git, $MAINT_TIME maint)" || echo disabled)"
echo "==> Timezone:   $TIMEZONE"
[[ -n "$REPO_PATH" ]] && echo "==> Git repo:   $REPO_PATH"

apply_timezone "$TIMEZONE"

CHROMIUM=""
if [[ $SKIP_APT -eq 0 ]]; then
echo "==> Installing packages"
apt-get update -q
apt-get install -y -q cage seatd curl python3 ydotool
# Chromium packaging differs by distro: Pi OS has a real deb named
# chromium-browser; Ubuntu's chromium-browser/chromium packages are snap
# shims. Try them in order, then fall back to installing the snap directly.
apt-get install -y -q chromium-browser 2>/dev/null \
  || apt-get install -y -q chromium 2>/dev/null \
  || snap install chromium
CHROMIUM=$(command -v chromium-browser || command -v chromium || echo /snap/bin/chromium)
if [[ ! -x "$CHROMIUM" ]]; then
  echo "Could not find a Chromium binary after install — aborting." >&2
  exit 1
fi
echo "==> Using browser: $CHROMIUM"
echo "==> Purging CUPS (no printing on a kiosk)"
apt-get purge -y -q cups cups-daemon cups-browsed cups-common 2>/dev/null || true
apt-get autoremove -y -q --purge 2>/dev/null || true
systemctl disable --now cups.service cups.socket cups-browsed.service 2>/dev/null || true
usermod -aG video,render,input "$KIOSK_USER"

if [[ $WITH_CEC -eq 1 ]]; then
  apt-get install -y -q cec-utils 2>/dev/null || echo "==> cec-utils not available — CEC scheduling disabled on this box."
fi

if [[ $AUTO_UPDATE -eq 1 ]]; then
  echo "==> Configuring unattended security upgrades"
  apt-get install -y -q unattended-upgrades apt-listchanges 2>/dev/null || true
  cat > /etc/apt/apt.conf.d/20signage-auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
EOF
  mkdir -p /etc/apt/apt.conf.d
  if [[ ! -f /etc/apt/apt.conf.d/50unattended-upgrades ]]; then
    dpkg-reconfigure -f noninteractive unattended-upgrades 2>/dev/null || true
  fi
fi
else
echo "==> Skipping package install (--skip-apt)"
CHROMIUM=$(command -v chromium-browser || command -v chromium || echo /snap/bin/chromium)
if [[ ! -x "$CHROMIUM" ]]; then
  CHROMIUM=/snap/bin/chromium
fi
if [[ ! -x "$CHROMIUM" ]]; then
  echo "Could not find Chromium — run setup without --skip-apt once." >&2
  exit 1
fi
echo "==> Using browser: $CHROMIUM"
fi

if [[ -f "$SCRIPT_DIR/scripts/install-signage-blank-cursor.sh" ]]; then
  echo "==> Installing transparent cursor theme (hide mouse on kiosk)"
  bash "$SCRIPT_DIR/scripts/install-signage-blank-cursor.sh"
else
  echo "==> Warning: scripts/install-signage-blank-cursor.sh not found — cursor may remain visible." >&2
fi

if [[ -f "$SCRIPT_DIR/scripts/signage-hide-cursor.sh" ]]; then
  echo "==> Installing pointer off-screen helper (cage compositor cursor)"
  install -m 755 "$SCRIPT_DIR/scripts/signage-hide-cursor.sh" /usr/local/bin/signage-hide-cursor
else
  echo "==> Warning: scripts/signage-hide-cursor.sh not found — compositor cursor may remain visible." >&2
fi

echo "==> Writing /etc/signage/kiosk.conf"
mkdir -p /etc/signage
cat > /etc/signage/kiosk.conf <<EOF
# Signage kiosk — sourced by CEC sync, watchdog, and update scripts
SIGNAGE_SERVER="$SIGNAGE_SERVER"
KIOSK_URL="$KIOSK_URL"
BOARDS_URL="$BOARDS_URL"
SCREEN="$SCREEN"
KIOSK_SCALE="$SCALE"
KIOSK_WITH_CEC="$WITH_CEC"
SIGNAGE_AUTO_UPDATE="$AUTO_UPDATE"
SIGNAGE_REPO="$REPO_PATH"
SIGNAGE_UPDATE_TIME="$UPDATE_TIME"
SIGNAGE_MAINT_TIME="$MAINT_TIME"
SIGNAGE_TIMEZONE="$TIMEZONE"
KIOSK_IGNORE_SSL="$IGNORE_SSL"
EOF
chmod 644 /etc/signage/kiosk.conf

echo "==> Writing /usr/local/bin/signage-kiosk"
if [[ $IGNORE_SSL -eq 1 ]]; then
  cat > /usr/local/bin/signage-kiosk <<EOF
#!/usr/bin/env bash
# Launched by signage.service — cage runs Chromium as the sole fullscreen app.
# If cage exits (crash/OOM), blackout tty1 and restart immediately so the Linux
# console does not flash through during systemd's restart window.
export XCURSOR_THEME=signage-blank
export XCURSOR_SIZE=24

signage_kiosk_blackout_tty() {
  if command -v setterm >/dev/null 2>&1; then
    setterm -background black -foreground black -clear all >/dev/tty1 2>/dev/null || true
  fi
  printf '\033[40m\033[2J\033[H\033[?25l' >/dev/tty1 2>/dev/null || true
}

signage_kiosk_blackout_tty

# Cage always draws a compositor cursor when a pointer device is present.
# Park it off-screen (ydotool) — CSS / blank Xcursor are not enough alone.
if command -v signage-hide-cursor >/dev/null; then
  pkill -u "\$(id -u)" -f '^/usr/local/bin/signage-hide-cursor' 2>/dev/null || true
  signage-hide-cursor &
fi

KIOSK_URL="\$1"
while true; do
  cage -- "$CHROMIUM" \\
    --kiosk "\$KIOSK_URL" \\
    --force-device-scale-factor=$SCALE \\
    --noerrdialogs \\
    --disable-infobars \\
    --disable-session-crashed-bubble \\
    --disable-features=TranslateUI \\
    --disable-dev-shm-usage \\
    --autoplay-policy=no-user-gesture-required \\
    --check-for-update-interval=31536000 \\
    --enable-features=VaapiVideoDecoder \\
    --ozone-platform=wayland \\
    --ignore-certificate-errors \\
    --allow-insecure-localhost \\
    --start-fullscreen || true
  signage_kiosk_blackout_tty
  sleep 1
done
EOF
else
  cat > /usr/local/bin/signage-kiosk <<EOF
#!/usr/bin/env bash
# Launched by signage.service — cage runs Chromium as the sole fullscreen app.
export XCURSOR_THEME=signage-blank
export XCURSOR_SIZE=24

signage_kiosk_blackout_tty() {
  if command -v setterm >/dev/null 2>&1; then
    setterm -background black -foreground black -clear all >/dev/tty1 2>/dev/null || true
  fi
  printf '\033[40m\033[2J\033[H\033[?25l' >/dev/tty1 2>/dev/null || true
}

signage_kiosk_blackout_tty

# Cage always draws a compositor cursor when a pointer device is present.
# Park it off-screen (ydotool) — CSS / blank Xcursor are not enough alone.
if command -v signage-hide-cursor >/dev/null; then
  pkill -u "\$(id -u)" -f '^/usr/local/bin/signage-hide-cursor' 2>/dev/null || true
  signage-hide-cursor &
fi

KIOSK_URL="\$1"
while true; do
  cage -- "$CHROMIUM" \\
    --kiosk "\$KIOSK_URL" \\
    --force-device-scale-factor=$SCALE \\
    --noerrdialogs \\
    --disable-infobars \\
    --disable-session-crashed-bubble \\
    --disable-features=TranslateUI \\
    --disable-dev-shm-usage \\
    --autoplay-policy=no-user-gesture-required \\
    --check-for-update-interval=31536000 \\
    --enable-features=VaapiVideoDecoder \\
    --ozone-platform=wayland \\
    --start-fullscreen || true
  signage_kiosk_blackout_tty
  sleep 1
done
EOF
fi
chmod +x /usr/local/bin/signage-kiosk

if [[ $WITH_CEC -eq 1 ]]; then
  echo "==> Installing signage-cec-sync"
  if [[ -f "$SCRIPT_DIR/scripts/signage-cec-sync.sh" ]]; then
    install -m 755 "$SCRIPT_DIR/scripts/signage-cec-sync.sh" /usr/local/bin/signage-cec-sync
  else
    echo "==> Warning: scripts/signage-cec-sync.sh not found — run setup from the signage-suite repo." >&2
  fi

  if [[ -x /usr/local/bin/signage-cec-sync ]]; then
  cat > /etc/systemd/system/signage-cec.service <<'EOF'
[Unit]
Description=Signage HDMI-CEC power sync

[Service]
Type=oneshot
ExecStart=/usr/local/bin/signage-cec-sync
EOF
  cat > /etc/systemd/system/signage-cec.timer <<'EOF'
[Unit]
Description=Poll signage CEC schedule every minute

[Timer]
OnBootSec=2min
OnUnitActiveSec=1min
Persistent=true

[Install]
WantedBy=timers.target
EOF
  fi
fi

echo "==> Writing systemd service"
cat > /etc/systemd/system/signage.service <<EOF
[Unit]
Description=Signage kiosk (cage + Chromium)
After=network-online.target systemd-user-sessions.service
Wants=network-online.target

[Service]
User=$KIOSK_USER
PAMName=login
TTYPath=/dev/tty1
StandardInput=tty
StandardOutput=journal
Environment=XDG_RUNTIME_DIR=/run/user/%U
Environment=XCURSOR_THEME=signage-blank
Environment=XCURSOR_SIZE=24
ExecStart=/usr/local/bin/signage-kiosk "$KIOSK_URL"
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

echo "==> Nightly maintenance timers"
if [[ -f "$SCRIPT_DIR/scripts/signage-kiosk-update.sh" ]]; then
  install -m 755 "$SCRIPT_DIR/scripts/signage-kiosk-update.sh" /usr/local/bin/signage-kiosk-update
fi
if [[ -f "$SCRIPT_DIR/scripts/signage-kiosk-maint.sh" ]]; then
  install -m 755 "$SCRIPT_DIR/scripts/signage-kiosk-maint.sh" /usr/local/bin/signage-kiosk-maint
fi

systemctl disable --now signage-restart.timer 2>/dev/null || true

if [[ $AUTO_UPDATE -eq 1 ]] && [[ -x /usr/local/bin/signage-kiosk-update ]] && [[ -x /usr/local/bin/signage-kiosk-maint ]]; then
  cat > /etc/systemd/system/signage-update.service <<'EOF'
[Unit]
Description=Signage kiosk OS and git update

[Service]
Type=oneshot
ExecStart=/usr/local/bin/signage-kiosk-update
EOF
  cat > /etc/systemd/system/signage-update.timer <<EOF
[Unit]
Description=Daily signage OS/git update

[Timer]
OnCalendar=$UPDATE_CAL
Persistent=true

[Install]
WantedBy=timers.target
EOF
  cat > /etc/systemd/system/signage-maint.service <<'EOF'
[Unit]
Description=Signage kiosk maintenance (reboot or browser restart)

[Service]
Type=oneshot
ExecStart=/usr/local/bin/signage-kiosk-maint
EOF
  cat > /etc/systemd/system/signage-maint.timer <<EOF
[Unit]
Description=Daily signage maintenance window

[Timer]
OnCalendar=$MAINT_CAL
Persistent=true

[Install]
WantedBy=timers.target
EOF
else
  echo "==> Legacy nightly browser restart only (04:00, no auto apt/git)"
  cat > /etc/systemd/system/signage-restart.service <<'EOF'
[Unit]
Description=Restart signage kiosk

[Service]
Type=oneshot
ExecStart=/usr/bin/systemctl restart signage.service
EOF
  cat > /etc/systemd/system/signage-restart.timer <<'EOF'
[Unit]
Description=Nightly signage restart

[Timer]
OnCalendar=*-*-* 04:00:00
Persistent=true

[Install]
WantedBy=timers.target
EOF
fi

if [[ -f "$SCRIPT_DIR/scripts/signage-kiosk-watchdog.sh" ]]; then
  echo "==> Installing kiosk health watchdog (every 5 min)"
  install -m 755 "$SCRIPT_DIR/scripts/signage-kiosk-watchdog.sh" /usr/local/bin/signage-kiosk-watchdog
  cat > /etc/systemd/system/signage-watchdog.service <<'EOF'
[Unit]
Description=Signage kiosk health check

[Service]
Type=oneshot
ExecStart=/usr/local/bin/signage-kiosk-watchdog
EOF
  cat > /etc/systemd/system/signage-watchdog.timer <<'EOF'
[Unit]
Description=Poll signage kiosk health every 5 minutes

[Timer]
OnBootSec=5min
OnUnitActiveSec=5min
Persistent=true

[Install]
WantedBy=timers.target
EOF
fi

echo "==> Disabling console getty on tty1 (kiosk owns the display)"
systemctl disable --now getty@tty1.service || true

systemctl daemon-reload
systemctl enable signage.service
if [[ $AUTO_UPDATE -eq 1 ]] && [[ -x /usr/local/bin/signage-kiosk-update ]] && [[ -x /usr/local/bin/signage-kiosk-maint ]]; then
  systemctl enable signage-update.timer signage-maint.timer
  systemctl start signage-update.timer signage-maint.timer
else
  systemctl enable signage-restart.timer
  systemctl start signage-restart.timer
fi
if [[ -x /usr/local/bin/signage-kiosk-watchdog ]]; then
  systemctl enable signage-watchdog.timer
  systemctl start signage-watchdog.timer
fi
if [[ $WITH_CEC -eq 1 ]] && [[ -x /usr/local/bin/signage-cec-sync ]]; then
  systemctl enable signage-cec.timer
  systemctl start signage-cec.timer
fi

if [[ $FROM_UPDATE -eq 0 ]]; then
cat <<NOTES

============================================================
Done. Reboot to start the kiosk:  sudo reboot

Useful afterwards:
  systemctl status signage          # is it running
  journalctl -u signage -f          # watch the browser logs
  sudo systemctl restart signage    # manual restart

AUTO UPDATES (default on)
  $UPDATE_TIME daily — apt upgrade + git pull in SIGNAGE_REPO (if set)
  $MAINT_TIME daily — reboot when kernel/packages need it, else restart browser
  unattended-upgrades — security patches between nightly runs
  Timers:  systemctl list-timers 'signage-*'
  Logs:    journalctl -u signage-update -u signage-maint
  Disable: re-run setup with --no-auto-update

HDMI-CEC (TV power from admin → Rotation → Displays):
  Schedules are set per screen in admin.php (CEC / Off hr / On hr).
  This box polls every minute as screen "$SCREEN".
  Test:  sudo /usr/local/bin/signage-cec-sync
  Logs:  journalctl -u signage-cec -f
  Disable: sudo systemctl disable --now signage-cec.timer

  TV must have CEC enabled (Anynet+, Simplink, Bravia Sync, etc.).
  Re-run setup with --no-cec to skip CEC entirely.

GIT / SCRIPT UPDATES
  Clone signage-suite on the Pi and re-run setup once — SIGNAGE_REPO is saved in
  /etc/signage/kiosk.conf. Nightly git pull re-runs setup-kiosk.sh (--skip-apt).
  Content on the wall still comes from the server (admin.php).

HTTPS / SELF-SIGNED CERTS
  Kiosks accept self-signed and LAN HTTPS by default (--ignore-certificate-errors).
  Use https:// in the kiosk URL when the server or reverse proxy serves TLS.
  Re-run setup after changing URL or SSL behavior. Use --strict-ssl only with
  a publicly trusted certificate (e.g. Let's Encrypt on your proxy).

CURSOR (if the mouse pointer is still visible after a server update):
  sudo apt install -y ydotool
  sudo bash $SCRIPT_DIR/scripts/install-signage-blank-cursor.sh
  sudo install -m 755 $SCRIPT_DIR/scripts/signage-hide-cursor.sh /usr/local/bin/signage-hide-cursor
  sudo systemctl restart signage

WATCHDOG (auto-restart if the browser stops serving board.php):
  systemctl status signage-watchdog.timer
  journalctl -u signage-watchdog -f

Docs: docs/kiosk-setup.md
============================================================
NOTES
fi
