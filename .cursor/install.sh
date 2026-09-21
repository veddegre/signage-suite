#!/usr/bin/env bash
#
# Cloud Agent bootstrap for the Home Signage Boards PHP app.
# Idempotent: safe to run repeatedly. Installs PHP 8.3 (matching CI) with the
# extensions the boards need, then creates the writable runtime directories that
# admin.php and the boards expect (all git-ignored).
set -euo pipefail

if ! command -v php >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  sudo apt-get update -q
  # php-cli + extensions used across the boards (curl/xml/mbstring/gd/zip),
  # plus opcache; dnsutils (internet board) and ffmpeg (video board) are optional.
  sudo apt-get install -y -q \
    php-cli php-curl php-xml php-mbstring php-gd php-zip php-opcache \
    dnsutils ffmpeg
fi

php --version

# Writable runtime dirs (git-ignored). admin.php also creates config/ on first
# run, but pre-creating keeps the built-in server happy from the first request.
mkdir -p config config/rotation/pages cache videos slides photos

echo "Signage dev environment ready."
