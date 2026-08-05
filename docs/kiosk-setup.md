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
2. A **display** defined in **admin.php → Rotation** (e.g. `main`, `garage`) so you know the screen key.
   The kiosk URL will be `https://your-server/board.php?screen=garage`.
   Use **`https://`** when the server or reverse proxy serves TLS (recommended for iframe embed boards). Omit `?screen=` for the default **main** screen.
3. On the kiosk machine: a fresh OS install, network, and a user you can `sudo` with (script uses `$SUDO_USER`, often `pi` or your login).

See also: [HTTPS and TLS](rotation-and-deployment.md#https-and-tls) — server certs, reverse proxies, and when to use `--strict-ssl`.

---

## Quick start

From a clone of this repo on the kiosk (or copy `setup-kiosk.sh` + `scripts/` onto the box):

```bash
# Interactive — prompts for server, screen, timezone, 4K, then tests board.php
sudo bash setup-kiosk.sh

# Non-interactive
sudo bash setup-kiosk.sh --server=https://your-server --screen=garage

# Legacy full URL (still accepted; /boards/ prefix is stripped automatically)
sudo bash setup-kiosk.sh "https://your-server/board.php?screen=garage"

# 4K display — pixel-double to fill the panel
sudo bash setup-kiosk.sh --server=https://your-server --screen=garage --scale=2

# Skip HDMI-CEC TV power control
sudo bash setup-kiosk.sh --server=https://your-server --screen=garage --no-cec

# Trusted public cert (e.g. Let's Encrypt on reverse proxy) — enforce TLS validation
sudo bash setup-kiosk.sh --server=https://signage.example.com --screen=main --strict-ssl
```

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

On first run, setup **prompts for the signage server** (hostname only — no `/boards` path), **screen name**, **timezone**, and **4K scale**, then **tests** `board.php` before installing. Pass **`--server`**, **`--screen`**, and **`--timezone`** to skip prompts (used by unattended git refresh).

**Re-running setup** on a box that already has `/etc/signage/kiosk.conf` shows the saved server and screen and asks whether to keep them or reconfigure (e.g. point at a new server after moving hardware). Pass **`--server`** and/or **`--screen`** on the command line to apply changes without that prompt. Nightly **`signage-kiosk-update`** passes those flags automatically and never prompts.

Config written to **`/etc/signage/kiosk.conf`** (`SIGNAGE_SERVER`, `KIOSK_URL`, `SCREEN`, scale, CEC, git repo path, update schedule, **`SIGNAGE_TIMEZONE`**, **`KIOSK_IGNORE_SSL`**). Launcher: **`/usr/local/bin/signage-kiosk`**.

If you run setup from a **git clone** of signage-suite, that directory is saved as **`SIGNAGE_REPO`** so nightly `git pull` can refresh kiosk scripts and re-run `setup-kiosk.sh --skip-apt`.

Chromium packaging differs by distro (Pi OS deb vs Ubuntu snap); the script tries `chromium-browser`, then `chromium`, then `snap install chromium`.

---

## HTTPS and self-signed certificates

Wall boards that embed external sites (`web.php`, Grand Haven / EarthCam webcams, WMTA streams, etc.) require the **rotation shell** to load over **HTTPS**. The server installer enables TLS by default ([setup-server.sh](rotation-and-deployment.md#setup-serversh--web-host)); many home installs use a **self-signed** cert.

**Kiosk default:** Chromium starts with `--ignore-certificate-errors` and `--allow-insecure-localhost`, so a self-signed server cert does **not** show “Your connection is not private” — the wall goes straight to `board.php`.

```bash
# Self-signed or LAN HTTPS (default)
sudo bash setup-kiosk.sh --server=https://192.168.1.50 --screen=main

# Public URL with Let's Encrypt on your reverse proxy — validate certs normally
sudo bash setup-kiosk.sh --server=https://signage.example.com --screen=main --strict-ssl
```

| Flag | When to use |
|------|-------------|
| *(none)* | Self-signed cert on server, or HTTPS via LAN IP/hostname |
| **`--strict-ssl`** | Trusted public certificate (e.g. Let's Encrypt at reverse proxy) |

**Reverse proxy:** Point the proxy at **`http://signage-host/boards/`** on port 80. Give kiosks the proxy’s **`https://`** URL. The server installer does **not** redirect port 80 to 443 by default. Set **Security → Trusted reverse proxies** in admin so **Status** shows each kiosk’s real IP ([admin-and-security.md](admin-and-security.md#trusted-reverse-proxies)). Full diagram: [HTTPS and TLS → Reverse proxy](rotation-and-deployment.md#reverse-proxy-recommended-production).

After changing URL or SSL flags, re-run setup and restart:

```bash
sudo bash setup-kiosk.sh --server=https://… --screen=garage
sudo systemctl restart signage
```

**Note:** [`player.php`](rotation-and-deployment.md#playerphp--pwa-player) (phone/tablet PWA) uses the user’s normal browser — it does **not** get these Chromium flags. Use a trusted cert or accept the warning manually on that device.

---

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
sudo bash setup-kiosk.sh --server=https://… --screen=garage --update-time=02:30 --maint-time=03:15
```

Disable timers (legacy 04:00 browser-only restart):

```bash
sudo bash setup-kiosk.sh "https://…" --no-auto-update
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
sudo bash setup-kiosk.sh "https://your-server/boards/board.php?screen=garage"
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

## Brief flash of the Linux console / terminal text

This is **not** the rotation playlist ending — `board.php` loops forever (shuffle/sequential/weighted). When you see shell scrollback or a login prompt, **Chromium or cage exited** and the TV is showing **tty1** underneath until the browser comes back.

Common triggers:

| Cause | When |
|-------|------|
| **Chromium crash / OOM** | Random, often on heavy iframe boards (Grafana, Splunk, webcam) |
| **signage-maint.timer** | Daily ~04:00 — intentional `systemctl restart signage` (memory flush) |
| **signage-watchdog** | After 3 failed health checks (~15 min apart if the server was unreachable) |
| **Package updates** | Reboot when kernel/apt updates require it |
| **board.php reload** | Every 8h or after admin saves rotation — stays in-browser (usually a dark flash, not the console) |

**Diagnose on the kiosk**

```bash
journalctl -u signage --since "1 hour ago" --no-pager
systemctl status signage
systemctl list-timers 'signage-*'
```

Look for `Main process exited`, `code=killed`, or `signage-maint: restarting signage.service`.

**Mitigation (in repo):** `signage-kiosk` now blackens tty1 and restarts cage in a loop (~1s) instead of leaving the console visible during systemd’s restart window. Re-run `setup-kiosk.sh` (or copy updated `/usr/local/bin/signage-kiosk` and `signage-kiosk-watchdog`) on each Pi/box.

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
