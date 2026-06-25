# Board reference

Every board is a **1920×1080** PHP page with shared styling. Configure all boards in **admin.php**; settings save to `config/settings.json`.

## Quick index

| Group | Board | File | Rotation URL | Keys |
|-------|-------|------|--------------|------|
| Weather & home | Weather | `index.php` | `index.php` | OpenWeatherMap |
| | Lake Michigan | `lake.php` | `lake.php` | — |
| | Webcam | `webcam.php` | `webcam.php` | — |
| | Mackinac Bridge cam | `bridgecam.php` | `bridgecam.php` | — |
| | Photo conditions | `photo.php` | `photo.php` | OpenWeatherMap |
| | Air & pollen | `air.php` | `air.php` | Google Pollen (optional) |
| | UV index | `uv.php` | `uv.php` | — |
| | Detroit sports | `sports.php` | `sports.php` | — |
| | Calendar | `calendar.php` | `calendar.php` | — |
| | Traffic map | `traffic.php` | `traffic.php` | TomTom |
| **Daily** | Word of the day | `wotd.php` | `wotd.php` | — |
| | This day in history | `history.php` | `history.php` | — |
| | Dad jokes | `joke.php` | `joke.php` | — |
| | XKCD comic | `xkcd.php` | `xkcd.php` | — |
| Monitoring | SignalTrace | `signaltrace.php` | `signaltrace.php` | Export token |
| | Homelab ops | `homelab.php` | `homelab.php` | Proxmox, AdGuard |
| | Zabbix | `zabbix.php` | `zabbix.php?d=<key>` | API token |
| Media | Photo rotator | `rotator.php` | `rotator.php` | — |
| | Custom slides | `slides.php` | `slides.php?slide=…` | — |
| | Video | `video.php` | `video.php?v=<key>` | — |
| | RSS | `rss.php` | `rss.php?feed=<key>` | — |
| Dashboards | Grafana | `grafana.php` | `grafana.php?d=<key>` | — (iframe) |
| | Splunk panels | `splunk.php` | `splunk.php?d=<key>` | Splunk token |
| | Splunk published | `splunkdash.php` | `splunkdash.php?d=<key>` | — (iframe) |
| | Websites | `web.php` | `web.php?d=<key>` | — (iframe) |

---

## Weather & home

### index.php — Weather

Allendale weather, RainViewer animated radar, sunrise arc.

**Setup:** set `OWM_API_KEY` in admin → **Weather**.

### lake.php — Lake Michigan Conditions

**Data:** NDBC buoy **45029** (Holland nearshore — wave height, closer to Grand Haven) + NWS active alerts + computed sun times. No API keys.

**Setup:** change `NWS_UA` to a real contact email (NWS requires this). Optionally change `NDBC_STATION` (Muskegon **45161** often reports met data without `WVHT`).

Nearshore buoys run in winter (~Nov–Apr); the board notes when they are offline and keeps NWS alerts live. Swim risk uses a wave-height heuristic, escalated to HIGH when a Beach Hazards Statement or Rip Current alert is active.

### webcam.php — Grand Haven Beach Webcam

Full-screen EarthCam embed from [Surf Grand Haven](https://surfgrandhaven.com) (MACkite).

**Setup:** admin → **Webcam** — paste iframe `src` if the default breaks. Optional title + clock overlay.

**Rotation:** long dwell (120s+). Default hourly iframe reload (`RELOAD_SEC`) clears memory on long kiosk sessions — set `0` to disable.

### bridgecam.php — Mackinac Bridge Cam

Full-screen live stills from the [Mackinac Bridge Authority bridge cams](https://www.mackinacbridge.org/fares-traffic/bridge-cam/). Four views: Mackinaw City (north), St. Ignace dock, Bridge View Park, and administration building. Source images update about every 60 seconds.

**Setup:** admin → **Mackinac Bridge Cam** — pick one camera or rotate through all four. Default is the Mackinaw City north view. No API key.

**Rotation:** 90s+ dwell works well; the board refreshes the image every 60s on its own.

### photo.php — Photo Conditions

**Data:** PHP sun math (golden/blue hour), synodic moon phase (SVG), OpenWeatherMap sunset cloud cover (tonight + next 3 evenings), NOAA SWPC Kp + 24h forecast.

**Setup:** `OWM_API_KEY` (same as weather). SWPC needs no key.

Verdict: ≤20% clouds = CLEAN LIGHT, 21–70% = DRAMATIC SKY, 71–85% = MARGINAL, else FLAT GRAY. Aurora panel highlights when Kp ≥ 6.

### air.php — Air & Pollen

US AQI, PM2.5/PM10, ozone, NO₂, pollen bars, three-day outlook.

**Data:** [Open-Meteo Air Quality API](https://open-meteo.com/en/docs/air-quality-api) — free, no key. US pollen uses **Google Pollen API** (optional); Open-Meteo pollen is Europe-only.

**Setup:** admin → **Air & Pollen** — place name, lat/lon, timezone. For US pollen: enable Pollen API in Google Cloud, paste key (5,000 calls/month free tier; 15-minute cache keeps usage low).

Without Google key, air quality works; pollen shows a setup note. Default cache TTL 900s.

### uv.php — UV Index

Current UV, today's hourly curve (sunrise–sunset), peak/clear-sky stats, and 5-day daily maximum.

**Data:** [Open-Meteo Forecast API](https://open-meteo.com/en/docs) — free, no key.

**Setup:** admin → **UV Index** — place name, lat/lon, timezone. Uses WHO UV bands (Low through Extreme) with sun-protection advice. Default cache TTL 900s.

### wotd.php — Word of the Day

Large word, pronunciation, part of speech, definition, etymology, and a real-world usage quote from Wordsmith.

**Data:** [Wordsmith.org A.Word.A.Day](https://wordsmith.org/) RSS + word page + [Free Dictionary API](https://dictionaryapi.dev/) for phonetic/alternate senses only — no keys.

**Setup:** admin → **Word of the Day** — title/subtitle, timezone. Cache TTL defaults to 24 hours (one word per calendar day).

### history.php — This Day in History

Featured event with year, thumbnail when available, more highlights, plus born/died snippets.

**Data:** [Wikipedia REST API](https://en.wikipedia.org/api/rest_v1/) on-this-day feed — no key.

**Setup:** admin → **This Day in History** — title, highlight count (4–12), timezone. Cache TTL defaults to 24 hours.

### joke.php — Dad Jokes

One random dad joke per visit, large type for the wall.

**Data:** [icanhazdadjoke.com API](https://icanhazdadjoke.com/api) — free, no key (set a descriptive User-Agent in admin).

**Setup:** admin → **Dad Jokes** — title, User-Agent, cache TTL (default 90s so rotation gets fresh jokes). Default reload 0 — the rotation shell fetches a new joke each time the slide appears.

### xkcd.php — XKCD Comic of the Day

Latest comic from [xkcd.com](https://xkcd.com/) — title, image, and hover text (alt).

**Data:** Official JSON API at `https://xkcd.com/info.0.json` — free, no key.

**Setup:** admin → **XKCD Comic** — title/subtitle, show hover text, timezone. Cache TTL defaults to 24 hours (new comic when Randall publishes, usually Mon/Wed/Fri).

### sports.php — Detroit Sports

Lions, Tigers, Pistons, Red Wings — 2×2 cards plus **Next games** strip.

**Data:** ESPN public API — no key. Server-side cache (default 300s; scoreboards refresh sooner).

**Setup:** admin → **Detroit Sports** — title, subtitle, timezone. ~75s dwell is a reasonable rotation default.

Season logic uses calendar windows plus nearby games. Live games show score + period; direct view auto-refreshes ~2 minutes while any team is live.

### traffic.php — Traffic Map

TomTom Traffic Flow on dark Carto basemap (Leaflet). Defaults to Allendale ↔ Grand Rapids; center, zoom, and labels editable in admin.

**Setup:** [developer.tomtom.com](https://developer.tomtom.com/) key with **Traffic API** enabled → admin → **Traffic Map**. Tiles served via `traffic_tiles.php` (key never in browser).

**Troubleshoot** (on server):

```bash
curl -I "http://localhost/boards/traffic_tiles.php?style=relative0-dark&z=11&x=536&y=753"
```

Expect **200** and `Content-Type: image/png`. Errors logged to `cache/traffic_tiles/last_error.txt`. Try flow style `relative0` for brighter colors, or zoom 12.

### calendar.php — Calendar

**Admin:** **Calendar** board. Settings use `calendar.*` prefix (auto-migrates from legacy `family.*`).

**Legacy:** `family.php` redirects to `calendar.php` (301).

**Setup:**

- **ICS feeds:** one row per calendar — key (legend label), color, URL
- **Trash/recycle:** `TRASH_WEEKDAY`, `RECYCLE_ANCHOR` (any past recycle date). Leave trash day as default to hide the chip
- **Countdowns:** label → `YYYY-MM-DD`

RRULE support: DAILY, WEEKLY (BYDAY, INTERVAL, WKST), MONTHLY (BYMONTHDAY), YEARLY, with UNTIL/EXDATE.

---

## Monitoring

### signaltrace.php — Threat Wall

**Data:** SignalTrace `GET /export/stats/extended` and `GET /export/json` (24h window).

**Setup:** `ST_BASE_URL` and `ST_EXPORT_TOKEN` (`EXPORT_API_TOKEN` from SignalTrace's `includes/config.local.php`).

All calls server-side; export token never reaches the kiosk. 60-second cache.

### homelab.php — Homelab Ops

**Data:** Proxmox cluster resources, AdGuard Home stats, HTTP service checks, WAN latency to 1.1.1.1.

**Setup:**

| Source | Credentials |
|--------|-------------|
| **Proxmox** | API token (Datacenter → Permissions → API Tokens), read-only role like **PVEAuditor**. `PVE_VERIFY_TLS=false` for self-signed certs |
| **AdGuard** | `ADGUARD_URL`, `ADGUARD_USER`, `ADGUARD_PASS` (same as web UI) |
| **Services** | Add HTTP(S) URLs to ping — empty by default |

Each panel degrades independently.

### zabbix.php — Zabbix Monitoring (JSON-RPC, 7.x)

Zabbix Web in an iframe means a login wall on the kiosk. This board uses **Zabbix 7.x JSON-RPC** (`api_jsonrpc.php`) server-side — active problems and host status with no iframe. The API token never reaches the display browser.

**Zabbix setup:**

1. Create a read-only user with **Problem read** and **Host read** on the host groups you need
2. **Users → API tokens** — create a token for that user
3. Admin → **Monitoring → Zabbix Monitoring → Board settings:** `ZABBIX_URL` (base URL only, e.g. `https://zabbix.example.com`), `ZABBIX_TOKEN`, `ZABBIX_VERIFY_TLS` (off for LAN self-signed certs)
4. If Zabbix is on a private IP → **Security → Allow private URL fetches**

**Multiple pages:** each admin tab is `zabbix.php?d=<key>` (default key `main`). Use separate pages for different host-group scopes — e.g. `network` vs `signage`.

**Per-page settings:**

| Setting | Purpose |
|---------|---------|
| Host groups | Comma-separated **exact** Zabbix host group names |
| Minimum severity | Not classified through Disaster |
| Max problems / Max hosts | List limits (defaults 12 / 24) |
| Hide acknowledged | Omit acknowledged problems from the wall |
| Off wall | Keep in admin but skip on kiosk |
| Access | Owner + shared with (operators) |

**Wall layout:** severity summary pills, active problem list (host, age, acknowledged), host grid (green = OK, red = problem, grey = disabled). Cache **`CACHE_TTL`** default 60s. Quick-add under **Monitoring** in Rotation.

---

## Media

### rotator.php — Photo Rotator

**Upload:** admin → **Photo Rotator** — JPG/PNG up to 25 MB. Deploy sync status on **Status**.

Photos in `./photos/` by default; served via `?img=` (not direct HTTP). EXIF captions, crossfade, configurable brand wordmark. Reloads every 6h for new files.

### slides.php — Custom Slides

Upload JPG/PNG/WebP or build slides in the **Slide creator** (templates, photo scenes, theme colors).

**Rotation:** each enabled slide is `slides.php?slide=…` with its own dwell. Deploy from **Custom Slides** or **Status**.

**Scheduling (per slide):**

| Mode | Behavior |
|------|----------|
| **always** | Whenever deck is on screen (optional hour window) |
| **once** | Single date (`YYYY-MM-DD`) |
| **range** | Inclusive from … to |
| **yearly** | Every `MM-DD` |
| **yearly_range** | MM-DD through MM-DD each year (e.g. holidays) |
| **monthly** | Day of month (1–31) |
| **weekly** | Weekday and/or `Mon,Wed,Fri` |
| **Priority** | When any priority slide is active, only priority slides show |
| **Off** | Bench without deleting |

Optional **Hr from / Hr to** (0–23, overnight 22→6 works). Board reloads every 5 minutes for schedule boundaries.

### rss.php — RSS Story Board

Keyed feeds: `rss.php?feed=krebs`, etc. Per-feed story count and dwell; defaults 8 stories / 12s.

RSS 2.0 and Atom. Images from media/enclosure/itunes/body. Progress dots show cycle position.

**Requires:** `php-xml`, `php-mbstring`.

### video.php — Video Board

Videos downloaded locally with **yt-dlp** — no live YouTube embed (no ads, no embed blocks on headless servers).

**Registry:** `youtube` URL or local `file` per entry → `video.php?v=<key>`.

Muted by default; uncheck **Mute all videos** in admin if needed. Refresh via admin UI or `php video.php fetch`.

See [video-youtube.md](video-youtube.md) for bot checks, cookies, and cron.

---

## Dashboards & integrations

### grafana.php — Grafana (iframe)

`grafana.php?d=<key>`. Registry maps keys to dashboard URLs; kiosk mode, dark theme, and refresh appended automatically.

**Grafana (one time):** `[security] allow_embedding = true`, plus either `[auth.anonymous] enabled = true` with `org_role = Viewer` **or** a public dashboard share URL.

### splunk.php — Splunk panels (REST API)

Oneshot searches server-side — no Splunk Web iframe.

**Setup:** low-privilege Splunk user, token under Settings → Tokens. `SPLUNK_BASE` = management port **8089** (not Splunk Web), `SPLUNK_TOKEN`.

**Panel types:** `single` (big number), `list` (label + bar + count), `trend` (timechart). Optional `earliest`/`latest`, `unit`, `wide` (2 columns).

Multi-page: `splunk.php?d=<key>` with per-page panel decks and ownership. Cache default 120s per search.

### splunkdash.php — Splunk published (iframe)

Splunk Enterprise 10.x / Cloud published Dashboard Studio dashboards.

**Setup:** publish in Splunk, copy URL to registry. Set `x_frame_options_sameorigin = false` in Splunk `web.conf` for LAN embeds. Wrapper reloads iframe on `reload` interval (default 300s).

### web.php — Websites (iframe)

Keyed sites: `web.php?d=<key>`. Target URLs must allow iframe embedding.

---

## ticker.php — Weather alert ticker (shared)

Included before `</body>` on every board. Renders nothing when no NWS alerts; otherwise 72px bottom overlay — amber for advisories, red + blinking dot for Severe/Extreme.

| Context | Behavior |
|---------|----------|
| **board.php** | Ticker in shell; framed pages use `?noticker=1` + safe-bottom inset |
| **player.php** | Ticker in outer PWA; polls JSON endpoint |
| **Direct board URL** | Each board renders its own ticker; scroll is clock-phased |

**Setup:** **Ticker** in admin — lat/lon, `TICKER_UA`, mode (`scroll` / `static`), min severity, demo mode.
