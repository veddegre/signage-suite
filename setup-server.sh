#!/usr/bin/env bash
#
# setup-server.sh — onboard a fresh Ubuntu / Debian / Raspberry Pi OS box as the
# Home Signage Boards web server.
#
# Run once as root from the repo (or pass --source / --clone):
#
#     sudo bash setup-server.sh
#     sudo bash setup-server.sh --webroot /var/www/html/boards --with-ytdlp --with-video-cron
#     sudo bash setup-server.sh --clone https://github.com/you/signage-suite.git
#
# What it does:
#   * Installs Apache + PHP 8.x (curl, xml, mbstring, gd, zip), ffmpeg, dnsutils (dig), and yt-dlp (pipx)
#   * Optionally skips yt-dlp (--no-ytdlp) or adds weekly video fetch cron (--with-video-cron)
#   * Deploys board files to the web root
#   * Creates config/, cache/, videos/, slides/, photos/ with correct ownership
#   * Blocks direct HTTP access to config/, cache/, slides/, and photos/
#   * Generates slide_backgrounds/ theme PNGs (requires php-gd)
#   * Fetches slide_backgrounds/photos/ from Unsplash/Pexels if missing (requires outbound HTTPS)
#   * Optionally adds a weekly cron job for `php video.php fetch`
#   * Raises PHP / web-server timeouts for admin YouTube downloads
#   * Enables PHP OPcache (php-opcache) for faster admin and board requests
#
# Pair with setup-kiosk.sh on display devices — point each Pi at board.php.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

WEBROOT="/var/www/html/boards"
SOURCE=""
CLONE_URL=""
DOMAIN=""
WEB_USER="www-data"
WEBSERVER="apache"
SKIP_APT=0
WITH_YTDLP=1
WITH_VIDEO_CRON=0
URL_BASE=""
# Admin yt-dlp downloads can run a long time — applied to PHP, Apache, and nginx.
PHP_VIDEO_TIMEOUT_SEC=3600

log()  { printf '==> %s\n' "$*"; }
warn() { printf '!!> %s\n' "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
  sed -n '3,22p' "$0" | sed 's/^# \{0,1\}//'
  cat <<'EOF'

Options:
  -w, --webroot PATH       Install directory (default: /var/www/html/boards)
  -s, --source PATH        Copy from this tree (default: script directory)
      --clone URL          git clone into webroot parent, then install
  -d, --domain NAME        Apache ServerName (optional, e.g. signage.lan)
      --nginx              Write nginx snippet instead of Apache config
      --skip-apt           Skip apt package installation (re-run / config only)
      --no-ytdlp           Skip yt-dlp install (video board YouTube fetch won't work)
      --with-ytdlp         Install yt-dlp via pipx (default; kept for compatibility)
      --with-video-cron    Weekly cron: php video.php fetch (needs yt-dlp)
      --url-base URL         Override printed base URL (default: auto-detect)
  -h, --help               Show this help

Examples:
  sudo bash setup-server.sh
  sudo bash setup-server.sh -w /var/www/signage --with-ytdlp --with-video-cron
  sudo bash setup-server.sh --nginx --webroot /var/www/html/boards
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -w|--webroot)       WEBROOT="${2:?}"; shift 2 ;;
    -s|--source)        SOURCE="${2:?}"; shift 2 ;;
    --clone)            CLONE_URL="${2:?}"; shift 2 ;;
    -d|--domain)        DOMAIN="${2:?}"; shift 2 ;;
    --nginx)            WEBSERVER="nginx"; shift ;;
    --skip-apt)         SKIP_APT=1; shift ;;
    --no-ytdlp)         WITH_YTDLP=0; shift ;;
    --with-ytdlp)       WITH_YTDLP=1; shift ;;
    --with-video-cron)  WITH_VIDEO_CRON=1; shift ;;
    --url-base)         URL_BASE="${2:?}"; shift 2 ;;
    -h|--help)          usage; exit 0 ;;
    *) die "Unknown option: $1 (try --help)" ;;
  esac
done

[[ $EUID -eq 0 ]] || die "Run with sudo: sudo bash setup-server.sh"

if [[ -n "$CLONE_URL" && -n "$SOURCE" ]]; then
  die "Use either --source or --clone, not both"
fi
if [[ $WITH_VIDEO_CRON -eq 1 && $WITH_YTDLP -eq 0 ]]; then
  warn "--with-video-cron requested without --with-ytdlp; cron will only work if yt-dlp is already installed"
fi

SOURCE="${SOURCE:-$SCRIPT_DIR}"
WEBROOT="$(realpath -m "$WEBROOT")"

detect_os() {
  if [[ -f /etc/os-release ]]; then
    # shellcheck source=/dev/null
    . /etc/os-release
    case "${ID:-}${ID_LIKE:-}" in
      *debian*|*ubuntu*|*raspbian*) return 0 ;;
      *) warn "Untested OS (${PRETTY_NAME:-unknown}) — continuing anyway" ;;
    esac
  fi
}

# Ubuntu 24.04+ lists php-opcache as virtual-only — install php8.x-opcache instead.
resolve_php_opcache_pkg() {
  local policy candidate ver
  policy="$(apt-cache policy php-opcache 2>/dev/null || true)"
  candidate="$(printf '%s\n' "$policy" | awk '/Candidate:/ {print $2; exit}')"
  if [[ -n "$candidate" && "$candidate" != "(none)" ]]; then
    echo php-opcache
    return
  fi
  for ver in 8.4 8.3 8.2 8.1 8.0; do
    policy="$(apt-cache policy "php${ver}-opcache" 2>/dev/null || true)"
    candidate="$(printf '%s\n' "$policy" | awk '/Candidate:/ {print $2; exit}')"
    if [[ -n "$candidate" && "$candidate" != "(none)" ]]; then
      echo "php${ver}-opcache"
      return
    fi
  done
  echo php-opcache
}

install_packages() {
  [[ $SKIP_APT -eq 1 ]] && { log "Skipping apt (--skip-apt)"; return; }

  log "Updating apt indexes"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -q

  log "Installing Apache, PHP, ffmpeg, and dnsutils (dig)"
  local php_opcache_pkg
  php_opcache_pkg="$(resolve_php_opcache_pkg)"
  if [[ "$php_opcache_pkg" != php-opcache ]]; then
    log "Using ${php_opcache_pkg} (php-opcache meta-package not installable on this release)"
  fi
  apt-get install -y -q \
    apache2 libapache2-mod-php \
    php-cli php-curl php-xml php-mbstring php-gd php-zip "$php_opcache_pkg" \
    ffmpeg \
    dnsutils \
    rsync git curl

  if [[ "$WEBSERVER" == "nginx" ]]; then
    apt-get install -y -q nginx php-fpm
  fi

  if [[ $WITH_YTDLP -eq 1 ]]; then
    log "Installing yt-dlp via pipx"
    apt-get install -y -q pipx
    pipx ensurepath || true
    if ! command -v yt-dlp >/dev/null 2>&1; then
      pipx install yt-dlp --force || pipx install yt-dlp
    fi
    # Make yt-dlp available to cron / www-data shells
    if [[ -x /root/.local/bin/yt-dlp && ! -e /usr/local/bin/yt-dlp ]]; then
      ln -sf /root/.local/bin/yt-dlp /usr/local/bin/yt-dlp
    fi
    if ! command -v deno >/dev/null 2>&1 || ! deno --version 2>/dev/null | awk -F. '$1<2 || ($1==2 && $2<3){exit 1}'; then
      log "Installing/upgrading deno (yt-dlp needs 2.3.0+ for YouTube EJS)"
      curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sh
    fi
    if [[ -x /usr/local/bin/deno && ! -e /usr/bin/deno ]]; then
      ln -sf /usr/local/bin/deno /usr/bin/deno
    fi
  fi
}

deploy_files() {
  if [[ -n "$CLONE_URL" ]]; then
    local parent dest
    parent="$(dirname "$WEBROOT")"
    dest="$(basename "$WEBROOT")"
    mkdir -p "$parent"
    if [[ -d "$WEBROOT/.git" ]]; then
      log "Updating existing git checkout in $WEBROOT"
      git -C "$WEBROOT" pull --ff-only
    elif [[ -d "$WEBROOT" && -n "$(ls -A "$WEBROOT" 2>/dev/null)" ]]; then
      die "$WEBROOT exists and is not empty — move it aside or pick another --webroot"
    else
      log "Cloning $CLONE_URL → $WEBROOT"
      git clone "$CLONE_URL" "$WEBROOT"
    fi
    return
  fi

  [[ -d "$SOURCE" ]] || die "Source directory not found: $SOURCE"
  if [[ ! -f "$SOURCE/config.php" || ! -f "$SOURCE/admin.php" ]]; then
    die "Source does not look like the signage suite ($SOURCE)"
  fi

  log "Deploying files from $SOURCE → $WEBROOT"
  mkdir -p "$WEBROOT"
  rsync -a \
    --exclude '.git/' \
    --exclude '.DS_Store' \
    --exclude 'config/' \
    --exclude 'cache/' \
    --exclude 'videos/' \
    --exclude 'slides/' \
    --exclude '/photos/' \
    "$SOURCE/" "$WEBROOT/"

  # Bundled slide JPGs live under slide_backgrounds/photos/ — must not be skipped
  # (a bare photos/ exclude would also block that path and leave stale hotlink junk).
  if [[ -d "$SOURCE/slide_backgrounds/photos" ]]; then
    log "Syncing bundled slide photo backgrounds"
    rsync -a "$SOURCE/slide_backgrounds/photos/" "$WEBROOT/slide_backgrounds/photos/"
  fi
}

write_deny_htaccess() {
  local dir="$1"
  mkdir -p "$dir"
  if [[ ! -f "$dir/.htaccess" ]]; then
    printf 'Require all denied\n' > "$dir/.htaccess"
  fi
}

setup_directories() {
  log "Creating runtime directories"
  for d in config cache videos slides photos bin; do
    write_deny_htaccess "$WEBROOT/$d"
  done
  mkdir -p "$WEBROOT/config/cookies" "$WEBROOT/cache/yt-dlp/deno"

  # slide_backgrounds/ ships in git; ensure it exists and is readable
  mkdir -p "$WEBROOT/slide_backgrounds" "$WEBROOT/slide_backgrounds/photos"

  log "Setting ownership ($WEB_USER) on writable paths"
  chown -R "$WEB_USER:$WEB_USER" \
    "$WEBROOT/config" \
    "$WEBROOT/cache" \
    "$WEBROOT/videos" \
    "$WEBROOT/slides" \
    "$WEBROOT/photos" \
    "$WEBROOT/bin"

  chmod 775 "$WEBROOT/config" "$WEBROOT/cache" "$WEBROOT/videos" "$WEBROOT/slides" "$WEBROOT/photos" "$WEBROOT/bin"

  ensure_setup_key
}

ensure_setup_key() {
  local keyfile="$WEBROOT/config/setup.key"
  local usersfile="$WEBROOT/config/users.json"

  if [[ -f "$WEBROOT/config/admin.json" ]]; then
    log "Legacy admin.json present — skipping setup key"
    return
  fi
  if [[ -f "$usersfile" ]]; then
    local has_users
    has_users="$(php -r '
      $d = json_decode((string)@file_get_contents($argv[1]), true);
      $u = is_array($d) ? ($d["users"] ?? []) : [];
      echo (is_array($u) && count($u) > 0) ? "yes" : "no";
    ' "$usersfile" 2>/dev/null || echo no)"
    if [[ "$has_users" == yes ]]; then
      log "Admin account(s) in users.json — skipping setup key"
      return
    fi
  fi
  if [[ -f "$keyfile" ]]; then
    log "Setup key already present ($keyfile)"
    return
  fi

  log "Creating one-time admin setup key → $keyfile"
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 16 > "$keyfile"
  else
    dd if=/dev/urandom bs=16 count=1 2>/dev/null | xxd -p -c 32 > "$keyfile"
  fi
  chmod 600 "$keyfile"
  chown "$WEB_USER:$WEB_USER" "$keyfile"
}

fix_ytdlp_bin_perms() {
  local target="$WEBROOT/bin/yt-dlp"
  [[ -f "$target" ]] || return
  chmod 755 "$target"
  chown "$WEB_USER:$WEB_USER" "$target"
}

seed_ytdlp_bin() {
  [[ $WITH_YTDLP -eq 1 ]] || return
  local target="$WEBROOT/bin/yt-dlp"
  log "Downloading standalone yt-dlp → $target (GitHub release, not pipx stub)"
  if ! curl -fsSL -o "$target" "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp"; then
    warn "Could not download yt-dlp — use Admin → Update yt-dlp after deploy"
    return
  fi
  fix_ytdlp_bin_perms
  local ver
  ver="$(sudo -u "$WEB_USER" python3 "$target" --version 2>/dev/null || true)"
  if [[ -z "$ver" ]]; then
    warn "bin/yt-dlp downloaded but --version failed as $WEB_USER"
  else
    log "bin/yt-dlp ready ($ver)"
  fi
}

setup_apache() {
  [[ "$WEBSERVER" == "apache" ]] || return

  local conf="/etc/apache2/conf-available/signage-boards.conf"
  local escaped
  escaped="$(printf '%s' "$WEBROOT" | sed 's/[\/&]/\\&/g')"

  log "Writing Apache hardening config → $conf"
  cat > "$conf" <<EOF
# Home Signage Boards — generated by setup-server.sh
# Blocks direct download of secrets, cache, uploaded slides, and rotator photos.
# Verify: curl -I http://HOST$(url_path)/config/settings.json  → 403

# Admin video downloads (yt-dlp) can exceed the default 300 s.
Timeout ${PHP_VIDEO_TIMEOUT_SEC}
# Slide uploads allow up to 15 MB in admin (PHP post_max_size is set separately).
LimitRequestBody 23068672

<Directory "$WEBROOT">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>

<DirectoryMatch "^${escaped}/(config|cache|slides|photos|bin)/">
    Require all denied
</DirectoryMatch>
EOF

  if [[ -n "$DOMAIN" ]]; then
    local vhost="/etc/apache2/sites-available/signage.conf"
    log "Writing Apache vhost → $vhost (ServerName $DOMAIN)"
    cat > "$vhost" <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $WEBROOT

    ErrorLog \${APACHE_LOG_DIR}/signage-error.log
    CustomLog \${APACHE_LOG_DIR}/signage-access.log combined
</VirtualHost>
EOF
    a2ensite signage.conf >/dev/null 2>&1 || true
    a2dissite 000-default.conf >/dev/null 2>&1 || true
  fi

  a2enconf signage-boards >/dev/null
  a2enmod rewrite >/dev/null 2>&1 || true

  log "Testing Apache configuration"
  apache2ctl configtest
  systemctl enable apache2 >/dev/null 2>&1 || true
  systemctl reload apache2
}

setup_nginx() {
  [[ "$WEBSERVER" == "nginx" ]] || return

  local snippet="/etc/nginx/snippets/signage-boards.conf"
  local loc_path
  loc_path="$(url_path)"

  log "Writing nginx snippet → $snippet"
  cat > "$snippet" <<EOF
# Home Signage Boards — generated by setup-server.sh
# Include inside your server { } block, then set root/index as needed.

location ^~ ${loc_path}/config/ { deny all; return 403; }
location ^~ ${loc_path}/cache/  { deny all; return 403; }
location ^~ ${loc_path}/slides/ { deny all; return 403; }
location ^~ ${loc_path}/photos/ { deny all; return 403; }
location ^~ ${loc_path}/bin/   { deny all; return 403; }

location ${loc_path}/ {
    alias $WEBROOT/;
    index index.php board.php;
    try_files \$uri \$uri/ =404;
}

location ~ ^${loc_path}/(.+\.php)$ {
    alias $WEBROOT/\$1;
    client_max_body_size 22m;
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_read_timeout ${PHP_VIDEO_TIMEOUT_SEC}s;
}
EOF

  warn "nginx: add 'include snippets/signage-boards.conf;' to your server block"
  warn "nginx: adjust fastcgi_pass if your PHP-FPM socket path differs"
  nginx -t
  systemctl enable nginx >/dev/null 2>&1 || true
  systemctl reload nginx
}

url_path() {
  # Web path when installed under Apache's default /var/www/html tree.
  local html="/var/www/html"
  html="$(realpath -m "$html")"
  if [[ "$WEBROOT" == "$html" ]]; then
    echo ""
    return
  fi
  if [[ "$WEBROOT" == "$html/"* ]]; then
    echo "/${WEBROOT#"$html/"}"
    return
  fi
  echo ""
}

guess_url_base() {
  if [[ -n "$URL_BASE" ]]; then
    echo "$URL_BASE"
    return
  fi
  local host path
  host="$(hostname -f 2>/dev/null || hostname)"
  path="$(url_path)"
  if [[ -n "$DOMAIN" ]]; then
    host="$DOMAIN"
  fi
  echo "http://${host}${path}"
}

post_install_php() {
  log "Checking PHP"
  php -v | head -1

  local phpver
  phpver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"

  # Ubuntu/Debian: php-gd may be installed while /etc/php/*/mods-available/gd.ini
  # is not enabled for this SAPI (common after --skip-apt or partial installs).
  enable_php_mod() {
    local mod="$1"
    if php -m 2>/dev/null | grep -qi "^${mod}$"; then
      return 0
    fi
    if command -v phpenmod >/dev/null 2>&1; then
      log "Enabling PHP module ${mod} (phpenmod)"
      phpenmod -v ALL -s ALL "$mod" 2>/dev/null || phpenmod "$mod" 2>/dev/null || true
    fi
    if [[ -n "$phpver" && -f "/etc/php/${phpver}/mods-available/${mod}.ini" ]]; then
      local sapi
      for sapi in cli apache2 fpm; do
        local confd="/etc/php/${phpver}/${sapi}/conf.d"
        [[ -d "$confd" ]] || continue
        if [[ ! -e "${confd}/20-${mod}.ini" && ! -e "${confd}/15-${mod}.ini" ]]; then
          ln -sf "../mods-available/${mod}.ini" "${confd}/20-${mod}.ini" 2>/dev/null || true
        fi
      done
    fi
  }

  local ext
  for ext in curl xml mbstring gd zip opcache; do
    enable_php_mod "$ext"
  done

  for ext in curl xml mbstring gd zip; do
    if php -m 2>/dev/null | grep -qi "^${ext}$"; then
      continue
    fi
    warn "PHP extension missing: $ext"
    if [[ "$ext" == "gd" ]]; then
      warn "  Fix: sudo apt install php${phpver}-gd && sudo phpenmod gd && sudo systemctl reload apache2"
      if [[ -n "$phpver" ]]; then
        if dpkg -l "php${phpver}-gd" 2>/dev/null | grep -q '^ii'; then
          warn "  php${phpver}-gd is installed, but not loaded by: $(command -v php)"
          warn "  Check: php -m | grep -i gd · ls /etc/php/${phpver}/cli/conf.d/*gd*"
        elif dpkg -l php-gd 2>/dev/null | grep -q '^ii'; then
          warn "  php-gd meta-package is installed; ensure php${phpver}-gd matches this PHP"
        else
          warn "  Package not installed — run without --skip-apt, or: sudo apt install php-gd"
        fi
      fi
    fi
  done

  local opcache_ini=0
  if [[ -n "$phpver" ]]; then
    for sapi in apache2 fpm; do
      [[ -f "/etc/php/${phpver}/${sapi}/conf.d/98-signage-opcache.ini" ]] && opcache_ini=1
    done
  fi
  if [[ $opcache_ini -eq 1 ]]; then
    log "OPcache configured for web PHP (98-signage-opcache.ini)"
  elif php -m | grep -qi '^Zend OPcache$'; then
    log "OPcache module present (distribution defaults — re-run setup to apply signage tuning)"
  else
    warn "OPcache not loaded — install $(resolve_php_opcache_pkg) and re-run setup-server.sh"
  fi

  if ! php -m | grep -qi '^zip$'; then
    warn "php-zip not loaded — admin deno updates (Video Board) will not work until php-zip is installed"
  fi

  if php -m | grep -qi '^gd$'; then
    log "Generating slide theme background PNGs (if missing)"
    php -r "require '$WEBROOT/lib/slides_lib.php'; slide_background_ensure_assets();"
  else
    warn "php-gd not loaded — slide creator theme PNGs will generate on first admin visit"
  fi

  log "Ensuring slide photo backgrounds (download if missing)"
  if fetched="$(php -r "require '$WEBROOT/lib/slides_lib.php'; echo slide_background_ensure_photos();")"; then
    if [[ -n "$fetched" && "$fetched" != "0" ]]; then
      log "Downloaded $fetched slide photo(s)"
    fi
  else
    warn "Could not fetch slide photo backgrounds — needs outbound HTTPS; re-run setup-server.sh or scripts/download-slide-photos.sh"
  fi

  if [[ -d "$WEBROOT/slide_backgrounds" ]]; then
    chown -R root:"$WEB_USER" "$WEBROOT/slide_backgrounds" \
      && chmod 755 "$WEBROOT/slide_backgrounds" \
      && find "$WEBROOT/slide_backgrounds" -type f -exec chmod 644 {} \;
  fi
}

setup_php_timeouts() {
  local phpver sapi dir ini
  phpver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  [[ -n "$phpver" ]] || { warn "Could not detect PHP version — skipping timeout tuning"; return; }

  ini="; Home Signage Boards — generated by setup-server.sh
; Admin YouTube downloads via yt-dlp can take several minutes.
max_execution_time = ${PHP_VIDEO_TIMEOUT_SEC}
max_input_time = ${PHP_VIDEO_TIMEOUT_SEC}
default_socket_timeout = 600
upload_max_filesize = 20M
post_max_size = 22M
"

  for sapi in apache2 fpm; do
    dir="/etc/php/${phpver}/${sapi}/conf.d"
    [[ -d "$dir" ]] || continue
    log "Writing PHP timeouts for ${sapi} → ${dir}/99-signage-timeouts.ini"
    printf '%s\n' "$ini" > "${dir}/99-signage-timeouts.ini"
  done

  dir="/etc/php/${phpver}/fpm/pool.d"
  if [[ -d "$dir" ]]; then
    log "Writing PHP-FPM pool timeout → ${dir}/signage-timeouts.conf"
    cat > "${dir}/signage-timeouts.conf" <<EOF
; Home Signage Boards — generated by setup-server.sh
[www]
request_terminate_timeout = ${PHP_VIDEO_TIMEOUT_SEC}
EOF
  fi

  reload_php_services
}

reload_php_services() {
  local phpver svc
  phpver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  [[ -n "$phpver" ]] || return

  svc="php${phpver}-fpm"
  if systemctl is-enabled "$svc" >/dev/null 2>&1; then
    log "Reloading $svc"
    systemctl reload "$svc" || warn "Could not reload $svc — restart it manually after setup"
  fi

  if [[ "$WEBSERVER" == "apache" ]] && systemctl is-enabled apache2 >/dev/null 2>&1; then
    log "Reloading apache2"
    systemctl reload apache2 || warn "Could not reload apache2 — restart it manually after setup"
  fi
}

setup_php_opcache() {
  local phpver sapi dir ini mem_kb jit_buf
  phpver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  [[ -n "$phpver" ]] || { warn "Could not detect PHP version — skipping OPcache tuning"; return; }

  if ! php -m | grep -qi '^Zend OPcache$'; then
    warn "OPcache not loaded — install $(resolve_php_opcache_pkg) and re-run setup-server.sh"
    return
  fi

  mem_kb=192
  jit_buf=64M
  if [[ -r /proc/meminfo ]]; then
    local total_kb
    total_kb="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)"
    if [[ -n "$total_kb" && "$total_kb" -lt 2000000 ]]; then
      mem_kb=96
      jit_buf=32M
      log "Low-memory host detected — using smaller OPcache (${mem_kb}M, JIT ${jit_buf})"
    fi
  fi

  ini="; Home Signage Boards — generated by setup-server.sh
; Caches compiled PHP bytecode — speeds up admin.php and board requests.
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = ${mem_kb}
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
opcache.save_comments = 1
"

  if php -r 'exit(PHP_VERSION_ID < 80000);'; then
    ini+="opcache.jit = tracing
opcache.jit_buffer_size = ${jit_buf}
"
  fi

  for sapi in apache2 fpm; do
    dir="/etc/php/${phpver}/${sapi}/conf.d"
    [[ -d "$dir" ]] || continue
    log "Writing PHP OPcache settings for ${sapi} → ${dir}/98-signage-opcache.ini"
    printf '%s\n' "$ini" > "${dir}/98-signage-opcache.ini"
  done

  reload_php_services
}

setup_video_cron() {
  [[ $WITH_VIDEO_CRON -eq 1 ]] || return

  local cron_file="/etc/cron.d/signage-video-fetch"
  local ytdlp
  ytdlp="$(command -v yt-dlp || true)"
  [[ -n "$ytdlp" ]] || warn "yt-dlp not found — skipping video fetch cron"

  log "Installing weekly video fetch cron → $cron_file"
  cat > "$cron_file" <<EOF
# Home Signage Boards — refresh YouTube downloads (Mondays 04:15)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
15 4 * * 1 root cd $WEBROOT && /usr/bin/php video.php fetch >> /var/log/signage-video-fetch.log 2>&1
EOF
  chmod 644 "$cron_file"
}

verify_protection() {
  [[ "$WEBSERVER" == "apache" ]] || return

  local base path
  base="$(guess_url_base)"
  path="${base%/}/config/settings.json"
  log "Verifying config/ is blocked (expect HTTP 403)"
  local code
  code="$(curl -s -o /dev/null -w '%{http_code}' "$path" 2>/dev/null || echo "000")"
  case "$code" in
    403) log "OK — config/settings.json returned 403" ;;
    404) log "OK — config/settings.json returned 404 (directory empty)" ;;
    000) warn "Could not curl $path — verify manually after DNS/firewall setup" ;;
    *)   warn "Expected 403 for config/settings.json, got HTTP $code — check Apache config" ;;
  esac
}

verify_opcache_web() {
  local script="$WEBROOT/scripts/check-opcache.sh"
  if [[ -x "$script" ]]; then
    log "Verifying OPcache via web request"
    if URL_BASE="$(guess_url_base)" bash "$script" --webroot "$WEBROOT"; then
      :
    else
      warn "OPcache web probe failed — see scripts/check-opcache.sh"
    fi
  fi
}

update_commands() {
  local src wr
  src="$(realpath -m "$SOURCE")"
  wr="$(realpath -m "$WEBROOT")"

  if [[ -n "$CLONE_URL" ]] || { [[ -d "$WEBROOT/.git" ]] && [[ "$src" == "$wr" ]]; }; then
    printf '  cd %s && git pull --ff-only\n  sudo bash setup-server.sh --skip-apt --source %s --webroot %s\n' \
      "$WEBROOT" "$WEBROOT" "$WEBROOT"
  elif [[ "$src" != "$wr" ]]; then
    printf '  cd %s && git pull\n  sudo bash setup-server.sh --skip-apt --source %s --webroot %s\n' \
      "$SOURCE" "$SOURCE" "$WEBROOT"
  else
    printf '  cd %s && git pull\n  sudo bash setup-server.sh --skip-apt --source %s\n' \
      "$WEBROOT" "$WEBROOT"
  fi
}

print_summary() {
  local base board admin player keyfile="$WEBROOT/config/setup.key"
  base="$(guess_url_base)"
  board="${base%/}/board.php"
  admin="${base%/}/admin.php"
  player="${base%/}/player.php"

  local setup_step
  if [[ -f "$keyfile" ]]; then
    setup_step="  1. Read the one-time setup key, then open admin:
       sudo cat $keyfile
       $admin
     Paste the setup key when creating your admin password (the file is deleted after setup)."
  else
    setup_step="  1. Open admin (account already configured, or visit once to create setup.key):
       $admin
     If first-time setup: sudo cat $keyfile"
  fi

  cat <<EOF

============================================================
Home Signage Boards — server setup complete
============================================================
Web root:     $WEBROOT
Web server:   $WEBSERVER
Base URL:     $base

Next steps:
${setup_step}
  2. Configure boards (API keys, rotation, slides, etc.) in admin.
  3. Preview the main rotation:
       $board
  4. Optional mobile / test player:
       $player

Kiosk displays (Pi / mini PC):
  sudo bash setup-kiosk.sh "$board"
  Full guide: docs/kiosk-setup.md (CEC, cursor, freezes, re-run after updates)

Security checklist:
  • config/settings.json holds API tokens — keep admin on HTTPS if exposed beyond LAN
  • First admin setup needs config/setup.key from the server (not web-accessible)
  • Confirm blocked paths return 403:
      curl -I ${base%/}/config/settings.json
  • For HTTPS, put Caddy/nginx/Certbot or Cloudflare Tunnel in front

Writable directories (owned by $WEB_USER):
  config/  cache/  videos/  slides/  photos/  bin/

Logs:
  Apache:  /var/log/apache2/signage-*.log (if --domain set)
  Video:   /var/log/signage-video-fetch.log (if --with-video-cron)

Re-run this script safely after pulling updates:
$(update_commands)
============================================================
EOF
}

main() {
  detect_os
  install_packages
  setup_php_timeouts
  setup_php_opcache
  deploy_files
  setup_directories
  seed_ytdlp_bin
  fix_ytdlp_bin_perms
  if [[ "$WEBSERVER" == "nginx" ]]; then
    setup_nginx
  else
    setup_apache
  fi
  post_install_php
  setup_video_cron
  verify_protection
  verify_opcache_web
  print_summary
}

main
