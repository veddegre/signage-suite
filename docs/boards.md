# Board reference

Every board is a **1920×1080** PHP page with shared styling. Configure all boards in **admin.php**; settings save to `config/settings.json`.

On operator-editable boards, super admins set **Access** per row: **owner**, **shared with users**, and **shared with roles** (e.g. all Operators). See [admin-and-security.md → Content ownership & sharing](admin-and-security.md#content-ownership--sharing).

## Quick index

| Group | Board | File | Rotation URL | Keys |
|-------|-------|------|--------------|------|
| Weather & home | Weather | `index.php` | `index.php` | OpenWeatherMap |
| | Lake Michigan | `lake.php` | `lake.php` | — |
| | Webcam | `webcam.php?cam=grpm` | `webcam.php?cam=KEY` | — |
| | Mackinac Bridge cam | `bridgecam.php` | `bridgecam.php` | — |
| | Photo conditions | `photo.php` | `photo.php` | OpenWeatherMap |
| | Air & pollen | `air.php` | `air.php` | Google Pollen (optional) |
| | UV index | `uv.php` | `uv.php` | — |
| | Sports | `sports.php` | `sports.php` | — |
| | Calendar | `calendar.php` | `calendar.php` | — |
| | Today at a glance | `glance.php` | `glance.php` | Calendar feeds |
| | Meal calendar | `meals.php` | `meals.php` | — |
| | Traffic map | `traffic.php` | `traffic.php` | TomTom |
| **Daily** | Word of the day | `wotd.php` | `wotd.php` | — |
| | This day in history | `history.php` | `history.php` | — |
| | Dad jokes | `joke.php` | `joke.php` | — |
| | Announcements | `announce.php?d=<key>` | `announce.php?d=<key>` | — |
| | XKCD comic | `xkcd.php` | `xkcd.php` | — |
| Monitoring | SignalTrace | `signaltrace.php` | `signaltrace.php` | Export token |
| | Cloud outages | `outages.php` | `outages.php` | Graph optional (M365) |
| | Internet infrastructure | `internet.php` | `internet.php` | `dig` for DNS roots |
| | Internet attacks | `attacks.php` | `attacks.php` | — |
| | DShield heatmap | `dshieldmap.php` | `dshieldmap.php` | — |
| | Attack origins | `dshieldsrc.php` | `dshieldsrc.php` | — |
| | Top attack ports | `attackports.php` | `attackports.php` | — |
| | Outage map | `iodamap.php` | `iodamap.php` | — |
| | Cloudflare Radar | `radar.php` | `radar.php` | Radar API token |
| | Attack map (L7) | `attackmap.php` | `attackmap.php` | Radar API token (shared) |
| | L3 attack map | `l3map.php` | `l3map.php` | Radar API token (shared) |
| | Data breaches | `hibp.php` | `hibp.php` | — |
| | New CVEs | `cve.php` | `cve.php` | NVD key optional |
| | CISA KEV | `kev.php` | `kev.php` | — |
| | TLS cert expiry | `certexp.php` | `certexp.php` | Host list |
| | Ransomware tracker | `ransomware.php` | `ransomware.php` | — |
| | Phishing & brand threats | `phish.php` | `phish.php` | URLhaus Auth-Key |
| | Homelab ops | `homelab.php` | `homelab.php` | Proxmox, AdGuard |
| | UniFi network | `unifi.php` | `unifi.php` | Local admin (UDM) or API key |
| | Uptime Kuma | `kuma.php?d=<key>` | `kuma.php?d=<key>` | Status page slug per page and/or API key |
| | Tailscale | `tailscale.php` | `tailscale.php` | Tailscale API key |
| | ntfy alerts | `ntfy.php` | `ntfy.php` | Webhook token and/or poll topic |
| | Zabbix | `zabbix.php` | `zabbix.php?d=<key>` | API token |
| Media | Photo rotator | `rotator.php` | `rotator.php` | — |
| | Custom slides | `slides.php` | `slides.php?slide=…` | — |
| | Video | `video.php` | `video.php?v=<key>` | — |
| | RSS | `rss.php` | `rss.php?feed=<key>` | — |
| Dashboards | Grafana | `grafana.php` | `grafana.php?d=<key>` | JWT secret (SSO) or — |
| | Splunk panels | `splunk.php` | `splunk.php?d=<key>` | Splunk token |
| | Splunk published | `splunkdash.php` | `splunkdash.php?d=<key>` | — (iframe) |
| | Power BI | `powerbi.php` | `powerbi.php?d=<key>` | Azure app (private) or — (publish) |
| | Websites | `web.php` | `web.php?d=<key>` | — (iframe) |

---

## Weather & home

### index.php — Weather

Allendale weather, RainViewer animated radar, sunrise arc. **Latitude / longitude** here are the site default for every location-aware board (weather, air, UV, photo, traffic, **NWS alert ticker**) unless a display overrides them under **Rotation → Kiosk settings**.

**Setup:** set `OWM_API_KEY` in admin → **Weather**.

### lake.php — Lake Michigan Conditions

**Data:** NDBC buoy **45029** (Holland nearshore — wave height, closer to Grand Haven) + NWS active alerts + computed sun times. No API keys.

**Setup:** change `NWS_UA` to a real contact email (NWS requires this). Optionally change `NDBC_STATION` (Muskegon **45161** often reports met data without `WVHT`).

Nearshore buoys run in winter (~Nov–Apr); the board notes when they are offline and keeps NWS alerts live. Swim risk uses a wave-height heuristic, escalated to HIGH when a Beach Hazards Statement or Rip Current alert is active.

**Rotation:** if the buoy has reported no fresh data for **24 hours**, `lake.php` is automatically skipped in rotation until readings return (saved playlist unchanged).

### webcam.php — Live Webcams

Full-screen live feeds — **one camera per rotation slot**, same pattern as `zabbix.php?d=` or `splunk.php?d=`.

**Built-in cameras:**

| Key | Source |
|-----|--------|
| `grpm` | [Grand Rapids Public Museum](https://www.wmta.org/live-west-michigan-camera-gallery/grand-rapids-public-museum-west-michigan-live-camera/) live stream (WMTA / WetMet iframe) |
| `grandhaven` | [Grand Haven beach](https://surfgrandhaven.com) EarthCam embed (iframe) |

**Setup:** admin → **Webcam** → **Cameras** — override built-in feeds or add rows with a unique **Key**. Each camera appears in **Rotation → Quick add** as its own entry (e.g. **Webcam — GR Public Museum**).

**Rotation:** add the cameras you want as separate playlist lines, intermixed wherever you like:

```
webcam.php?cam=grpm
webcam.php?cam=grandhaven
```

Custom cameras: `webcam.php?cam=yourkey`. Set **Off** on a row to hide it from quick-add and disable a built-in feed.

Still-image cameras refresh every `IMAGE_REFRESH_SEC` (default 60s). Iframe streams use an hourly reload backstop (`RELOAD_SEC`).

If a camera URL fails probe checks for **24 hours**, that rotation entry is auto-skipped until the stream responds again.

### bridgecam.php — Mackinac Bridge Cam

Full-screen live stills from the [Mackinac Bridge Authority bridge cams](https://www.mackinacbridge.org/fares-traffic/bridge-cam/). Four views: Mackinaw City (north), St. Ignace dock, Bridge View Park, and administration building. Source images update about every 60 seconds.

**Setup:** admin → **Mackinac Bridge Cam** — pick one camera or rotate through all four. Default is the Mackinaw City north view. No API key.

**Rotation:** 90s+ dwell works well; the board refreshes the image every 60s on its own.

### photo.php — Photo Conditions

**Data:** PHP sun math (golden/blue hour), synodic moon phase (SVG), OpenWeatherMap sunset cloud cover (tonight + next 3 evenings), Open-Meteo air quality (PM2.5 + aerosol optical depth for smoke/haze tint), NWS active alerts (air quality / smoke / dust / fog), NOAA SWPC Kp + 24h forecast.

**Setup:** `OWM_API_KEY` (same as weather). Open-Meteo AQ, NWS, and SWPC need no key.

**Verdict inputs (not clouds alone):**
- Cloud cover near sunset (OWM 3-hour slots blended within ±3h of sunset)
- Smoke / haze tint from OWM weather types (smoke, haze, dust), Open-Meteo PM2.5 + AOD, reduced visibility, and NWS Air Quality / Smoke advisories
- Clear deck + smoke tint → **DRAMATIC SKY** (deep orange/red without needing broken clouds)
- Otherwise: ≤20% clouds = CLEAN LIGHT, 21–70% = DRAMATIC SKY, 71–85% = MARGINAL, else FLAT GRAY

Aurora panel highlights when Kp ≥ 6. The board shows both a **cloud cover** bar and a **color potential / smoke tint** bar (Clear → Broken → Heavy).

### air.php — Air & Pollen

US AQI, per-pollutant AQI (PM2.5, PM10, ozone), pollen bars, and a three-day **Open-Meteo model** outlook.

**Data**

| Signal | Source | Notes |
|--------|--------|--------|
| Current AQI + pollutant tiles | [EPA AirNow API](https://docs.airnowapi.org/) | Ground monitors — matches weather apps (recommended for US) |
| Fallback AQI | [Open-Meteo Air Quality API](https://open-meteo.com/en/docs/air-quality-api) | Free CAMS model; can lag wildfire smoke |
| Outlook (3-day) | Open-Meteo | Model forecast only — not EPA monitors |
| Alerts | NWS (`api.weather.gov`) | Badges + verdict; no API key — **5 min cache** by default |
| Pollen | Google Pollen API (optional) | Separate key from AirNow |

**Scoring:** overall AQI = highest pollutant sub-index (EPA method). With only Open-Meteo, NWS alert wording can raise the displayed category when monitors are unavailable.

**Cache TTL** (admin → Air & Pollen)

| Setting | Default | Applies to |
|---------|---------|------------|
| Cache TTL | 3600s (1 h) | AirNow, Google Pollen, Open-Meteo |
| NWS alert cache | 300s (5 min) | NWS active alerts only (free, no quota) |

Single-flight lock (`*.json.lock`) prevents duplicate upstream calls when a TTL expires. Lower cache TTL during heavy smoke for fresher AQI; NWS can be shortened further (min 60s) if you want faster alert badges.

**Setup — admin → Air & Pollen**

1. Place name, lat/lon, timezone.
2. **EPA AirNow API key** (AQI / PM2.5 / ozone):
   - The public map site is [airnow.gov](https://www.airnow.gov/) — the **API key is not there**.
   - Developer portal: [docs.airnowapi.org/login](https://docs.airnowapi.org/login) → **Request an AirNow API Account** ([registration form](https://docs.airnowapi.org/account/request/)).
   - Activate via the email confirmation link, then log in.
   - Open [Web Services](https://docs.airnowapi.org/webservices) — your **API key is in the upper-right corner** (also embedded in Query Tool example URLs).
   - Paste into admin → **EPA AirNow API key** → **Save**.
3. **Google Pollen API key** (optional, pollen only): enable Pollen API in Google Cloud; 5,000 calls/month free tier.

**Diagnose on the server**

```bash
php scripts/diagnose-air.php --root=/var/www/html/boards
```

Expect `air.AIRNOW_API_KEY: set` and `AirNow HTTP 200` with PM2.5 / PM10 / O3 lines. `air.AIRNOW_API_KEY` and `air.GOOGLE_POLLEN_API_KEY` are different settings.

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

### sports.php — Sports

ESPN team cards (up to four per display) plus **Next games** strip.

**Data:** ESPN public API — no key. Server-side cache (default 300s; scoreboards refresh sooner). Upcoming and live games show **local start time** and **where to watch** (national TV first, then home/away regional networks from ESPN’s schedule feed).

**Setup:** admin → **Sports** — title, subtitle, timezone. Per-display team picks under **Rotation → Kiosk settings**. ~75s dwell is a reasonable rotation default.

Season logic uses calendar windows plus nearby games. Live games show score + period; direct view auto-refreshes ~2 minutes while any team is live.

**Rotation:** when **every** configured team is off-season (and none are live), `sports.php` is auto-skipped until a team enters season or a game goes live.

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

RRULE support: DAILY, WEEKLY (BYDAY, INTERVAL, WKST — **biweekly** uses DST-safe week math), MONTHLY (BYMONTHDAY), YEARLY, with UNTIL/EXDATE.

### glance.php — Today at a glance

Compact **today + tomorrow** calendar (left), **weather summary** (top right), and up to two **headline panels** (below weather) on one board.

**Layout:** Clock, date, and calendar legend; today’s events (with optional tomorrow preview); OpenWeatherMap current conditions with hi/lo, **precip chance**, wind, and tomorrow outlook. Headline columns sit side-by-side when both are enabled.

**Admin:** **Today at a Glance** — site-wide defaults for title, event count, tomorrow preview, weather on/off, headline column titles, GVNext URL, RSS keys, max headlines, cache TTL. Per-display overrides: **Rotation → Kiosk settings** (same pattern as sports teams and ticker RSS). Calendar data comes from **Calendar** board feeds (`calendar.ICS_FEEDS`).

**Headlines (left, site default):** [GVNext](https://www.gvsu.edu/gvnext/) — scrapes `preview-title` story titles; autodiscovers RSS when the page links a feed. Per display: override page URL, column title, or RSS fallback (used when scrape returns nothing).

**Headlines (right, site default):** RSS feed key from **RSS Stories**. Per display: override feed key or hide the column.

**Rotation:** `board.php` appends `?screen=<key>` so location, headlines, and calendar scope match the kiosk. Built-in **Weekly planner** and default **starter playlist** include `glance.php`.

### meals.php — Meal calendar

Rolling **7-day meal plan** — today highlighted large on the left, the next six days in a grid. Edit in admin; no external calendar required.

**Admin:** **Meal calendar** board:

- **Weekly plan** — one row per weekday (Mon–Sun): dinner + optional note; lunch/breakfast columns optional
- **Date overrides** — `YYYY-MM-DD` rows replace the weekly default (holiday, takeout, leftover night)
- **Show lunch / breakfast** — off by default; enable to show extra slots on the wall

Pairs with the **Dinner menu** slide template for a hero “tonight” graphic and the built-in **Kitchen weeknight** rotation preset.

---

## Monitoring

### outages.php — Cloud Outages

Six-card grid for public cloud/SaaS status: **AWS**, **Azure**, **GitHub**, **Cloudflare**, **Microsoft 365**, and **Google Workspace**. Each card shows overall health, a summary line, and up to three active incidents.

**Data:** Public status APIs — no keys except Microsoft 365:

| Provider | Source |
|----------|--------|
| AWS | `status.aws.amazon.com/data.json` |
| Azure | Azure Status RSS |
| GitHub | [githubstatus.com](https://www.githubstatus.com) Statuspage API |
| Cloudflare | [cloudflarestatus.com](https://www.cloudflarestatus.com) Statuspage API |
| Google Workspace | [Google Workspace Status Dashboard](https://www.google.com/appsstatus/dashboard/) JSON |
| Microsoft 365 | Public backup feed (`status.office.com`) — optional Graph for tenant health |

**Setup:** admin → **Cloud Outages** — toggle providers, **US incidents only** (default on), cache TTL (default 120s). **Microsoft 365** works without credentials via Microsoft's public backup status API — it only posts during widespread incidents (when the admin center status page itself is affected). For day-to-day Exchange/Teams/SharePoint health per your tenant, optionally register an Entra app with **ServiceHealth.Read.All** and paste tenant ID, client ID, and secret. Other providers work out of the box.

**US filtering:** When enabled, AWS events are limited to `us-*` regions, Cloudflare to US POPs, Google Workspace to US-affected locations, and Azure RSS posts mentioning US regions. GitHub publishes global service status only — that card stays global and is labeled accordingly. M365 public feed is global; Graph uses your tenant view.

**Rotation:** 60s dwell is enough; the board auto-refreshes feeds on its own cache schedule.

### internet.php — Internet Infrastructure (BGP & DNS roots)

Two-panel board for core Internet plumbing: **routing/ASN outages** from IODA and a live **DNS root server** reachability grid (letters A–M).

**Data:**

| Panel | Source |
|-------|--------|
| BGP / ASN outages | [IODA API v2](https://api.ioda.inetintel.cc.gatech.edu/v2/) — free, no API key |
| DNS root servers | CHAOS TXT probe (`hostname.bind`) to each `*.root-servers.net` via `dig` |

IODA combines BGP prefix visibility, active probing, and other signals. The board prioritizes BGP alerts and ASN-level correlated events; when BGP-specific alerts are sparse it still shows recent ASN outage events with the signal source labeled (BGP, active probe, etc.).

**DNS roots:** Each root letter is probed from your signage server. A failed probe highlights that letter in red — useful for spotting reachability issues from your network’s perspective (same idea as RIPE Atlas root monitoring, but local). Requires the `dig` command (`dnsutils` on Debian/Ubuntu).

**Setup:** admin → **Internet Infrastructure** — toggle BGP and DNS panels, IODA lookback (default 7 days), optional **US scope** for IODA, cache TTLs (IODA default 300s, DNS probes default 180s).

**Rotation:** 60s dwell; IODA and DNS probes refresh on their own cache schedules.

### attacks.php — Internet Attacks (DShield)

Scanning and brute-force visibility from the SANS ISC DShield sensor network: **areas under attack** (country targets), **top ports**, **top attacking IPs**, and the global **Infocon** threat level.

**Data:** [SANS ISC DShield API](https://isc.sans.edu/api/) — free, no API key (`country`, `topips`, `topports`, `infocon`).

**Setup:** admin → **Internet Attacks** — country/port/IP counts, optional US highlight. Works out of the box.

**Rotation:** 60s dwell; feeds refresh on cache TTL (default 300s).

### dshieldmap.php — DShield Heatmap

Full-screen **world heatmap** of SANS ISC DShield **attack targets by country** — glowing blobs sized and colored by how many distinct hosts in each country are being targeted. Sidebar lists the hottest countries; Infocon level in the header.

**Data:** `GET /country` on [SANS ISC DShield API](https://isc.sans.edu/api/) — same free feed as `attacks.php` (no API key).

**Setup:** admin → **DShield Heatmap** — minimum target threshold, sidebar count. Shares cache with **Internet Attacks**.

**Rotation:** 60s dwell; page reload refreshes from cache TTL (default 300s).

### dshieldsrc.php — Attack Origins (DShield)

Full-screen **world heatmap** of DShield **attack sources by country** — where scanning traffic appears to originate. Cyan palette (inverse of the targets heatmap).

**Data:** Same `GET /country` feed as `attacks.php` — uses the `sources` field (no API key).

**Setup:** admin → **Attack Origins** — minimum source threshold, sidebar count.

### attackports.php — Top Attack Ports

Full-screen **treemap** of DShield **top targeted ports** — block size by record volume; colors by service (SSH, RDP, HTTP, etc.).

**Data:** `GET /topports/records` on isc.sans.edu (no API key).

**Setup:** admin → **Top Attack Ports** — port count in treemap.

### iodamap.php — Outage Map (IODA)

Full-screen **world map** of **country-level internet outages** from Georgia Tech IODA — severity blobs with live pulse for ongoing disruptions.

**Data:** IODA API v2 `/outages/events` and `/outages/alerts` (no API key).

**Setup:** admin → **Outage Map** — lookback days, minimum score threshold.

### l3map.php — L3 Attack Map (pew-pew)

Same animated arc map as `attackmap.php` but for **Cloudflare Radar L3 volumetric DDoS** origin→target pairs. Purple/cyan arc styling.

**Data:** `GET /radar/attacks/layer3/top/attacks` — same Radar token as `radar.php`.

**Setup:** admin → **L3 Attack Map** — arc count, travel speed, time window.

### radar.php — Cloudflare Radar (DDoS geography)

Separate board for **L3 DDoS targets**, **L3 attack origins**, and **L7 attack targets** — share of attacks by country across Cloudflare's network.

**Data:** [Cloudflare Radar API](https://developers.cloudflare.com/radar/) — free account, API token with **Account → Radar** permission.

**Setup:** admin → **Cloudflare Radar** — paste API token, pick time window (default 24h), toggle L3/L7 panels.

**Rotation:** 60s dwell; add as its own playlist row alongside **Internet Attacks** for a separate screen.

### attackmap.php — Attack Map (pew-pew)

Full-screen **animated world map** of Cloudflare Radar **L7 attack flows** — curved arcs from origin country to target country, with pulsing endpoints and a live flow list.

**Data:** `GET /radar/attacks/layer7/top/attacks` — same Cloudflare Radar token as `radar.php`.

**Setup:** admin → **Attack Map** — optional token (inherits from **Cloudflare Radar** if blank), arc count (default 18), travel speed, time window.

**Rotation:** 75s dwell recommended so arcs have time to play; page reload refreshes pair data from cache TTL (default 300s).

### hibp.php — Data Breaches (Have I Been Pwned)

Latest breach hero plus a list of recently added breaches — title, domain, account count, exposed data types, and description.

**Data:** [Have I Been Pwned API v3](https://haveibeenpwned.com/API/v3) `GET /breaches` — free, no API key (User-Agent required).

**Setup:** admin → **Data Breaches** — title, User-Agent, breach count (default 8), cache TTL (default 1 hour).

### cve.php — New CVEs (NIST NVD)

Latest published CVE hero plus a list of recent vulnerabilities — ID, CVSS score, severity, and description.

**Data:** [NIST NVD API 2.0](https://nvd.nist.gov/developers/vulnerabilities) — free without a key (5 requests / 30s); optional API key for higher limits.

**Setup:** admin → **New CVEs** — lookback window (default 7 days), CVE count (default 8), cache TTL (default 1 hour).

### kev.php — CISA Known Exploited Vulnerabilities

Federal **KEV catalog** — hero CVE with remediation due date, vendor/product, ransomware-use flag, and required action text.

**Data:** [CISA KEV JSON feed](https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json) — free, no API key (default cache 1 hour).

**Setup:** admin → **CISA KEV** — due-soon window (default 14 days), optional **Watch vendors** filter, hide ransomware-linked entries if desired. Add `kev.php` to rotation (~60s dwell).

### certexp.php — TLS Certificate Expiry

Direct **TLS probes** of configured HTTPS hosts — surfaces expired, failing, and soon-to-expire certificates.

**Data:** OpenSSL handshake from the signage server to each configured host/port (default cache 1 hour per host).

**Setup:** admin → **TLS Cert Expiry** — add **Hosts** rows (`host`, optional `label`, `port`). Set warn window (default 30 days). LAN hosts must be reachable from PHP on the server.

### ransomware.php — Ransomware Tracker (Ransomware.live)

Recent **extortion-site victim claims** — hero victim, group, sector, country, infostealer counts when available, plus sector and group rollups.

**Data:** [Ransomware.live API v2](https://api.ransomware.live/apidocs) `GET /v2/recentvictims` — free, no API key (1 request/min per endpoint; default cache 30 min).

**Important:** Claims are **unverified** shaming posts — not confirmed breaches unless Ransomware.live links press corroboration. The board never links to `.onion` URLs.

**Setup:** admin → **Ransomware Tracker** — lookback (default 7 days), highlight country (default US), optional watch sectors/groups (comma-separated), cache TTL.

**Rotation:** 60s dwell; strategic awareness alongside CVE/HIBP boards.

### phish.php — Phishing & Brand Threats

Two panels: **Brand watch** (Certificate Transparency lookalikes for your root domains via crt.sh) and **URLhaus recent** (malware/phishing URLs from abuse.ch). Hosts are **defanged** on the wall — not clickable.

**Data:**

| Panel | Source | Auth |
|-------|--------|------|
| URLhaus recent | `urlhaus-api.abuse.ch/v1/urls/recent/` | [abuse.ch Auth-Key](https://auth.abuse.ch/) (free) |
| Brand watch | `crt.sh` JSON query per root domain | None (slow — 24h cache per domain) |

**Setup:** admin → **Phishing & Brand Threats** — paste **URLhaus Auth-Key**, optional tag filters (`emotet`, `qakbot`, …), add **Brand watch** rows (root domain + keywords). Poll URLhaus no more often than every 5 minutes (default cache 900s).

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

### unifi.php — UniFi Network

**Data:** Adopted gateways, switches, and APs; online/offline state; client counts (Wi‑Fi, wired, guest); WAN/WLAN/LAN health; **WAN download/upload**; **top talkers**; **last speed test** when the controller has results; pending adoptions.

**Dream Machine / UDM (typical — no API key needed):**

1. **Controller URL** — `https://<gateway-ip>` (the UDM’s LAN IP, port 443). Do not add `/network`.
2. **Local admin** — Settings → **Admins** → add or use a **local** account with **local access** (not a ui.com-only cloud account; MFA off for API use).
3. Admin → **Monitoring → UniFi Network:** `UNIFI_URL`, `UNIFI_USERNAME`, `UNIFI_PASSWORD`, `UNIFI_SITE` = `default`
4. **Security → Allow private URL fetches** on the signage server
5. **Verify TLS** off for the UDM’s self-signed certificate

The Integrations / API key UI appears only on newer **Network 9.3+** firmware. If you don’t see it, username/password is the correct path.

**Optional — Integration API (newer firmware):** Settings → **Integrations** (sidebar) or Control Plane → Integrations → API key. Use instead of username/password when available.

**Rotation:** `unifi.php` — quick-add under **UniFi network** in the rotation editor.

### kuma.php — Uptime Kuma

**Data:** Monitor up/down grid, ping, 24h uptime (when available), summary counts, and a **Down now** panel.

**Multiple pages:** `kuma.php?d=<key>` — same pattern as Zabbix and Splunk. Each page has its own status page slug, optional tag filter, title, and sharing.

**Setup:**

1. **Uptime Kuma URL** — base URL, e.g. `http://192.168.x.x:3001` (board settings)
2. **Per page — status page slug** — from **Status Pages** in Kuma (works without an API key; uses `/api/status-page/heartbeat/{slug}`)
3. **Or board API key** — **Settings → API Keys** in Uptime Kuma (`Authorization: Bearer …`). Lists all monitors; optional **tag filter** per page (comma-separated)
4. **Security → Allow private URL fetches** when Kuma is on your LAN

Legacy single-slug configs (`KUMA_STATUS_SLUG` / `KUMA_TAGS` at board level) are migrated automatically to a `main` page until you save new pages in admin.

**Rotation:** `kuma.php?d=<key>` — quick-add lists each configured page under **Uptime Kuma** in the rotation editor.

### announce.php — Announcements & countdown

**Data:** Full-screen title, message body, or live countdown to a date/time.

**Multiple items:** `announce.php?d=<key>` — one rotation row per admin entry (unless **Strip only**).

| Setting | Purpose |
|---------|---------|
| Mode | **announcement** or **countdown** |
| Countdown until | `YYYY-MM-DD` or `YYYY-MM-DD HH:MM` |
| Show from / Hide after | Optional schedule window |
| Strip only | Hero strip only — omitted from rotation quick-add; add via **Rotation → hero strip** |
| Off wall | Skip in rotation |

**Hero strip:** pick the item key under **Rotation → Display options → Strip sources**, or choose **All active strip-only items** to rotate every active strip-only row in one bar.

**Rotation:** quick-add under **Daily** when not strip-only.

### emergency.php — Emergency override (inline)

**Data:** Full-screen title and body from **Rotation → Emergency override** when **Full-screen announcement** mode is active and no existing `announce.php?d=` item is selected.

Not a normal content board — activated site-wide by a super admin. Also usable as a URL in an **emergency playlist**. See [rotation-and-deployment.md → Emergency override](rotation-and-deployment.md#emergency-override-super-admin).

**URL:** `emergency.php` (no `?d=` key).

### tailscale.php — Tailscale mesh

**Data:** Online/offline devices, IPs, OS, last seen — from the Tailscale API.

**Setup:**

1. **Tailnet name** — your tailnet slug (admin → **Monitoring → Tailscale**)
2. **API key** — [Tailscale admin → Keys](https://login.tailscale.com/admin/settings/keys) with **Devices read**
3. **Security → Allow private URL fetches** not required (cloud API)

**Rotation:** `tailscale.php` — quick-add when URL and key are configured.

### ntfy.php — ntfy alerts

**Data:** Recent alert titles from a local cache — shown on the full board or in the hero strip.

**Ingest (pick one or both):**

1. **Webhook** — set **Webhook token**, POST JSON to `ntfy_webhook.php` with header `X-Signage-Token: <token>` (or `?token=`). Optional `?topic=` tags the message.
2. **Poll topic** — set **Poll topic** and **ntfy server** (default `https://ntfy.sh`). Signage polls `{server}/{topic}/json` on an interval (default 30s) and merges into the same cache.

**Rotation:** `ntfy.php` — or add **ntfy alerts** as a hero strip source on a display.

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
| Access | Owner; shared with users and/or roles (e.g. Operators) |

**Wall layout:** severity summary pills, active problem list (host, age, acknowledged), host grid (green = OK, red = problem, grey = disabled). Cache **`CACHE_TTL`** default 60s. Quick-add under **Monitoring** in Rotation.

---

## Media

### rotator.php — Photo Rotator

**Upload:** admin → **Photo Rotator** — JPG/PNG up to 25 MB. Deploy sync status on **Status**.

Photos in `./photos/` by default; served via `?img=` (not direct HTTP). EXIF captions, crossfade, configurable brand wordmark. Reloads every 6h for new files.

Per-photo **Access** (owner, users, roles) like slides. Deploy targets respect operator display assignment.

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

**Time windows** (optional, any schedule mode):

| Field | Behavior |
|-------|----------|
| **Time windows** | One or more ranges — whole hours (`7`) or minutes (`7:30`). End time is **exclusive** (matches rotation). Overnight `22→6` works. Leave blank for all day. |
| **Active days (time windows)** | Optional weekday checkboxes — separate from the **weekly** date schedule above. Limits when the time windows apply. |

Legacy **`hour_from` / `hour_to`** (0–23, inclusive end) still works on saved rows until you migrate to time windows. Board reloads every 5 minutes for schedule boundaries.

**Calendar slide sets:** under **Rotation → Calendar overrides**, choose **Slide set only** to limit which deck files play during a matching ICS event while the rest of the rotation playlist continues.

**Access:** super admins set owner, individual users, and roles on each slide card (**Access** popover in the deck, or bulk **Share with** / **All operators** in the deck toolbar). Operators see slides they own, that are shared with them, or that are shared with the **Operators** role.

### rss.php — RSS Story Board

Keyed feeds: `rss.php?feed=krebs`, etc. Per-feed story count and dwell; defaults 8 stories / 12s.

RSS 2.0 and Atom. Images from media/enclosure/itunes/body. Progress dots show cycle position.

**Image fit** — global default under **RSS Stories**, or per feed:

| Mode | Behavior |
|------|----------|
| **auto** (default) | Landscape images fill the screen; portrait images show full height on the right with a blurred backdrop |
| **cover** | Always crop to fill 1920×1080 |
| **contain** | Always show the full image (letterboxed) |

Useful for poster-style feeds (e.g. portrait artwork).

**Requires:** `php-xml`, `php-mbstring`.

### video.php — Video Board

Videos downloaded locally with **yt-dlp** — no live YouTube embed (no ads, no embed blocks on headless servers).

**Registry:** `youtube` URL or local `file` per entry → `video.php?v=<key>`. Per-entry **Access** (owner, users, roles).

Muted by default; uncheck **Mute all videos** in admin if needed. Refresh via admin UI or `php video.php fetch`.

See [video-youtube.md](video-youtube.md) for bot checks, cookies, and cron.

---

## Dashboards & integrations

### grafana.php — Grafana (iframe + JWT)

`grafana.php?d=<key>`. Kiosk mode, theme, and refresh params are appended automatically. Per-dashboard **Access** (owner, users, roles) like other operator boards.

| Auth path | When |
|-----------|------|
| **JWT embed** | Self-hosted Grafana behind SSO — signage signs `auth_token` (HS256) |
| **Public dashboard URL** | Non-sensitive; Grafana “public dashboard” share link |
| **Anonymous Viewer** | Homelab LAN (`auth.anonymous` in grafana.ini) |

**Full JWT setup (work Grafana + SSO):** [grafana.md](grafana.md) — `grafana.ini` `[auth.jwt]`, JWK file, dedicated Viewer user, signage admin fields, troubleshooting.

**Diagnostics:** `php scripts/diagnose-grafana.php` · `php scripts/diagnose-grafana.php --test` · admin **Test JWT signing**

### splunk.php — Splunk panels (REST API)

Oneshot searches server-side — no Splunk Web iframe.

**Setup:** low-privilege Splunk user, token under Settings → Tokens. `SPLUNK_BASE` = management port **8089** (not Splunk Web), `SPLUNK_TOKEN`.

**Panel types:** `single` (big number), `list` (label + bar + count), `trend` (timechart). Optional `earliest`/`latest`, `unit`, `wide` (2 columns).

Multi-page: `splunk.php?d=<key>` with per-page panel decks and **Access** (owner, users, roles). Cache default 120s per search.

### splunkdash.php — Splunk published (iframe)

Splunk Enterprise 10.x / Cloud published Dashboard Studio dashboards.

**Setup:** publish in Splunk, copy URL to registry. Set `x_frame_options_sameorigin = false` in Splunk `web.conf` for LAN embeds. Wrapper reloads iframe on `reload` interval (default 300s).

### powerbi.php — Power BI (iframe + embed tokens)

`powerbi.php?d=<key>`. Supports **publish-to-web** (public iframe) and **private embed tokens** via Azure AD service principal — the same pattern as Yodeck and other commercial signage tools.

| Mode | Use case | Setup |
|------|----------|--------|
| **Publish** | Public reports | `app.powerbi.com/view?r=…` link (no sign-in; data is public) |
| **Token** | Private reports on kiosk players | Azure AD app + workspace/report IDs |

**Full setup guide:** [powerbi.md](powerbi.md) — detailed Entra app registration, API permissions, Power BI admin portal settings, workspace access, row configuration, RLS, rotation, and troubleshooting.

**Signage admin (summary):**

1. Admin → **Power BI** — **Azure tenant ID**, **client ID**, **client secret** (see [Azure setup](powerbi.md#azure-setup-one-time)).
2. **Test Azure + Power BI API** — confirms credentials before adding reports.
3. Per row: **Mode** `token` or `auto`, **URL** (embed link) or **Workspace ID** + **Report ID**, optional **RLS** fields.
4. Rotation: `powerbi.php?d=<key>` or quick-add under **Dashboards**.

**Diagnostics:** `php scripts/diagnose-powerbi.php` · `php scripts/diagnose-powerbi.php --test` · admin **Test Azure + Power BI API**

### web.php — Websites (iframe)

Keyed sites: `web.php?d=<key>`. Target URLs must allow iframe embedding. Per-site **Access** (owner, users, roles).

---

## ticker.php — Weather alert ticker (shared)

Included before `</body>` on every board. Renders nothing when no NWS alerts (unless **RSS news fallback** is configured for that display); otherwise 72px bottom overlay — amber for advisories, red + blinking dot for warnings.

| Context | Behavior |
|---------|----------|
| **board.php** | Ticker in shell; framed pages use `?noticker=1` + safe-bottom inset |
| **player.php** | Ticker in outer PWA; polls `ticker.php?api=1&screen=<key>` |
| **Direct board URL** | Each board renders its own ticker; scroll is clock-phased |

**Location:** NWS `alerts/active?point=lat,lon` uses the same coordinates as the **Weather** board — global lat/lon under **Weather** in admin, or per-display override under **Rotation → Kiosk settings**. There is no separate latitude/longitude on the **Alert Ticker** board anymore.

**Setup:** **Alert Ticker** in admin — master enable, `TICKER_UA`, poll interval, mode (`scroll` / `static`), min severity, demo mode. Per display: **Show weather alert ticker** and optional **RSS headlines when there are no alerts** under **Rotation → Kiosk settings**.

**Alert text:** Headline, timing, and key hazard language — county **Areas:** lists are omitted (the alert is already for your configured point).
