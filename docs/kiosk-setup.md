# Kiosk machine setup

Turn a dedicated Linux box into a fullscreen Chromium display pointed at your signage server. Content stays on the **server** (`admin.php`); the kiosk is just a browser that boots into rotation.

| | |
|---|---|
| **Script** | [`setup-kiosk.sh`](../setup-kiosk.sh) at the repo root |
| **OS** | Raspberry Pi OS Lite (Bookworm+) or Ubuntu Server 24.04+ |
| **Hardware** | Pi 4/5, or an x86 mini PC / NUC |
| **Display** | Boards are designed at **1920×1080** |

---

## Prerequisites

1. **Signage server** already running (`setup-server.sh`) and reachable on the LAN.
2. A **display** defined in **admin.php → Rotation** (e.g. `main`, `garage`) so you know the URL:
   ```
   http://your-server/boards/board.php?screen=garage
   ```
   Omit `?screen=` for the default **main** screen.
3. On the kiosk machine: a fresh OS install, network, and a user you can `sudo` with (script uses `$SUDO_USER`, often `pi` or your login).

---

## Quick start

From a clone of this repo on the kiosk (or copy `setup-kiosk.sh` + `scripts/` onto the box):

```bash
# 1080p display
sudo bash setup-kiosk.sh "http://your-server/boards/board.php?screen=garage"

# 4K display — pixel-double to fill the panel
sudo bash setup-kiosk.sh "http://your-server/boards/board.php?screen=garage" 2

# Skip HDMI-CEC TV power control
sudo bash setup-kiosk.sh "http://your-server/boards/board.php?screen=garage" --no-cec
```

**Quote URLs** that contain `?screen=` so the shell does not eat the query string.

Then:

```bash
sudo reboot
```

After reboot, Chromium should fill the TV via **cage** (minimal Wayland compositor). Manage playlists and boards only on the **server** — you do not edit PHP on the Pi.

---

## What the script installs

| Piece | Role |
|-------|------|
| **cage** + **Chromium** | Fullscreen kiosk compositor + browser |
| **signage.service** | Starts at boot; restarts Chromium if it crashes |
| **signage-update.timer** | Daily **03:30** (default) — `apt upgrade` + optional `git pull` in `SIGNAGE_REPO` |
| **signage-maint.timer** | Daily **04:00** (default) — **reboot** if updates need it, else restart browser (memory flush) |
| **unattended-upgrades** | Security patches between nightly runs |
| **signage-watchdog.timer** | Every **5 min** — restarts `signage` if `board.php` stops responding |
| **signage-cec.timer** | Every **1 min** — polls server CEC schedule (unless `--no-cec`) |
| **Blank cursor** | Transparent theme + off-screen pointer helper (cage still draws a cursor if a USB mouse / CEC “pointer” is present) |

Config written to **`/etc/signage/kiosk.conf`** (`KIOSK_URL`, `BOARDS_URL`, `SCREEN`, scale, CEC, git repo path, update schedule). Launcher: **`/usr/local/bin/signage-kiosk`**.

If you run setup from a **git clone** of signage-suite, that directory is saved as **`SIGNAGE_REPO`** so nightly `git pull` can refresh kiosk scripts and re-run `setup-kiosk.sh --skip-apt`.

Chromium packaging differs by distro (Pi OS deb vs Ubuntu snap); the script tries `chromium-browser`, then `chromium`, then `snap install chromium`.

### Automatic updates (default on)

| Time (default) | Timer | Action |
|----------------|-------|--------|
| **03:30** | `signage-update.timer` | `apt update` / `apt upgrade`, optional `git pull` + re-apply `setup-kiosk.sh` |
| **04:00** | `signage-maint.timer` | Reboot if `/var/run/reboot-required` or packages changed; otherwise `systemctl restart signage` |

**Content on the TV** still comes from the **signage server** (`admin.php`) — kiosks only update **OS + local helper scripts**.

```bash
systemctl list-timers 'signage-*'
journalctl -u signage-update -u signage-maint -n 50
sudo /usr/local/bin/signage-kiosk-update    # manual run
```

Customize schedule when installing:

```bash
sudo bash setup-kiosk.sh "http://…/board.php?screen=garage" --update-time=02:30 --maint-time=03:15
```

Disable timers (legacy 04:00 browser-only restart):

```bash
sudo bash setup-kiosk.sh "http://…" --no-auto-update
```

**Signage server (PHP app)** updates remain on the server — `git pull` and `setup-server.sh` there, not on the Pi.

---

## HDMI-CEC (TV on / off)

Optional. When enabled, the kiosk polls:

```
{boards}/board.php?api=cec&screen=<key>
```

and runs `cec-client` standby/on according to **admin → Rotation → Display settings** (CEC / Off hr / On hr). Set **Rotation → Timezone** on the server so blank hours match the wall clock.

| Check | Command |
|-------|---------|
| Manual sync | `sudo /usr/local/bin/signage-cec-sync` |
| Logs | `journalctl -u signage-cec -f` |
| Disable | `sudo systemctl disable --now signage-cec.timer` |

The TV must have CEC enabled (Anynet+, Simplink, Bravia Sync, etc.). Re-run setup with `--no-cec` to skip CEC entirely.

---

## Day-to-day operations

```bash
systemctl status signage.service signage-watchdog.timer signage-restart.timer
journalctl -u signage -n 80 --no-pager
journalctl -u signage -f                    # live browser / cage logs
sudo systemctl restart signage.service      # recover without reboot
```

**OS updates** on the kiosk are automatic by default (`signage-update.timer` + `unattended-upgrades`). Manual check:

```bash
sudo /usr/local/bin/signage-kiosk-update
```

**After pulling signage-suite on the kiosk** (or wait for nightly git pull if `SIGNAGE_REPO` is set), scripts refresh automatically; you can still re-run setup manually:

```bash
cd ~/signage-suite && git pull
sudo bash setup-kiosk.sh "http://your-server/boards/board.php?screen=garage"
```

(Pass the same URL, scale, and `--no-cec` you used originally.)

---

## Cursor still visible

Cage draws a compositor cursor whenever a pointer-capable device exists (USB mouse, some IR/CEC receivers).

```bash
sudo apt install -y ydotool
sudo bash scripts/install-signage-blank-cursor.sh
sudo install -m 755 scripts/signage-hide-cursor.sh /usr/local/bin/signage-hide-cursor
sudo systemctl restart signage
```

Unplug unused USB mice if the pointer keeps waking.

---

## Freezes or stops rotating

Recovery is layered (board shell + systemd):

| Layer | What it does |
|-------|----------------|
| **board.php** | Unloads the hidden iframe after each crossfade (limits memory creep) |
| **board.php watchdog** | Stall ~2× dwell (+ 90s) → next board; second trip → full shell reload |
| **board.php** | Automatic shell reload every 8 hours |
| **signage-maint.timer** | Daily reboot-if-needed else browser restart |
| **signage-restart.timer** | Only when `--no-auto-update` (04:00 browser restart) |
| **signage-watchdog.timer** | Every 5 min — restarts if `board.php` HTML no longer contains `const PAGES` (3 failures) |

**Quick checks**

1. Enable **Debug** on the display row in **Rotation** — overlay shows loading vs on-screen URL.
2. Heavy boards (Grafana, Splunk published, webcam, long video) use the most GPU/RAM — keep sensible `RELOAD_SEC` on those boards.
3. **Hang (ms)** under Rotation display settings (default 20s) advances if a board never fires `onload`.
4. Re-run `setup-kiosk.sh` after updates (Chromium flags).

```bash
systemctl status signage-watchdog.timer
journalctl -u signage-watchdog -f
```

---

## Alternatives (no dedicated kiosk box)

| Option | When to use |
|--------|-------------|
| Any browser → `board.php?screen=…` | Temporary wall, smart TV browser |
| [`player.php`](rotation-and-deployment.md#playerphp--pwa-player) | PWA / tablet / laptop; needs HTTPS for install + wake lock |
| Channels DVR chrome-capture | See [rotation guide → Channels DVR](rotation-and-deployment.md#channels-dvr) |

Server install and playlists: [rotation-and-deployment.md](rotation-and-deployment.md). Board config: [boards.md](boards.md).
