# Rotation & deployment

## board.php — the rotation shell

Point each kiosk at `board.php?screen=<key>`. Plain `board.php` = **main** screen.

Screens, playlists, dwell times, and hour windows (0–23, overnight 22→6 supported) are edited in **admin.php → Rotation** — no file edits.

Each display’s **playlist rows** are stored in **`config/rotation/pages/<screen>.json`** (not in `settings.json`). On upgrade, existing `rotation.PAGES_*` keys in settings are moved automatically the first time playlists are loaded (open **Rotation** or load `board.php?screen=…`). Screen names, kiosk options, emergency override, and saved templates stay in settings.

### Configuration storage (rotation)

| What | Where |
|------|--------|
| Playlist URL rows, dwell, hours, skip, weight | `config/rotation/pages/<screen>.json` |
| Screen list, display names, shared editors | `settings.json` → `rotation.SCREENS` |
| Per-display kiosk options (ticker, location, hero strip, shuffle, …) | `settings.json` (on each screen entry) |
| Emergency override, calendar overrides, playlist templates | `settings.json` |
| Legacy global default playlist (rare) | `settings.json` → `rotation.PAGES` — used when **main** has no own file |

**Hand-editing:** Playlist files are JSON **arrays** of objects (`url`, `dwell`, optional `from`/`to`, `weight`, …). After editing on disk, kiosks pick up changes within ~30s (rotation shell poll) or on next playlist save from admin.

**Diagnostics:** `php scripts/diagnose-rotation.php <screen>` prints the playlist file path and effective rows.

**Concurrent saves:** Operators editing **different** displays write **different** files. Two people saving the **same** display still last-write-wins on that one file.

The **Playlists** section uses a **Which display** picker at the top. Three tabs organize setup:

| Tab | Purpose |
|-----|---------|
| **Add boards** | Searchable board list — adds to the selected display’s playlist (rows already on that playlist are dimmed) |
| **Kiosk settings** | Whole-TV options: rotation mode, weather ticker + RSS fallback, hero status bar, location, sports teams, blank hours |
| **Templates** | Load built-in presets (**Kitchen weeknight**, **Weekly planner**, **Security wall**) or save/load your own (`rotation.PLAYLIST_TEMPLATES` in settings) |

Opening a playlist panel below syncs the **Which display** picker and highlights that panel. Playlist headers show **On wall: …** from live kiosk heartbeats when online. Each expanded playlist includes a **Plays now** panel — which saved rows are eligible right now (Skip / Later / Hidden), with approximate **weighted pick %** when weighted mode is on.

Some boards auto-skip from rotation when off-season or unreachable for 24h+: **lake.php** (NDBC buoy), **sports.php** (all teams off-season), **webcam.php** (per-camera embed/image probe — `?cam=` aware). They stay on the saved playlist and return when data is back.

Two stacked iframes preload each board before crossfade. Hang timeout skips pages that fail to load. Weather ticker lives in the shell (persistent across transitions). NWS alerts use each display’s **Weather / kiosk location** (not a separate ticker lat/lon). The shell polls `board.php?api=1` every **~30 seconds** and reloads when the playlist or display options change.

Framed boards that support per-display settings receive `?screen=<key>` from the shell (weather, calendar, **glance**, sports, traffic, RSS, slides, etc.). Plain `board.php` = **main**.

Optional **hero status strip** — a bar above the ticker showing live Kuma/Zabbix/announcement/ntfy snippets without burning rotation airtime. Configure per display under **Rotation → Playlists → Kiosk settings** (enable strip, add up to four sources, height). The shell polls `board.php?api=hero` every 30s.

### Display settings (super admin)

Open **Display settings** on the Rotation page (screen list, names, shared editors):

| Setting | Purpose |
|---------|---------|
| Shared editors | Operators who may manage the full display (playlist, options, deploy) |
| Weather ticker | Per-screen NWS ticker on/off |
| Clock | Overlay on/off |
| Debug | Show debug overlay |
| Crossfade / settle / hang | Timings in ms (blank = global default) |
| Weighted / Shuffle | Rotation mode — **Weighted** builds a shuffled multi-slot cycle; **Shuffle** randomizes once per in-window pass (see below) |
| Hero status strip | Persistent Kuma / Zabbix / announce / ntfy bar above ticker |
| Location | Optional lat/lon + place name for this kiosk — weather, air, UV, photo, traffic map, and NWS ticker (blank = global **Weather** board) |
| Sports teams | Up to four ESPN teams for `sports.php` on this display (blank = site default) |
| Ticker news fallback | RSS feed key for headlines when there are no NWS weather alerts (blank = ticker hidden when no alerts) |
| Glance headline columns | Left page URL / RSS fallback and right RSS feed for `glance.php` (blank = site defaults from **Today at a Glance**) |
| Blank hours / CEC | HDMI-CEC power schedule |

Operators with **multiple displays** assigned, or **shared editors** on a display, see a playlist panel per screen (or a combined view where the UI groups their displays). Deploy pickers on **Slides**, **Photo Rotator**, **RSS**, and **Video** target any display they may fully edit.

Operators see **Kiosk settings** (rotation mode, ticker, hero strip, **location**, **sports teams**, **glance headlines**, news fallback) in the **Playlists** setup area for each display they manage.

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

- Pages in **playlist order**, **Shuffle**, or **Weighted** (see below)
- Per-page **dwell** (seconds)
- Per-page hour windows — one or more ranges (e.g. 7–9 and 16–18 for commute times). Leave blank for all day. Overnight 22→6 supported.
- Optional **weekdays** per playlist row (e.g. traffic Mon–Fri only).
- **Minute precision** — use `7:30`–`9:00` instead of whole hours when needed.
- **Calendar overrides** (Rotation → Calendar overrides) — swap the playlist while a matching ICS event is active (title contains + feed key), or **Slide set only** — keep the normal playlist but show only selected slide files from the deck during the event.
- **Skip** — bench a page without deleting (settings preserved)

Screens need not map to hardware: define `ambient` or `guests` and point any display at it.

### Shuffle rotation

Enable **Shuffle each cycle** under **Kiosk settings** (or the display row for super admins). Each pass builds a **random order of in-window boards only** — every eligible page plays **once** before the deck reshuffles. Out-of-window entries are skipped until their hour window opens (the deck rebuilds when the eligible set changes). **Sequential** mode still walks playlist order (with a random starting slot so kiosks don’t all boot on item 1).

### Weighted rotation

By default playlists run **sequentially** (or shuffled once per pass when Shuffle is on).

Enable **Weighted** on a display row to build a **shuffled cycle** from in-window entries. Each entry appears in the cycle **Weight** times (1–20, default 1), so weight 3 is ~3× as often as weight 1. **Every board plays at least once** before the cycle repeats (unlike the old independent random pick). The order within each cycle is randomized so lighter boards are mixed in throughout.

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
glance.php
calendar.php
meals.php
rss.php?feed=krebs
grafana.php?d=homelab
zabbix.php?d=network
splunk.php?d=soc
video.php?v=drone
slides.php?slide=birthday.png
webcam.php?cam=grpm
webcam.php?cam=grandhaven
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

**Installs:** Apache or nginx, PHP 8.x + opcache, ffmpeg, dnsutils (`dig`), yt-dlp (pipx), writable dirs (including **`config/rotation/pages/`** for per-display playlists), deny rules for secrets, 1-hour timeouts for YouTube downloads, slide backgrounds.

**Re-run after updates:**

```bash
cd ~/signage-suite && git pull
sudo bash setup-server.sh --skip-apt --source ~/signage-suite --webroot /var/www/html/boards
```

If webroot *is* the git checkout (`--clone` install):

```bash
cd /var/www/html/boards && git pull --ff-only
sudo bash setup-server.sh --skip-apt --source /var/www/html/boards --webroot /var/www/html/boards
```

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
