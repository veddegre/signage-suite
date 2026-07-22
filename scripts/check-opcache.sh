#!/usr/bin/env bash
#
# Verify PHP OPcache is active for web requests (not CLI).
# CLI returns false when opcache.enable_cli=0 — that is normal for this suite.
#
# Usage:
#   sudo bash scripts/check-opcache.sh
#   sudo bash scripts/check-opcache.sh --webroot /var/www/html/boards

set -euo pipefail

WEBROOT="/var/www/html/boards"

while [[ $# -gt 0 ]]; do
  case "$1" in
    -w|--webroot) WEBROOT="${2:?}"; shift 2 ;;
    -h|--help)
      echo "Usage: sudo bash scripts/check-opcache.sh [--webroot PATH]"
      exit 0
      ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

WEBROOT="$(realpath -m "$WEBROOT")"
[[ -f "$WEBROOT/config.php" ]] || { echo "Not a signage web root: $WEBROOT" >&2; exit 1; }

phpver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
echo "== PHP $phpver"
for sapi in apache2 fpm; do
  ini="/etc/php/${phpver}/${sapi}/conf.d/98-signage-opcache.ini"
  if [[ -f "$ini" ]]; then
    echo "== Found $ini"
    grep -E '^opcache\.' "$ini" | sed 's/^/   /'
  fi
done

probe="$WEBROOT/.opcache-probe-$$.php"
cleanup() { rm -f "$probe"; }
trap cleanup EXIT

cat > "$probe" <<'PHP'
<?php
header('Content-Type: text/plain; charset=utf-8');
if (!function_exists('opcache_get_status')) {
    echo "disabled\nno Zend OPcache extension in this SAPI\n";
    exit;
}
$s = opcache_get_status(false);
if ($s === false) {
    echo "disabled\nopcache_get_status() returned false (enable=0 for this SAPI?)\n";
    exit;
}
echo "enabled\n";
echo 'memory_mb: ' . (int)ini_get('opcache.memory_consumption') . "\n";
echo 'jit: ' . (ini_get('opcache.jit') ?: 'off') . "\n";
$st = $s['opcache_statistics'] ?? [];
echo 'cached_scripts: ' . (int)($st['num_cached_scripts'] ?? 0) . "\n";
echo 'hits: ' . (int)($st['hits'] ?? 0) . "\n";
echo 'misses: ' . (int)($st['misses'] ?? 0) . "\n";
PHP
chown www-data:www-data "$probe" 2>/dev/null || true
chmod 644 "$probe"

base="${URL_BASE:-}"
if [[ -z "$base" ]]; then
  host="$(hostname -f 2>/dev/null || hostname)"
  html="/var/www/html"
  html="$(realpath -m "$html")"
  path=""
  if [[ "$WEBROOT" != "$html" && "$WEBROOT" == "$html/"* ]]; then
    path="/${WEBROOT#"$html/"}"
  fi
  base="http://${host}${path}"
fi

url="${base%/}/$(basename "$probe")"
echo "== Probing web SAPI: $url"
body="$(curl -fsS "$url" 2>/dev/null || true)"
if [[ -z "$body" ]]; then
  echo "FAIL — could not curl probe URL (is Apache/nginx running? adjust URL_BASE=)"
  exit 1
fi
printf '%s\n' "$body"
if [[ "$body" == enabled* ]]; then
  echo "== OK — OPcache is active for web PHP"
  exit 0
fi
echo "== FAIL — OPcache not active for web requests"
echo "   Re-run setup-server.sh --skip-apt with your git checkout as --source and $WEBROOT as --webroot"
echo "   Example: sudo bash setup-server.sh --skip-apt --source ~/signage-suite --webroot $WEBROOT"
exit 1
