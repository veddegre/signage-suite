#!/usr/bin/env bash
# @deprecated Use signage-fix-cursor-pi.sh — safe Pi-only cursor fix (no ydotool).
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/signage-fix-cursor-pi.sh" "$@"
