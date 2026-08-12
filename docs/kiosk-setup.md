# Kiosk machine setup

Turn a dedicated Linux box into a fullscreen Chromium display pointed at your signage server. Content stays on the **server** (`admin.php`); the kiosk is just a browser that boots into rotation.

| | |
|---|---|
| **Script** | [`setup-kiosk.sh`](../setup-kiosk.sh) at the repo root |
| **OS** | Raspberry Pi OS Lite (Bookworm+) or Ubuntu Server 24.04+ |
| **Hardware** | **Recommended:** x86 mini PC / NUC (8 GB+). **Pi:** Raspberry Pi **5** (8 GB) for video & advanced boards; Pi 4 — basic playlists only |
| **Display** | Boards are designed at **1920×1080**; `board.php` CSS-scales to fill larger panels (4K) |

---

## Prerequisites

1. **Signage server** already running (`setup-server.sh`) and reachable on the LAN.
2. A **display** defined in **admin.php → Rotation** (e.g. `main`, `garage`) so you know the screen key.
   The kiosk URL will be `https://your-server/board.php?screen=garage`.
   Use **`https://`** when the server or reverse proxy serves TLS (recommended for iframe embed boards). Omit `?screen=` for the default **main** screen.
3. On the kiosk machine: a fresh OS install, network, and a user you can `sudo` with (script uses `$SUDO_USER`, often `pi` or your login).

See also: [HTTPS and TLS](rotation-and-deployment.md#https-and-tls) — server certs, reverse proxies, and when to use `--strict-ssl`.

---

## Hardware requirements

**Recommended overall:** an **x86 mini PC or NUC** (Intel N100 / i3 class or better, **8 GB RAM**, SSD) running **Ubuntu Server 24.04+**. More CPU, GPU, and memory headroom for iframe dashboards (Grafana, Splunk), live webcams, animated maps, and YouTube live embeds in one rotation.

**Raspberry Pi:** supported, but tiered by model.

| Tier | Hardware | Good for |
|------|----------|----------|
| **Recommended Pi** | **Raspberry Pi 5** (prefer **8 GB**), official **5 V / 5 A** PSU | Full rotation including **video**, **live webcams**, **animated map boards**, moderate iframe load |
| **Basic Pi** | Raspberry Pi **4** (4 GB+) | Static boards — weather, slides, RSS, Zabbix API walls, simple rotation **without** heavy canvas, live video, or YouTube embeds |
| **Not recommended** | Pi 3 / Zero | Kiosk use |

### Advanced boards (Pi 5 or x86)

These need **Pi 5 or better**, or **x86** — a Pi 4 will stutter, spin, or never finish loading:

| Category | Examples |
|----------|----------|
| **Video** | `video.php` **YouTube live** embeds; long local 1080p files in heavy playlists |
| **Live webcams** | WetMet / GRPM, EarthCam iframe, Muskegon HLS |
| **Animated maps** | Cloudflare / SANS / IODA attack & heat maps (canvas + Leaflet) |
| **Heavy iframes** | Grafana, Splunk published, Power BI, `web.php` embeds |

**Best practice for on-demand YouTube:** download on the **server** with `php video.php fetch` and play the local MP4 — works on Pi 4 and Pi 5 ([video-youtube.md](video-youtube.md)).

**Playlist design:** even on Pi 5 or x86, avoid stacking many heavy iframe boards back-to-back; use sensible dwell times and **`RELOAD_SEC`** on embed boards ([Freezes](#freezes-black-screen-or-console-text) below).

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

# 4K display — also set scale 2 (helps Chromium DPI when the flag is honored;
# board.php CSS-fit fills the panel either way)
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
| **signage-update.timer** | Daily **03:30** (default) — `apt upgrade` + `git pull` / `setup-kiosk.sh --skip-apt` |
| **signage-maint.timer** | Daily **04:00** (default) — **`git pull` + setup again**, then reboot if needed else browser restart |
| **unattended-upgrades** | Security patches between nightly runs |
| **signage-watchdog.timer** | Every **5 min** — restarts `signage` if `board.php` stops responding |
| **signage-cec.timer** | Every **1 min** — polls server CEC schedule (unless `--no-cec`) |
| **signage-cursor-vt.service** | **Pi only** — VT switch to hide cage’s compositor pointer |
| **Blank cursor theme** | Transparent Xcursor theme (Chromium client cursors only) |
| **signage-hide-cursor** | **x86** — ydotool parks pointer off-screen (no VT switch on NUCs) |

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
| **03:30** | `signage-update.timer` | `apt update` / `apt upgrade`; then **`git pull` + `setup-kiosk.sh --skip-apt`** (if `SIGNAGE_REPO` is set) |
| **04:00** | `signage-maint.timer` | **`git pull` + `setup-kiosk.sh --skip-apt` again**, then reboot if kernel/packages need it; otherwise `systemctl restart signage` |

Every night the maint window **always** syncs the local git clone and re-installs kiosk scripts/systemd units **before** reboot or browser restart — so boxes pick up repo changes even when `apt` did not need a reboot.

**Content on the TV** still comes from the **signage server** (`admin.php`) — kiosks only update **OS + local helper scripts**.

```bash
systemctl list-timers 'signage-*'
journalctl -u signage-update -u signage-maint -u signage-sync -n 50
sudo /usr/local/bin/signage-kiosk-sync-repo   # manual git pull + setup only
sudo /usr/local/bin/signage-kiosk-update      # manual apt + sync
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

## Map boards on Pi (attack / heat animations)

Animated map boards (Cloudflare attack maps, SANS DShield heatmaps, IODA) use a full-screen canvas at 60 fps on desktop. A **Pi 4** kiosk is underpowered — use **Pi 5 or x86** (see [Hardware requirements](#hardware-requirements)). Boards auto-tune on ARM/Linux kiosks:

- Caps canvas resolution (DPR 1)
- Limits redraws to ~24 fps (adaptive if still slow)
- Caches arc/point geometry instead of recomputing every frame
- Drops expensive glow (`shadowBlur`) and extra labels on low-end profile

Deploy updated signage-suite on the **server** (boards are rendered there, not on the Pi). Kiosks pick it up on the next rotation reload (~30s) or when the board page reloads.

**Debug overrides** (append to board URL in admin preview):

| Query | Effect |
|-------|--------|
| `?mapperf=low` | Force Pi-style tuning (test on desktop) |
| `?mapperf=high` | Force full-quality 60 fps |

If still choppy: lower **Max flows** on attack maps in admin, or use **scale 1** on 1080p TVs (`KIOSK_SCALE=1` in `/etc/signage/kiosk.conf`).

### GRPM / WetMet webcam (`webcam.php?cam=grpm`)

Uses **direct HLS** first (fresh signed URL from your signage server), then falls back to WetMet’s iframe if needed. **Auto-recovery** mimics a manual browser reload:

- Re-fetches a new signed playlist on a timer (every **5–10 minutes**; tune via admin → Webcam → **Live stream token refresh**)
- Retries HLS twice on fatal errors or startup stall before iframe fallback
- Reloads the iframe with a cache-bust query when in fallback mode
- Detects frozen video (no frame advance for ~45s) and triggers recovery

WetMet’s embed also cycles its player about every **5 minutes** — a brief flash can still happen on the iframe path.

When WetMet’s HLS feed is offline, **`webcam.php?cam=grpm` is auto-skipped in rotation** (and shows “Webcam not available” if opened directly). The server re-probes on a timer (default **30 minutes**; shorten in admin → **Webcam** → **Offline re-probe interval** for testing) and adds it back when the stream is live again.

**Debug:** `?mapperf=low` on the webcam URL forces the direct-HLS path on any browser. Server-side: `php scripts/diagnose-webcam.php grpm --refresh`.

### Video & YouTube on the kiosk

| Source | Pi 4 | Pi 5 | x86 (recommended) |
|--------|------|------|-------------------|
| **Downloaded MP4** (`video.php fetch` on server) | OK | OK | OK |
| **YouTube live embed** (`video.php` + Live stream) | Poor | Often OK | Best |
| **Live webcam / EarthCam iframe** | Poor | Usually OK | Best |

Use the **Video board**, not Webcam or Websites, for YouTube. Details: [video-youtube.md](video-youtube.md).

---

## Cursor on x86 (mini PC / NUC)

**cage** may draw a compositor pointer (center of screen). **`setup-kiosk.sh` uses ydotool on x86 only** — it parks the libinput pointer off-screen. **Do not use the VT switch on NUCs** — it briefly switches to tty2 and flashes a **login prompt** on the TV (especially after Chromium snap updates restart `signage.service`).

```bash
systemctl status signage-cursor-vt   # should be inactive/disabled on NUCs
bash scripts/signage-diagnose-kiosk.sh
```

### Screen blinking between signage and a login prompt (NUC)

Cause: **`signage-cursor-vt.timer`** running `chvt 2` / `chvt 1` every 10 minutes (or after each browser restart). tty2 still had **getty** → visible login screen during the switch.

**Fix immediately:**

```bash
sudo systemctl disable --now signage-cursor-vt.timer signage-cursor-vt.service
sudo systemctl disable --now getty@tty2.service getty@tty1.service
sudo systemctl restart signage
```

Then re-run setup from git (disables VT fix on x86 permanently):

```bash
cd ~/signage-suite && git pull
sudo bash setup-kiosk.sh --skip-apt
```

---

## Cursor on Raspberry Pi (phantom HDMI pointer)

On many **Pi kiosks**, **cage** draws a compositor pointer from the HDMI/CEC input node (`vc4-hdmi-0`) — **no mouse plugged in**. CSS and blank Xcursor themes cannot remove it.

**`setup-kiosk.sh` installs the fix automatically on Raspberry Pi:** `signage-cursor-vt.service` waits for `board.php` to load, settles ~90s, then runs a VT switch (`chvt 2` / `chvt 1`) while **cage keeps running**. The same service is triggered when `signage.service` restarts (watchdog, maintenance).

**Do not use** on Pi:

| Approach | Why |
|----------|-----|
| **ydotool** | Black screen / journal spam |
| **libinput udev ignore** (`LIBINPUT_IGNORE_DEVICE`) | Black screen on some Pis (kills CEC keyboard events too) |

### Logs and manual re-run

```bash
journalctl -u signage-cursor-vt -f
sudo systemctl start signage-cursor-vt.service
```

After a fresh `setup-kiosk.sh` on an already-running Pi (without reboot):

```bash
cd ~/signage-suite && git pull
sudo bash setup-kiosk.sh --skip-apt
sudo systemctl start signage-cursor-vt.service
```

Documented for Pi + cage in [cage#299](https://github.com/cage-kiosk/cage/issues/299).

**Undo:**

```bash
sudo systemctl disable --now signage-cursor-vt.service
sudo rm /etc/systemd/system/signage-cursor-vt.service /usr/local/bin/signage-suppress-cursor-vt
sudo systemctl daemon-reload
# Re-run setup-kiosk.sh to drop ExecStartPost from signage.service
```

### Manual one-liner (debug)

After the wall has been up for ~2 minutes:

```bash
sudo chvt 2 && sleep 1 && sudo chvt 1
```

### If the cursor comes back ~1 minute later

The VT switch was too early (page still repainting). Re-run the service — it retries with a confirmation window. Or increase settle time:

```bash
sudo SIGNAGE_CURSOR_VT_SETTLE=120 systemctl start signage-cursor-vt.service
```

### Cleanup failed experiments

```bash
sudo bash scripts/signage-fix-cursor-pi.sh --cleanup
```

### Full display rollback

```bash
sudo bash scripts/signage-restore-display.sh
sudo reboot
```

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
| **signage-watchdog.timer** | Every 5 min (first check 2 min after boot) — restarts if the server is down **or** the browser is hung with no heartbeat |

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

## Stuck on `[ OK ] loading target graphical.target`

This is usually **misleading** — the Pi is not starting a desktop. That line is just the **last boot message left on tty1** while `signage.service` has not taken over the display yet.

The race-condition fix added **`ExecStartPre` wait-for-runtime**, which blocked for up to **2 minutes before any blackout or browser start**, because `/run/user/<uid>` does not exist until the PAM login session opens (which happens at `ExecStart`, not before). Boot scrollback — including `graphical.target` — stays frozen on the TV the whole time.

**Fix on the player:**

```bash
cd ~/signage-suite && git pull
sudo bash setup-kiosk.sh --skip-apt   # removes ExecStartPre wait; enables linger + seatd
sudo systemctl restart signage
```

If it still hangs, check logs (server wait can take up to ~4 min on a slow network):

```bash
journalctl -u signage -b --no-pager | tail -40
grep KIOSK_URL /etc/signage/kiosk.conf
curl -k "$(grep KIOSK_URL /etc/signage/kiosk.conf | cut -d= -f2- | tr -d '"')" | grep -q 'const PAGES' && echo server OK
```

Also confirm the new screen key has **pages in its playlist** in admin → Rotation.

---

## Blank screen until `systemctl restart signage`

Rebooting may not help; only restarting the **signage** service fixes it. On **x86 mini PCs / NUCs**, the usual cause is a **VT/DRM race at boot**: cage starts before the kernel attaches scanout to HDMI, so the TV stays black even though `signage.service` is running. Opening another console (tty2) and switching back often “wakes” the display — same effect as `chvt 1`.

**Fix (re-run on each player — installs display prep + boot retry):**

```bash
cd ~/signage-suite && git pull
sudo bash setup-kiosk.sh --skip-apt   # VT/DRM prep, user@ linger, boot retry timer
sudo reboot
```

What changed:

| Fix | Purpose |
|-----|---------|
| **`signage-kiosk-wait-for-display`** (root, before cage) | `chvt 1` + wait for `/dev/dri/card*` |
| **`user@<uid>.service` + linger** | `/run/user/<uid>` exists before cage (PAM session) |
| **Removed wrong `XDG_RUNTIME_DIR=/run/user/%U`** | Let PAM set numeric UID path |
| **`signage-boot-retry.timer`** | At 90s after boot, restart if cage never started |
| **Snap Chromium** | Waits for `snapd.seeded.service` on Ubuntu |

**Other cause:** Chromium launched before the network or signage server was ready, loaded a blank/error page, and stayed running — systemd thinks everything is fine. The server wait + watchdog heartbeat cover that case.

**Diagnose on the kiosk**

```bash
# Server OK from the shell?
curl -k "$(grep KIOSK_URL /etc/signage/kiosk.conf | cut -d= -f2- | tr -d '"')" | grep -q 'const PAGES' && echo server OK

# Browser actually reporting in? (after board.php update)
curl -k "…board.php?screen=YOURSCREEN&api=kiosk-health"

journalctl -u signage --since "30 min ago" | grep -E 'waiting for server|restarting'
journalctl -u signage-watchdog --since "30 min ago"
```

If **server OK** but the TV is blank, the browser is hung — exactly the case `signage-watchdog` detects via missing heartbeats (requires `board.php?api=kiosk-health` on the server).

**Verify the API is deployed** (from kiosk or server):

```bash
curl -k "https://YOUR-SERVER/board.php?screen=YOURSCREEN&api=kiosk-health"
```

You should get a **small JSON** blob like `{"ok":true,"online":false,...}`. If you get a full HTML page (~50KB), the server needs `git pull` + the watchdog cannot auto-recover hung browsers until that is deployed.

**Fix (re-run on each new player)**

```bash
cd ~/signage-suite && git pull
sudo bash setup-kiosk.sh --skip-apt   # refreshes scripts + systemd units
sudo systemctl restart signage
```

This installs:

- **Wait for server** before launching Chromium (up to 4 min at boot)
- **Wait for XDG runtime + seatd** before cage starts
- **Display prep** (`chvt 1`, DRM ready) on x86 before cage
- **Boot retry** at 90s if cage never started
- **Watchdog heartbeat check** — restarts if the server responds but the screen has not checked in for ~10 min

---

## Alternatives (no dedicated kiosk box)

| Option | When to use |
|--------|-------------|
| Any browser → `board.php?screen=…` | Temporary wall, smart TV browser |
| [`player.php`](rotation-and-deployment.md#playerphp--pwa-player) | PWA / tablet / laptop; needs HTTPS for install + wake lock |
| Channels DVR chrome-capture | See [rotation guide → Channels DVR](rotation-and-deployment.md#channels-dvr) |

Server install and playlists: [rotation-and-deployment.md](rotation-and-deployment.md). Board config: [boards.md](boards.md).
