#!/usr/bin/env python3
"""One-shot layout migration: lib/, boards/<group>/, root stubs."""
from __future__ import annotations

import os
import re
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

LIBS = sorted(p.name for p in ROOT.glob('*_lib.php')) + ['json_store_lib.php']
LIBS = sorted(set(LIBS))

BOARD_GROUPS: dict[str, list[str]] = {
    'weather': [
        'index.php', 'lake.php', 'photo.php', 'air.php', 'uv.php',
        'webcam.php', 'bridgecam.php',
    ],
    'commute': ['traffic.php', 'traffic_tiles.php'],
    'security': [
        'cve.php', 'hibp.php', 'attacks.php', 'signaltrace.php',
        'outages.php', 'internet.php',
    ],
    'maps': [
        'attackmap.php', 'l3map.php', 'dshieldmap.php', 'dshieldsrc.php',
        'attackports.php', 'iodamap.php', 'radar.php',
    ],
    'monitoring': [
        'zabbix.php', 'splunk.php', 'splunkdash.php', 'grafana.php', 'homelab.php',
    ],
    'media': ['slides.php', 'rotator.php', 'video.php', 'rss.php', 'calendar.php'],
    'embed': ['web.php'],
    'fun': ['joke.php', 'xkcd.php', 'wotd.php', 'history.php', 'sports.php'],
}

ROOT_SHELL = {
    'board.php', 'admin.php', 'player.php', 'config.php', 'schema.php',
    'ticker.php', 'family.php',
}

ROOT_RESOURCE_PREFIXES = (
    '/cache', '/config', '/slides', '/photos', '/videos', '/bin', '/slide_backgrounds',
)


def read_text(path: Path) -> str:
    return path.read_text(encoding='utf-8')


def write_text(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding='utf-8')


def move_file(src: Path, dst: Path) -> None:
    dst.parent.mkdir(parents=True, exist_ok=True)
    if dst.exists():
        return
    shutil.move(str(src), str(dst))


def fix_lib_file(text: str) -> str:
    text = text.replace("require_once __DIR__ . '/config.php';", "require_once dirname(__DIR__) . '/config.php';")
    for prefix in ROOT_RESOURCE_PREFIXES:
        text = text.replace(f"__DIR__ . '{prefix}", f"SIGNAGE_ROOT . '{prefix}")
    # Relative paths from configured dirs (video/rotator/slides)
    text = re.sub(
        r"return __DIR__ \. '/' \. trim\(\$d, '/'\);",
        "return SIGNAGE_ROOT . '/' . trim($d, '/');",
        text,
    )
    text = re.sub(
        r"\$raw = __DIR__ \. '/' \. ltrim\(\$raw, '/'\);",
        "$raw = SIGNAGE_ROOT . '/' . ltrim($raw, '/');",
        text,
    )
    text = re.sub(
        r"cfg\('video\.VIDEO_DIR', __DIR__ \. '/videos'\)",
        "cfg('video.VIDEO_DIR', SIGNAGE_ROOT . '/videos')",
        text,
    )
    text = re.sub(
        r"defined\('SPORTS_CACHE_DIR'\) \? SPORTS_CACHE_DIR : \(__DIR__ \. '/cache'\)",
        "defined('SPORTS_CACHE_DIR') ? SPORTS_CACHE_DIR : (SIGNAGE_ROOT . '/cache')",
        text,
    )
    return text


def fix_board_file(text: str) -> str:
    root = "dirname(__DIR__, 2)"
    text = text.replace("require_once __DIR__ . '/config.php';", f"require_once {root} . '/config.php';")
    text = re.sub(
        r"require_once __DIR__ \. '/([a-z0-9_]+_lib\.php)';",
        rf"require_once {root} . '/lib/\1';",
        text,
    )
    text = text.replace(f"include __DIR__ . '/ticker.php';", f"include {root} . '/ticker.php';")
    for prefix in ROOT_RESOURCE_PREFIXES:
        text = text.replace(f"__DIR__ . '{prefix}", f"SIGNAGE_ROOT . '{prefix}")
    return text


def fix_root_shell(text: str, filename: str) -> str:
    text = re.sub(
        r"require_once __DIR__ \. '/([a-z0-9_]+_lib\.php)';",
        r"require_once __DIR__ . '/lib/\1';",
        text,
    )
    if filename == 'ticker.php':
        text = text.replace("__DIR__ . '/cache'", "SIGNAGE_ROOT . '/cache'")
    if filename == 'config.php':
        if 'SIGNAGE_ROOT' not in text.split('json_store_lib', 1)[0]:
            text = text.replace(
                "<?php\n",
                "<?php\nif (!defined('SIGNAGE_ROOT')) {\n    define('SIGNAGE_ROOT', __DIR__);\n}\n",
                1,
            )
        text = text.replace("require_once __DIR__ . '/json_store_lib.php';", "require_once __DIR__ . '/lib/json_store_lib.php';")
        text = text.replace("require_once __DIR__ . '/calendar_lib.php';", "require_once __DIR__ . '/lib/calendar_lib.php';")
        text = text.replace("__DIR__ . '/config/settings.json'", "SIGNAGE_ROOT . '/config/settings.json'")
    return text


def stub_for(rel_board: str) -> str:
    return f"<?php\nrequire __DIR__ . '/{rel_board}';\n"


def main() -> None:
    lib_dir = ROOT / 'lib'
    lib_dir.mkdir(exist_ok=True)

    for name in LIBS:
        src = ROOT / name
        if src.is_file():
            move_file(src, lib_dir / name)

    for lib_path in sorted(lib_dir.glob('*.php')):
        write_text(lib_path, fix_lib_file(read_text(lib_path)))

    board_to_rel: dict[str, str] = {}
    for group, files in BOARD_GROUPS.items():
        group_dir = ROOT / 'boards' / group
        group_dir.mkdir(parents=True, exist_ok=True)
        for name in files:
            src = ROOT / name
            rel = f'boards/{group}/{name}'
            board_to_rel[name] = rel
            if src.is_file():
                move_file(src, ROOT / rel)
            board_path = ROOT / rel
            if board_path.is_file():
                write_text(board_path, fix_board_file(read_text(board_path)))

    for name, rel in sorted(board_to_rel.items()):
        write_text(ROOT / name, stub_for(rel))

    for shell in ROOT_SHELL:
        path = ROOT / shell
        if path.is_file():
            write_text(path, fix_root_shell(read_text(path), shell))

    schema = ROOT / 'schema.php'
    if schema.is_file():
        write_text(schema, fix_root_shell(read_text(schema), 'schema.php'))

    # Scripts that reference moved paths
    script_fixes = {
        ROOT / 'scripts' / 'test-calendar-rrule.php': [
            ("require_once __DIR__ . '/../calendar_lib.php';", "require_once __DIR__ . '/../lib/calendar_lib.php';"),
            ("require_once __DIR__ . '/../calendar.php';", "require_once __DIR__ . '/../boards/media/calendar.php';"),
        ],
        ROOT / 'scripts' / 'diagnose-rotation.php': [],
        ROOT / 'scripts' / 'diagnose-slides-deploy.php': [],
        ROOT / 'scripts' / 'test-weighted-rotation.php': [],
    }
    for path, replacements in script_fixes.items():
        if not path.is_file():
            continue
        text = read_text(path)
        for old, new in replacements:
            text = text.replace(old, new)
        text = re.sub(
            r"require_once __DIR__ \. '/\.\./([a-z0-9_]+_lib\.php)';",
            r"require_once __DIR__ . '/../lib/\1';",
            text,
        )
        write_text(path, text)

    setup = ROOT / 'setup-server.sh'
    if setup.is_file():
        text = read_text(setup)
        text = text.replace("require '$WEBROOT/slides_lib.php'", "require '$WEBROOT/lib/slides_lib.php'")
        write_text(setup, text)

    print('Reorganized:', len(LIBS), 'libs,', len(board_to_rel), 'boards with stubs')


if __name__ == '__main__':
    main()
