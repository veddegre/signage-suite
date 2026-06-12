# Home Signage Boards

Thirteen self-contained PHP pages, all 1920×1080, all sharing the same dark-navy/amber design language. Drop them in one web directory with PHP 8+ and php-curl. Each page caches API responses in `./cache/` (created automatically — make sure the web user can write there) and falls back to stale cache on API failure, so a flaky API never blanks the wall.

## Server requirements (Debian / Ubuntu / Raspberry Pi OS)
PHP 8.1+ with curl, xml, and mbstring — on Ubuntu 24.04+ that's:

    sudo apt install apache2 libapache2-mod-php php-curl php-xml php-mbstring ffmpeg
    sudo chown -R www-data:www-data /var/www/html/boards/config /var/www/html/boards/cache /var/www/html/boards/videos

(`ffmpeg` is for video.php duration readouts; install `yt-dlp` via `pipx` rather than apt — the repo version goes stale and YouTube breaks it.)

**Important on Ubuntu's default Apache:** `/var/www` ships with `AllowOverride None`, which silently ignores the protective `.htaccess` files in `config/` and `cache/` — leaving your API tokens in `settings.json` downloadable. Add this to your site config (e.g. `/etc/apache2/conf-available/signage.conf`, then `a2enconf signage && systemctl reload apache2`):

    <DirectoryMatch "/var/www/html/boards/(config|cache)/">
        Require all denied
    </DirectoryMatch>

(Adjust the path to wherever the boards live. On nginx: `location ~ ^/boards/(config|cache)/ { deny all; }`.) Verify after deploying: `curl -I http://server/boards/config/settings.json` should return 403.

## Configuration: admin.php (start here)
All settings are managed through **admin.php** — a web frontend covering every board. On first visit it asks you to create an admin password (stored as a hash in `config/admin.json`; delete that file to reset it). Settings save to `config/settings.json`; **the board PHP files are never modified**, so the web server only needs write access to `config/` and `cache/`.

How it works: each board's old `const` values are now built-in defaults. A blank field in the admin means "use the default"; anything you enter overrides it. The Tools page can clear the API cache (handy after changing keys) and shows the raw JSON, which you can also edit by hand.

Notes for the security-minded:
- `config/settings.json` holds your API tokens. The admin drops a deny-all `.htaccess` into `config/` and `cache/` automatically (Apache). **On nginx add:** `location ~ ^/boards/(config|cache)/ { deny all; }` (adjust the path).
- Login is session-based with CSRF protection on all saves; failed logins are rate-dampened. It's built for your LAN — if you expose it further, put real auth (Cloudflare Access, VPN) in front.
- `php video.php fetch` still runs from the CLI; the admin edits the video registry, the fetcher downloads what's in it.

## index.php — Weather (built previously)
Allendale weather, RainViewer animated radar, sunrise arc. Needs `OWM_API_KEY`.

## lake.php — Lake Michigan Conditions
- **Data:** NDBC buoy 45161 (Muskegon nearshore) + NWS active alerts + computed sun times. **No API keys.**
- **Setup:** change `NWS_UA` to include a real contact email (NWS asks for this). Optionally change `NDBC_STATION`.
- Nearshore buoys are pulled for winter (~Nov–Apr); the board says so and keeps NWS alerts live.
- Swim risk is a wave-height heuristic, escalated to HIGH automatically when a Beach Hazards Statement or Rip Current alert is active.

## photo.php — Photo Conditions
- **Data:** PHP sun math (golden/blue hour), synodic moon-phase calc with drawn SVG moon, OpenWeatherMap cloud cover at sunset (tonight + next 3 evenings), NOAA SWPC Kp index + 24h forecast.
- **Setup:** set `OWM_API_KEY` (same key as the weather board). SWPC needs no key.
- Verdict heuristic: ≤20% clouds = CLEAN LIGHT, 21–70% = DRAMATIC SKY, 71–85% = MARGINAL, else FLAT GRAY. Aurora callout appears at Kp ≥ 6.

## signaltrace.php — Threat Wall
- **Data:** your SignalTrace instance via `GET /export/stats/extended` and `GET /export/json` with a 24h `from`/`to` window.
- **Setup:** set `ST_BASE_URL` and `ST_EXPORT_TOKEN` (the `EXPORT_API_TOKEN` from SignalTrace's `includes/config.local.php`).
- All API calls happen **server-side** — the export token never reaches the signage browser. 60-second cache + reload.

## homelab.php — Homelab Ops
- **Data:** Proxmox `/api2/json/cluster/resources` (node CPU/RAM, guest list, storage), AdGuard Home `/control/stats`, HTTP checks of `SERVICES`, and WAN round-trip to 1.1.1.1.
- **Setup:**
  - Proxmox: create an API token (Datacenter → Permissions → API Tokens) with a read-only role like **PVEAuditor**, then set `PVE_TOKEN_ID` / `PVE_TOKEN_SECRET`. `PVE_VERIFY_TLS=false` is the default for self-signed certs.
  - AdGuard: `ADGUARD_URL` / `ADGUARD_USER` / `ADGUARD_PASS` (same as web UI).
  - Edit the `SERVICES` array to taste.
- Each panel degrades independently — an unreachable AdGuard doesn't take down the Proxmox panel.

## rotator.php — Vedders Visuals Photo Rotator
- **Setup:** point `PHOTO_DIR` at a folder of JPG/PNG files. Photos can live outside the webroot; the page serves them itself (`?img=`) with filenames validated against the directory listing.
- Reads camera model + capture month from EXIF for the caption (`SHOW_EXIF`), dedupes "SONY SONY" style Make/Model overlap, crossfades every `INTERVAL_SEC`, honors `prefers-reduced-motion`, and reloads every 6h to pick up new files.

## family.php — Family Board
- **Setup:**
  - `ICS_FEEDS`: secret iCal URLs (Google Calendar → Settings → calendar → *Secret address in iCal format*), one entry per calendar with a color.
  - `TRASH_WEEKDAY` and `RECYCLE_ANCHOR` (any past date recycling was collected; drives the every-other-week cadence — set `''` to disable).
  - `COUNTDOWNS`: label → `YYYY-MM-DD`.
- RRULE support: DAILY, WEEKLY (BYDAY), MONTHLY (BYMONTHDAY), YEARLY, with INTERVAL/UNTIL/EXDATE. Exotic rules show only on their original date.

## rss.php — RSS Story Board
- **One file, many feeds.** Define feeds in the `FEEDS` registry, then each Anthias web asset is just `rss.php?feed=krebs`, `rss.php?feed=ars`, `rss.php?feed=petapixel`, and so on. No `?feed=` falls back to the first entry.
- Per feed, `'stories'` sets how many items to cycle and `'dwell'` sets seconds per story; omit either to use `DEFAULT_STORIES` (8) and `DEFAULT_DWELL` (12).
- Handles RSS 2.0 and Atom. Story images come from `media:content`/`media:thumbnail` (largest wins), `enclosure`, `itunes:image`, or the first `<img>` in the article body; stories with no image render as a clean typographic card.
- Synopses are tag-stripped, whitespace-collapsed, trimmed to ~280 chars at a word boundary, and common feed cruft ("The post X appeared first on…") is removed.
- Progress dots along the bottom show position in the cycle; the active dot fills over the dwell time.
- **Requires** the `php-xml` (SimpleXML) and `php-mbstring` extensions — present on most installs, `apt install php-xml php-mbstring` if not.

## video.php — Video Board (local YouTube playback)
Mimics Anthias's native video handling for web assets: videos are **downloaded locally with yt-dlp** and played fullscreen from disk — no live YouTube embed, so no ads, buffering, or embed-blocked failures on the Pi.

- **Registry:** define entries in `VIDEOS` — either `'youtube' => URL` or `'file' => 'name.mp4'` for videos you copy in yourself. Each Anthias asset is `video.php?v=drone`, `video.php?v=ambient`, etc.
- **Fetching:** run `php video.php fetch` on the server. It downloads/updates every YouTube entry into `./videos/` (capped at 1080p mp4) and **prints each video's duration with the exact Anthias asset length to set**. Re-run it any time you change a URL; cron it weekly if the source videos update:
  `0 4 * * 1 cd /var/www/boards && php video.php fetch >> /var/log/video-fetch.log 2>&1`
- The player loops, so an asset duration slightly longer than the video wraps to the start instead of going black.
- `FIT` = `'cover'` (fill) or `'contain'` (letterbox); `MUTED` defaults to true — Chromium blocks un-muted autoplay unless the kiosk runs with `--autoplay-policy=no-user-gesture-required`.
- **Requires:** `yt-dlp` in PATH for fetching (`pipx install yt-dlp` — keep it updated, YouTube breaks old versions), `ffmpeg`/`ffprobe` for merged downloads and duration readouts.
- Videos live inside the webroot (`./videos/`) so Apache/nginx serves them directly with range support — easy on a Pi.

## grafana.php — Grafana Board (kiosk iframe wrapper)
Wraps any Grafana dashboard in kiosk mode so it joins the rotation and gets the alert ticker.

- **Registry:** `DASHBOARDS` maps keys to dashboard URLs; each Anthias asset is `grafana.php?d=signaltrace`, etc. Kiosk mode, dark theme, and the per-dashboard `refresh` interval are appended automatically; `'params'` adds anything else (template vars, time range).
- **Grafana config (one time, then restart):** `[security] allow_embedding = true`, and for a login-free kiosk either `[auth.anonymous] enabled = true` with `org_role = Viewer` (fine on a LAN) **or** use a "Public dashboards" share link as the URL, which needs neither setting.
- The SignalTrace repo ships Grafana dashboards (`grafana/`) — point an entry at one of those for a richer threat view than the native threat wall.

## splunk.php — Splunk Board (REST API panels)
Splunk Web in an iframe means a login wall on the kiosk, so this board skips it: panels are **oneshot searches run server-side via the REST API** with an auth token, rendered in the same style as the rest of the wall. The token never reaches the display browser.

- **Splunk setup:** create a low-privilege signage user, mint a token under Settings → Tokens, set `SPLUNK_BASE` (management port **8089**, not Splunk Web) and `SPLUNK_TOKEN`. `SPLUNK_VERIFY_TLS = false` matches 8089's default self-signed cert.
- **Panels:** the `PANELS` array defines the grid — `'single'` (big number from a stats result), `'list'` (label + bar + count, e.g. `stats count by country`), `'trend'` (area chart from a `timechart`). Optional `'earliest'`/`'latest'` Splunk time modifiers per panel, `'unit'`, and `'wide' => true` to span two of the three columns.
- Results cache for `CACHE_TTL` (120s) per unique search, so Anthias cycling doesn't re-run searches; sizing guide: six normal panels, or four plus one wide trend, fills 1080p.

## splunkdash.php — Splunk Published Dashboard Board (10.x)
The "whole dashboard, pixel for pixel" companion to splunk.php: wraps Splunk's **published Dashboard Studio dashboards** (Splunk Enterprise 10.x / Cloud 9.3.2411+), which are viewable without login — ideal kiosk material.

- **Publish in Splunk:** open the Studio dashboard → Actions → Publish dashboard, choose a data refresh schedule (and optionally a link expiration), copy the published URL into `DASHBOARDS`. Each Anthias asset is `splunkdash.php?d=soc`, etc.
- **One web.conf change:** published pages are served by Splunk Web, which by default refuses cross-origin framing. Set `x_frame_options_sameorigin = false` under `[settings]` in `$SPLUNK_HOME/etc/system/local/web.conf` and restart. Reasonable on a LAN instance; don't do it on an internet-exposed one — in that case put the published URL straight into Anthias instead (you just lose the ticker overlay).
- **Know the refresh model:** published dashboards update on their *scheduled search* cadence — searches never run on demand. Set the publish refresh schedule to match how live you want the wall. The wrapper also hard-reloads the iframe every `reload` seconds (default 300, 0 to disable) as a backstop for long kiosk sessions.
- Network rules still apply: the published link is hosted on the Splunk instance, so firewalls/VPN/IP allowlists gate who can reach it.

## ticker.php — Weather Alert Ticker (shared include)
Every board includes this just before `</body>`. It renders **nothing** when there are no active NWS alerts; when alerts exist, a ticker overlays the bottom 72px of whichever board is on screen — amber for advisories, red with a blinking dot when anything Severe/Extreme is active.

- **In the rotation shell (board.php)** the ticker renders once in the shell itself and is genuinely persistent across page transitions; framed boards are passed `?noticker=1` so they don't draw a second copy. Opened directly, each board still shows its own ticker — and because the scroll position is computed from the wall clock rather than page-load time, it stays seamless even under an external rotator like Anthias.
- **Setup:** set `TICKER_LAT`/`TICKER_LON` and put a contact email in `TICKER_UA`. One shared cache file means the NWS API is polled at most once per `TICKER_TTL` (5 min) no matter how many boards rotate.
- `TICKER_MODE`: `'scroll'` (marquee) or `'static'` (one alert at a time, 9s each, also clock-phased).
- `TICKER_MIN_SEVERITY`: hide alerts below `Minor`/`Moderate`/`Severe`.
- `TICKER_DEMO = true` renders sample alerts so you can check the layout, then set it back to `false`.
- To add it to future boards: `<?php include __DIR__ . '/ticker.php'; ?>` before `</body>`.

## Rotation & deployment (self-hosted — no Anthias needed)

### board.php — the rotation shell (multi-screen)
Point each kiosk browser at `board.php?screen=<key>`; it cycles that screen's boards with crossfades. Screens, page lists, dwell times, and optional hour windows (0–23, overnight like 22→6 supported) are all edited in **admin.php → Rotation** — no file edits. Two stacked iframes preload each board before revealing it, a configurable hang timeout skips any page that fails to load, and the alert ticker lives in the shell so it persists across transitions.

**Playlists:** each screen *is* a playlist — pages in order (or shuffled: check **Shuffle** on the screen's row and the play order randomizes once per full pass, so every page still appears once per cycle, hour windows and Skip still apply, and nothing repeats back-to-back), per-page dwell, per-page hour windows, and a **Skip** checkbox to bench a page without deleting its row (dwell and window settings are kept for when you re-enable it). Screens don't have to map to hardware: define an `ambient` or `guests` playlist and point any display or Channels capture at it when the occasion calls for it.

**Multiple displays:** define screens on the Rotation page (one row per display — e.g. `main` / Living Room, `garage` / Garage Bench); after saving, each screen gets its own page-list editor. Point each device at its URL: plain `board.php` is the `main` screen, `board.php?screen=garage` is the garage. Any number of devices can share one URL (they render independently; the shared server-side cache means ten screens cost the same API usage as one), and a screen with no pages of its own — or an unknown `?screen=` value — falls back to the main rotation, so a freshly provisioned kiosk always shows something.

Entries are relative URLs, so parameterized boards work naturally: `rss.php?feed=krebs`, `grafana.php?d=homelab`, `video.php?v=drone` (set the dwell to the duration `php video.php fetch` reports).

### setup-kiosk.sh — the display device
Turns a fresh Raspberry Pi OS Lite (Bookworm) install into the kiosk:

    sudo bash setup-kiosk.sh "http://your-server/boards/board.php?screen=garage" [scale]

(Quote the URL when it contains `?screen=`. The optional scale argument — e.g. `2` — fills a 4K display, since boards are designed at 1920×1080.)

It installs cage (minimal Wayland compositor) + Chromium, creates a systemd service that boots into the rotation and restarts on crash, schedules a nightly 04:00 browser restart to flush memory, and prints optional HDMI-CEC cron lines for turning the TV off overnight. Chromium runs with `--autoplay-policy=no-user-gesture-required`, so un-muted video works if you ever want sound. Any small x86 box works the same way — reuse the unit file.

After setup the Pi never needs touching for content: everything is managed on the server through admin.php. The Pi only needs occasional `apt full-upgrade`.

### player.php — PWA player (phones, tablets, testing)
`board.php` is fixed at 1920×1080 by design; `player.php` wraps it in a stage that scales and letterboxes to fit **any** viewport, so you can test rotations on a laptop window, tablet, or phone. Same screen selection: `player.php?screen=garage`.

- **Install it:** open it in a mobile browser and Add to Home Screen / Install — it launches fullscreen landscape, requests a screen wake lock so the display stays on, and a tap toggles fullscreen in a normal tab. (Install and wake lock require HTTPS — put it behind your reverse proxy or Cloudflare Tunnel; plain HTTP still works fine as a regular page.)
- The service worker is deliberately minimal: nothing dynamic is cached (boards are live data), it only provides installability plus an auto-retrying "server unreachable" screen when the network drops.
- Ships with `manifest.webmanifest`, `sw.js`, and `icon-192/512.png` (sunrise-arc mark matching the weather board) — keep them in the boards folder.

### Channels DVR — signage as a TV channel
Capture tools like **chrome-capture-for-channels** load a webpage in headless Chrome and stream it into Channels DVR as a custom channel. Use `board.php` as the source URL — it's natively 1920×1080, which is exactly what these tools capture, so no scaling is involved. A typical custom-channel M3U entry (cc4c defaults to port 5589; adjust to your capture app's syntax):

    #EXTM3U
    #EXTINF:-1 channel-id="signage" tvg-name="Home Signage",Home Signage
    chrome://192.168.86.x:5589/stream?url=http%3A%2F%2Fserver%2Fboards%2Fboard.php

URL-encode the source URL, and use `...board.php%3Fscreen%3Dgarage` for a specific screen — each screen can be its own channel. If your capture app records at a non-1080p resolution, point it at `player.php` instead and it'll scale to fit. Boards are silent by design; the video board is the only source that could contribute audio.

### Still want Anthias instead?
Every board works unchanged as an Anthias web asset (`lake.php`, `rss.php?feed=ars`, ...) — set per-asset durations there and skip board.php. The per-board ticker includes handle slide cuts seamlessly either way.

## General notes
- Keep all files in one folder so they share `config/` and `cache/`.
- Every board shows a small diagnostic stamp bottom-right when an API call fails (HTTP code or curl error) while continuing to render from cache.
