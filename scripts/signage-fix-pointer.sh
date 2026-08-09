#!/usr/bin/env bash
# @deprecated Do not use — delegates to cleanup-only helper.
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/signage-fix-cursor-pi.sh" --cleanup "$@"
