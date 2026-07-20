# Rotation & deployment

## board.php — the rotation shell

Point each kiosk at `board.php?screen=<key>`. Plain `board.php` = **main** screen.

Screens, playlists, dwell times, and hour windows (0–23, overnight 22→6 supported) are edited in **admin.php → Rotation** — no file edits.

Two stacked iframes preload each board before crossfade. Hang timeout skips pages that fail to load. Weather ticker lives in the shell (persistent across transitions).

Optional **hero status strip** — a bar above the ticker showing live Kuma/Zabbix/announcement/ntfy snippets without burning rotation airtime. Configure per display under **Rotation → Display options** (enable strip, add up to four sources, height). The shell polls `board.php?api=hero` every 30s.

### Display settings (super admin)

Open **Display settings** on the Rotation page (screen list, names, shared editors):

| Setting | Purpose |
|---------|---------|
| Shared editors | Operators who may manage the full display (playlist, options, deploy) |
| Weather ticker | Per-screen NWS ticker on/off |
| Clock | Overlay on/off |
| Debug | Show debug overlay |
| Crossfade / settle / hang | Timings in ms (blank = global default) |
| Weighted / Shuffle | Rotation mode (see below) |
| Hero status strip | Persistent Kuma / Zabbix / announce / ntfy bar above ticker |
| Blank hours / CEC | HDMI-CEC power schedule |

Operators with **multiple displays** assigned, or **shared editors** on a display, see a playlist panel per screen (or a combined view where the UI groups their displays). Deploy pickers on **Slides**, **Photo Rotator**, **RSS**, and **Video** target any display they may fully edit.

Operators see display options (including hero strip) inside their assigned or shared playlist panel(s).

### Emergency override (super admin)

**Rotation → Emergency override** — site-wide takeover within ~30 seconds on every kiosk:

| Mode | Effect |
|------|--------|
| **Forced ticker** | Normal rotation continues; a custom message runs in the alert bar on every display. Optionally **include NWS weather alerts** in the same ticker (your message first). |
| **Full-screen announcement** | Every display shows one message — inline text (`emergency.php`) or an existing `announce.php?d=` item. |
| **Emergency playlist** | Every display’s rotation is replaced with the same URL list until release. |

Save draft content, then **Activate on all displays**. **Release** restores per-display playlists. While active, operators cannot save rotation changes (super admins still can). Actions are audit-logged.

Optional: **Auto-release** after N minutes, **ntfy** push on activate/release/expiry (topic defaults to the ntfy board poll topic), and a visible banner on **Status** for all admins.

### Playlists

Each screen *is* a playlist:

- Pages in order, or **Shuffle** (randomize once per full pass — every page still plays once per cycle)
- Per-page **dwell** (seconds)
- Per-page hour windows (**From hr** / **To hr**)
- **Skip** — bench a page without deleting (settings preserved)

Screens need not map to hardware: define `ambient` or `guests` and point any display at it.

### Weighted rotation

By default playlists run **sequentially** (or shuffled once per pass).

Enable **Weighted** on a display row to pick the next page at random using each entry's **Weight** (1–20, default 1). Weight 3 is ~3× as likely as weight 1. Unlike shuffle, a page can repeat before every other page has played.

**Weighted overrides Shuffle** when both are checked. Hour windows and **Skip** still apply.

Set weights on each playlist card under **Weight**.

### Multiple displays

Define screens under **Rotation → Display settings** (e.g. `main` / Living Room, `garage` / Garage).

| URL | Screen |
|-----|--------|
| `board.php` | main |
| `board.php?screen=garage` | garage |

Assign each display to **one operator** under **Users** (primary owner). With **Security → Operators may manage multiple displays** enabled (default), one operator may own several screens and manage all of their playlists and deploy targets.

**Shared editors** (super admin → **Rotation** → expand a display → check operators under **Shared editing**) may manage that display’s **full** configuration: playlist order, dwell/hours/skip, display options, hero strip, and deploy/sync — including slides and quick-add boards owned by the primary operator. Shared editors do not gain access to other displays or unrelated content boards unless they own or are shared on those rows separately.

The **Users** display picker shows only unassigned screens plus that operator’s current assignments — screens assigned to someone else are omitted.

Many kiosks can point at the same URL (independent render; shared server cache).

Unknown `?screen=` or empty playlist falls back to **main**.

### Status page

**admin.php → Status** — online kiosks, current page, photo/slide deploy sync. Read-mostly; deploy actions stay on media boards.

### Example playlist URLs

```
rss.php?feed=krebs
grafana.php?d=homelab
zabbix.php?d=network
splunk.php?d=soc
video.php?v=drone
slides.php?slide=birthday.png
webcam.php
bridgecam.php
air.php
uv.php
wotd.php
history.php
joke.php
xkcd.php
outages.php
hibp.php
cve.php
sports.php
traffic.php
```

For video entries, set dwell to the duration `php video.php fetch` reports.

---

## setup-server.sh — web host

Onboards Ubuntu / Debian / Raspberry Pi OS as the signage server.

```bash
sudo bash setup-server.sh
sudo bash setup-server.sh --webroot /var/www/html/boards --with-video-cron
sudo bash setup-server.sh --clone https://github.com/you/signage-suite.git
sudo bash setup-server.sh --domain signage.lan
sudo bash setup-server.sh --nginx
```

**Installs:** Apache or nginx, PHP 8.x + opcache, ffmpeg, dnsutils (`dig`), yt-dlp (pipx), writable dirs, deny rules for secrets, 1-hour timeouts for YouTube downloads, slide backgrounds.

**Re-run after updates:**

```bash
cd ~/signage-suite && git pull
sudo bash setup-server.sh --skip-apt --source ~/signage-suite --webroot /var/www/html/boards
```

If webroot *is* the git checkout: `cd /var/www/html/boards && sudo git pull`

---

## Dedicated kiosk machines

Fullscreen Chromium on a Pi or mini PC is covered in a dedicated guide (install, CEC, cursor, freezes, re-running after updates):

→ **[Kiosk machine setup](kiosk-setup.md)** (`setup-kiosk.sh`)

Short version:

```bash
sudo bash setup-kiosk.sh "http://your-server/boards/board.php?screen=garage"   # add 2 for 4K
sudo reboot
```

CEC blank hours are configured per display under **Rotation → Display settings**; set **Rotation → Timezone** on the server. Content changes stay in **admin.php** on the server — the kiosk only needs OS updates and occasional re-runs of `setup-kiosk.sh` after Chromium-flag changes.

---

## player.php — PWA player

Scales `board.php` to any viewport — laptops, tablets, phones. Same screens: `player.php?screen=garage`.

Add to home screen for fullscreen landscape. Wake lock keeps display on. Install and wake lock require HTTPS.

Service worker is minimal — no dynamic cache; auto-retry when server unreachable.

Ships with `manifest.webmanifest`, `sw.js`, `icon-192/512.png`.

---

## Channels DVR

**chrome-capture-for-channels** loads a webpage and streams into Channels DVR.

Use `board.php` at native 1920×1080 — no scaling. Example M3U (adjust port/host):

```
#EXTM3U
#EXTINF:-1 channel-id="signage" tvg-name="Home Signage",Home Signage
chrome://192.168.86.x:5589/stream?url=http%3A%2F%2Fserver%2Fboards%2Fboard.php
```

URL-encode the source. Use `…board.php%3Fscreen%3Dgarage` for a specific screen.

If capture resolution ≠ 1080p, use `player.php` instead. Boards are silent except the video board.

---

## Using boards outside board.php

Every board is a plain URL. External rotators, capture tools, or full-screen browsers can load `lake.php`, `rss.php?feed=ars`, etc. directly.

When not framed by `board.php`, each board renders its own ticker. Scroll position is clock-phased for seamless transitions.
