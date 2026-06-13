#!/usr/bin/env bash
# Fetch missing slide creator photo backgrounds (same logic as setup-server.sh).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
n="$(php -r "require '$ROOT/slides_lib.php'; echo slide_background_ensure_photos();")"
if [[ "$n" == "0" ]]; then
  echo "All slide photos present in slide_backgrounds/photos/"
else
  echo "Downloaded $n slide photo(s) into slide_backgrounds/photos/"
fi
