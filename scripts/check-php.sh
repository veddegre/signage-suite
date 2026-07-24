#!/usr/bin/env bash
#
# Run PHP syntax + load-time fatal checks (and offline test-*.php scripts).
#
# Usage:
#   bash scripts/check-php.sh [--root=PATH] [--lint-only] [--no-tests]
#
# Same flags as scripts/check-php.php. Use after git pull on server or in CI.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

exec php "$SCRIPT_DIR/check-php.php" --root="$ROOT" "$@"
