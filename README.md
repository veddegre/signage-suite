# Home Signage Boards

Seventeen self-contained PHP pages, all 1920×1080, all sharing the same dark-navy/amber design language. Drop them in one web directory with PHP 8+ and php-curl. Each page caches API responses in `./cache/` (created automatically — make sure the web user can write there) and falls back to stale cache on API failure, so a flaky API never blanks the wall.

## Quick start (Ubuntu / Debian / Raspberry Pi OS)

**Server** — run once on the box that hosts the boards:

    sudo bash setup-server.sh --with-video-cron

**Display** — run once on each Pi or kiosk PC (after the server is up):

    sudo bash setup-kiosk.sh "http://your-server/boards/board.php" [scale]

Then open **admin.php** in a browser, create your super admin account, and configure boards. Optional: enable SSO (Entra ID or Authentik) under **Security**. See [Admin access](#admin-access), [SSO setup](#sso-setup-entra-id--authentik), [Server setup](#setup-serversh--the-web-host), and [Kiosk setup](#setup-kiosksh--the-display-device).

## Server requirements (manual install)

If you prefer not to use `setup-server.sh`, you need PHP 8.1+ with curl, xml, mbstring, gd, and zip — on Ubuntu 24.04+:

    sudo apt install apache2 libapache2-mod-php php-curl php-xml php-mbstring php-gd php-zip ffmpeg
    sudo mkdir -p /var/www/html/boards/{config,cache,videos,slides,photos}
    sudo chown -R www-data:www-data /var/www/html/boards/config /var/www/html/boards/cache /var/www/html/boards/videos /var/www/html/boards/slides /var/www/html/boards/photos

(`php-gd` powers the slide creator backgrounds. `php-zip` lets admin install deno updates on the Video Board. `ffmpeg` is for video.php duration readouts; install `yt-dlp` via `pipx` rather than apt — the repo version goes stale and YouTube breaks it.)

**Important on Ubuntu's default Apache:** `/var/www` ships with `AllowOverride None`, which silently ignores the protective `.htaccess` files in `config/`, `cache/`, `slides/`, and `photos/` — leaving your API tokens in `settings.json` (and uploaded images) downloadable. Add this to your site config (e.g. `/etc/apache2/conf-available/signage.conf`, then `a2enconf signage && systemctl reload apache2`):

    <DirectoryMatch "/var/www/html/boards/(config|cache|slides|photos)/">
        Require all denied
    </DirectoryMatch>

(Adjust the path to wherever the boards live. On nginx: `location ^~ /boards/(config|cache|slides|photos)/ { deny all; }`.) Verify after deploying: `curl -I http://server/boards/config/settings.json` should return 403.

## Configuration: admin.php (start here)

All settings are managed through **admin.php** — a web frontend covering every board. Settings save to `config/settings.json`; **the board PHP files are never modified**, so the web server only needs write access to `config/`, `cache/`, `videos/`, `slides/`, and `photos/`.

How it works: each board's old `const` values are built-in defaults. A blank field in the admin means "use the default"; anything you enter overrides it. Password and API-key fields are left blank on save to mean "unchanged" — the admin never echoes secrets back into the HTML. The **Tools** page (super admin only) can clear the API cache and shows the raw JSON, which you can also edit by hand.

### Admin access

Accounts live in `config/users.json` (not in the web-readable tree — blocked like `settings.json`). On first visit you create a **super admin** using a one-time key from `config/setup.key` on the server (SSH in to read it — prevents a stranger from claiming admin on a public host).

| Role | Access |
|------|--------|
| **Super admin** | All boards, **Users**, **Tools**, **Security**, every display |
| **Operator** | **Slides**, **Photo Rotator**, **RSS**, **Websites**, **Video**, **Grafana**, **Splunk**, **Calendar**, and **Rotation** for their assigned display only; **Account** and **Status** |

**Navigation:** sidebar groups boards (Setup, Weather & home, Monitoring, Media, Dashboards). **Users** and **Tools** are super-admin only. **Status**, **Account**, and logout are in the sidebar footer.

- **Account** — change your local password (hidden for SSO-linked accounts).
- **Users** — create local or SSO users, assign roles, assign exactly **one display** per operator (radio picker). Displays assigned to an operator cannot be deleted from **Rotation** until unassigned here.
- **Status** — live sign health (kiosk heartbeats), recent play log, and photo/slides sync-to-rotation panels. Deploy actions stay on each media board; Status is read-mostly monitoring.
- **Security** — idle timeout, outbound URL policy, [SSO](#sso-setup-entra-id--authentik), and audit settings.
- **Audit** (super admin) — sign-ins, failed logins, board saves, user changes, cache clears. Not wiped when clearing API cache.

**Login:** local username/password, optional **Sign in with …** when SSO is enabled, session cookies with CSRF protection, idle auto-logout, and lockout after repeated failures.

### Content ownership & sharing

Playlist rows on operator boards (**Slides**, **Photo Rotator**, **RSS**, **Websites**, **Video**, **Grafana**, **Splunk**, **Splunk Published**, **Calendar**, etc.) can have an **owner** and **shared with** list (super admins see an **Access** control on each row). Weather, monitoring, and setup boards stay super-admin only. Operators only see and edit entries they own or that are shared with them; new entries they create are owned by them automatically. Board-level API secrets (Splunk token, TomTom key, etc.) remain super-admin only.

### Concurrent saves & JSON storage

Settings, accounts, audit history, and kiosk presence are stored as JSON on disk (`config/settings.json`, `config/users.json`, `cache/admin_audit.json`, `cache/presence.json`). That keeps the stack simple — no database to install — and is a good fit for teams on the order of dozens of users.

**Simultaneous admin saves** are handled with file locking across the full read → merge → write cycle. If two people save different boards at the same time, the second request waits briefly, reads the latest file, and applies its changes on top — so one save no longer silently overwrites the other. The same protection applies to deploy-to-rotation actions (slides, photos, video, RSS), SSO account linking, JIT provisioning, and kiosk heartbeats. If a lock cannot be acquired in time, admin shows *Another admin save is in progress — wait a moment and try again.*

**Users page caveat:** that screen posts the entire user table on each save. Two super admins editing **Users** at the same time still means whoever saves last wins the whole table (not a corrupt file — just the usual full-form replace). Coordinate user changes, or save one at a time.

Sidecar `*.lock` files next to the JSON files are normal; they are used only while a write is in progress.

### SSO setup (Entra ID & Authentik)

Admin login supports **OpenID Connect** — one implementation works for **Microsoft Entra ID**, **Authentik**, and any standard OIDC provider.

**Prerequisites:** PHP **curl** extension (already required for boards). HTTPS in front of admin is strongly recommended for production SSO.

#### 1. Configure the identity provider

Register a **Web** OAuth2/OIDC application and note the **client ID**, **client secret**, and **issuer URL**.

| Provider | Issuer URL | Redirect URI |
|----------|------------|--------------|
| **Entra ID** | `https://login.microsoftonline.com/<tenant-id>/v2.0` | Copy from admin after step 2 |
| **Authentik** | Shown on the OAuth2/OpenID provider (often `https://auth.example.com/application/o/<slug>/` — **trailing slash must match exactly**) | Same |

In Entra: App registration → **Authentication** → add redirect URI as **Web**. Create a client secret under **Certificates & secrets**.

In Authentik: **Applications** → Provider (OAuth2/OpenID) + Application → add the redirect URI to the provider.

#### 2. Enable SSO in Signage

1. Log in as super admin → **Security**.
2. Check **Enable SSO login**.
3. Set **SSO provider** to `entra` or `authentik` (preset hints only — same OIDC flow).
4. Fill **OIDC issuer URL**, **OIDC client ID**, **OIDC client secret**.
5. Leave **OIDC scopes** at default `openid profile email` unless your IdP requires more.
6. **Save**. The page shows your **Redirect URI** — paste it into Entra/Authentik if you have not already.

Useful options:

- **Username claim** — JWT field matched to admin username (default `preferred_username`; falls back to `email` / Entra `upn`).
- **Auto-link by email** — on first SSO sign-in, match an existing user when the email local-part equals their username (e.g. `jane` ↔ `jane@contoso.com`).
- **Allow local password login** — keep the username/password form when SSO is on (default: yes).
- **SSO just-in-time provisioning** — auto-create **operator** accounts on first SSO sign-in (never super). Optional **allowed email domains** and **required groups/roles** in the token gate who gets provisioned.
- **Enable audit log** — record admin actions (default on). View under **Audit** in the sidebar.

#### 3. Create SSO users (or enable JIT)

**Manual (default):** SSO does **not** auto-provision unless JIT is enabled. A super admin must create each user first:

1. **Users** → **+ Add user** (or edit existing).
2. Set **Auth** to **SSO**.
3. **Username** must match what the IdP sends (usually the email local-part).
4. Set role and display assignment → **Save users**.

On first successful SSO sign-in the account **links** (status shows "Linked"); until then it shows "Pending first sign-in". Linked accounts authenticate only via SSO — no local password.

**JIT provisioning:** enable **Security → SSO just-in-time provisioning**. First sign-in from Entra/Authentik creates an **operator** with no display assigned — a super admin assigns a screen under **Users**. Recommended for larger teams:

1. Set **JIT allowed email domains** (e.g. `yourcompany.com`).
2. Optionally set **JIT required groups/roles** — user must have any listed value in the token `groups` or `roles` claim (Entra: assign app roles or enable groups in token configuration; Authentik: groups appear in userinfo).
3. New JIT users appear in **Users** as SSO operators; assign displays as usual.

#### Troubleshooting SSO

- **Could not load OpenID discovery** — wrong issuer URL or server cannot reach the IdP. Authentik on a private LAN still works (SSO calls are allowed to the configured issuer host even when **Allow private URL fetches** is off).
- **No matching admin account** — create the user under **Users**, enable JIT, or check domain/group filters.
- **ID token issuer mismatch** — Entra tenant URL or Authentik issuer trailing slash does not match exactly.
- **Token exchange failed** — wrong client secret or redirect URI not registered in the IdP.

### Notes for the security-minded

- `config/settings.json` and `config/users.json` hold secrets and account data. The admin drops deny-all `.htaccess` into `config/`, `cache/`, `slides/`, and `photos/` automatically (Apache). **On nginx add:** `location ^~ /boards/(config|cache|slides|photos)/ { deny all; }` (adjust the path).
- **Concurrent writes:** board saves and rotation deploys use locked read-modify-write so overlapping admin work does not lose changes — see [Concurrent saves & JSON storage](#concurrent-saves--json-storage).
- Login is session-based with CSRF protection, strict session cookies, configurable idle timeout (**Security → Admin idle timeout**), and login lockout after repeated failures.
- **Outbound fetch policy:** RSS/ICS URLs block private IPs unless **Security → Allow private URL fetches** is enabled. YouTube downloads only accept `youtube.com` / `youtu.be`. yt-dlp updates verify SHA-256 from the official GitHub release.
- Put **HTTPS** in front if admin is reachable from the internet (reverse proxy or Cloudflare Tunnel). For defense in depth, VPN or Cloudflare Access is recommended on semi-public hosts.
- `php video.php fetch` still works from the CLI; admin can download YouTube entries and update yt-dlp from the Video Board page.

## index.php — Weather (built previously)
Allendale weather, RainViewer animated radar, sunrise arc. Needs `OWM_API_KEY`.

## lake.php — Lake Michigan Conditions
- **Data:** NDBC buoy 45161 (Muskegon nearshore) + NWS active alerts + computed sun times. **No API keys.**
- **Setup:** change `NWS_UA` to include a real contact email (NWS asks for this). Optionally change `NDBC_STATION`.
- Nearshore buoys are pulled for winter (~Nov–Apr); the board says so and keeps NWS alerts live.
- Swim risk is a wave-height heuristic, escalated to HIGH automatically when a Beach Hazards Statement or Rip Current alert is active.

## webcam.php — Grand Haven Beach Webcam
Live beach view full-screen on the wall — embeds the EarthCam stream used by [Surf Grand Haven](https://surfgrandhaven.com) (presented by MACkite).

- **Data:** third-party embed URL (default is the EarthCam **share** link from surfgrandhaven.com — not the WordPress page itself). No API key.
- **Setup:** admin → **Webcam** — paste the iframe `src` URL if the default ever breaks. Optional overlay title + clock; attribution line defaults to `EarthCam · MACkite · Surf Grand Haven`.
- **Rotation:** use a long dwell (120s+); the stream is live video. Default iframe reload every hour (`RELOAD_SEC`) clears memory if EarthCam stalls in a long kiosk session — set `0` to disable.
- EarthCam may show its own controls/branding around the video; that is normal for share embeds.

## photo.php — Photo Conditions
- **Data:** PHP sun math (golden/blue hour), synodic moon-phase calc with drawn SVG moon, OpenWeatherMap cloud cover at sunset (tonight + next 3 evenings), NOAA SWPC Kp index + 24h forecast.
- **Setup:** set `OWM_API_KEY` (same key as the weather board). SWPC needs no key.
- Verdict heuristic: ≤20% clouds = CLEAN LIGHT, 21–70% = DRAMATIC SKY, 71–85% = MARGINAL, else FLAT GRAY. Aurora callout appears at Kp ≥ 6.

## air.php — Air & Pollen
US air quality and pollen on one board — AQI, PM2.5/PM10, ozone, NO₂, pollen bars, and a three-day outlook.

- **Data:** [Open-Meteo Air Quality API](https://open-meteo.com/en/docs/air-quality-api) for US AQI and pollutants — **free, no API key.** Pollen for US locations uses the **Google Pollen API** (optional key in admin); Open-Meteo pollen is Europe-only and returns empty for most US coordinates.
- **Setup:** admin → **Air & Pollen** — set place name, lat/lon, timezone. For US pollen, enable **Pollen API** in Google Cloud, create a key, and paste it under **Google Pollen API key** (free tier is 5,000 calls/month; default 15-minute cache keeps usage low).
- **Layout:** large US AQI panel, pollutant stats, today's pollen (Grass / Weed / Tree UPI when Google is configured), three-day AQI outlook, and a plain-language verdict banner.
- Without a Google key, air quality still works; the pollen section shows a short setup note instead of dashes.
- Default cache TTL 900s (15 min). Direct view can auto-reload on an interval; in rotation the shell reloads with the playlist.

## sports.php — Detroit Sports
Lions, Tigers, Pistons, and Red Wings on a single 2×2 board with a **Next games** strip across the bottom.

- **Data:** ESPN public site API (`site.api.espn.com`) — **no API key.** All fetches are server-side with file cache (default 300s; scoreboards refresh sooner).
- **Setup:** admin → **Detroit Sports** — title/subtitle and timezone only; the four teams are built in. Add `sports.php` to rotation via the quick-add preset or playlist editor (~75s dwell is a reasonable default).
- **Season logic:** calendar windows per league (NFL/MLB/NBA/NHL) plus nearby games — live games, finals within a week, or a game in the next ~3 weeks count as in-season. Distant openers stay **Off season** with the actual date (e.g. `Sep 13 · vs NO`), not a vague “this Sunday.” Preseason games appear automatically when ESPN lists them.
- **Live games:** card shows score + period/inning; direct view auto-refreshes every ~2 minutes while any team is live.
- Team logos come from ESPN; sport-icon fallbacks appear only when a logo is unavailable.

## signaltrace.php — Threat Wall
- **Data:** your SignalTrace instance via `GET /export/stats/extended` and `GET /export/json` with a 24h `from`/`to` window.
- **Setup:** set `ST_BASE_URL` and `ST_EXPORT_TOKEN` (the `EXPORT_API_TOKEN` from SignalTrace's `includes/config.local.php`).
- All API calls happen **server-side** — the export token never reaches the signage browser. 60-second cache + reload.

## homelab.php — Homelab Ops
- **Data:** Proxmox `/api2/json/cluster/resources` (node CPU/RAM, guest list, storage), AdGuard Home `/control/stats`, HTTP checks of `SERVICES`, and WAN round-trip to 1.1.1.1.
- **Setup:**
  - Proxmox: create an API token (Datacenter → Permissions → API Tokens) with a read-only role like **PVEAuditor**, then set `PVE_TOKEN_ID` / `PVE_TOKEN_SECRET`. `PVE_VERIFY_TLS=false` is the default for self-signed certs.
  - AdGuard: `ADGUARD_URL` / `ADGUARD_USER` / `ADGUARD_PASS` (same as web UI).
  - Add rows under **Services** for each HTTP(S) endpoint you want pinged — the default is an empty list (nothing to check until you configure it).
- Each panel degrades independently — an unreachable AdGuard doesn't take down the Proxmox panel.

## rotator.php — Vedders Visuals Photo Rotator
- **Upload:** admin → **Photo Rotator** — JPG/PNG up to 25 MB each (multiple files at once). Delete from the same page. Deploy-to-rotation status is on **Status**.
- **Setup:** photos land in `./photos/` by default (configurable **Photo directory**). The board serves images via `?img=` — they are not directly downloadable from the web root. You can still point **Photo directory** at an absolute path outside the webroot and copy files there manually.
- Reads camera model + capture month from EXIF for the caption (`SHOW_EXIF`), dedupes "SONY SONY" style Make/Model overlap, crossfades every `INTERVAL_SEC`, honors `prefers-reduced-motion`, and reloads every 6h to pick up new files. Brand overlay text is configurable via **Brand wordmark** in admin.

## slides.php — Custom Slides
Upload your own JPG/PNG/WebP images or build text slides in admin, then schedule each one independently.

- **Upload / create:** new slides auto-deploy to **main** rotation. Use **Deploy to displays** at the top of **Custom Slides** to sync one playlist entry per slide to any screen — each entry uses that slide's dwell seconds. Sync status is on **Status**.
- **Slide deck:** drag cards to reorder. Each card shows **Active now**, schedule summary, and per-slide timing. Save the deck with **Deploy to** checkboxes to sync rotation on selected displays.
- **Rotation:** each enabled slide is its own playlist entry (`slides.php?slide=…`) with dwell from that slide's settings. Per-slide schedules control which entries are active on the wall (including priority takeover). In **Rotation**, slide entries appear grouped under **Custom slides** so long decks don't clutter the playlist.
- **Slide creator:** same page — start from an occasion **template** (Birthday, Fall, Baseball, Bowling, etc.) or pick a **Photo scene** background (curated photography, dimmed for readability) or a **Theme color** gradient. Full-width edit fields, live preview at 1920×1080, **Create slide** saves into `./slides/`.
- **Scheduling (per slide in the deck):**
  - **always** — show whenever the deck is on screen (optional hour window)
  - **once** — single date in **From** (`YYYY-MM-DD`)
  - **range** — inclusive **From** … **To** (`YYYY-MM-DD`)
  - **yearly** — every **MM-DD** (birthdays, anniversaries)
  - **yearly_range** — **MM-DD** through **MM-DD end** every year (e.g. `12-24` … `01-06` for the holidays)
  - **monthly** — every month on **Day** (1–31)
  - **weekly** — **Weekday** dropdown and/or **Days** text (`Mon,Wed,Fri` or full names)
  - **Hr from / Hr to** — optional 0–23 window on any schedule (overnight like 22→6 works)
  - **Priority** — when any priority slide is active, only priority slides show (emergency / takeover)
  - **Off** — bench without deleting
- Each slide can set **Caption**, **Dwell (seconds)**, and image **fit** (`contain` / `cover`). The board reloads every 5 minutes so midnight and birthday boundaries pick up without restarting the kiosk.
- Uploaded images live in `./slides/` (web server must write there; blocked from direct HTTP access like `config/`).

## traffic.php — Traffic Map
Live TomTom Traffic Flow tiles on a dark Carto basemap (Leaflet), with optional city markers. Defaults to the Allendale ↔ Grand Rapids area but center, zoom, and labels are all editable in admin.

- **Setup:** free TomTom Developer key at [developer.tomtom.com](https://developer.tomtom.com/) (enable **Traffic API** on the key) → admin → **Traffic Map** → paste key. Tiles are fetched through `traffic_tiles.php` so the key never appears in the browser.
- **What you should see:** colored lines on major roads — green = free flow, yellow/red = slower/congested (default style `relative0-dark`). Late night on quiet roads, some segments may be sparse, but I-96 and US-131 should still show TomTom coverage around Grand Rapids.
- **Nothing colored, only the dark basemap?** Tiles are not loading. After updates, make sure `traffic_tiles.php` is deployed to the web root (see [setup-server.sh](#setup-serversh--the-web-host)). Then test on the server:

      curl -I "http://localhost/boards/traffic_tiles.php?style=relative0-dark&z=11&x=536&y=753"

  Expect `HTTP/1.1 200` and `Content-Type: image/png`. If you get 403/502, check the key in admin and TomTom portal → your app → **Traffic API** enabled. Errors are logged to `cache/traffic_tiles/last_error.txt`.
- **Hard to see green?** Try **Flow style → `relative0`** in admin for brighter colors on the dark basemap, or bump **Zoom** to 12.
- Tiles load in the browser; the key is visible to the kiosk — fine on a LAN wall.
- **Flow style** `relative0-dark` matches the dark basemap best. Map reloads on a configurable interval (default 5 min).

## family.php — Calendar
- **Setup:**
  - `ICS_FEEDS`: one row per calendar — **Key** (legend label, e.g. Dad), **Color** (theme palette), then source/URL. Keys and colors appear on the calendar board legend and beside each event.
  - `TRASH_WEEKDAY` and `RECYCLE_ANCHOR` (any past date recycling was collected; drives the every-other-week cadence). Leave trash day as **(default)** to hide the chip entirely — useful for apartments.
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
- **Sound:** muted by default for wall displays. **Admin → Video Board** — uncheck **Mute all videos** and save. Kiosks set up with `setup-kiosk.sh` already allow unmuted autoplay.
- **Fetching:** use **Admin → Video Board → Download / refresh YouTube videos**, or run `php video.php fetch` on the server. Downloads land in `./videos/` (capped at 1080p mp4); admin shows each video's duration for rotation dwell. Re-fetch after URL changes; optional weekly cron if sources update:
  `0 4 * * 1 cd /var/www/boards && php video.php fetch >> /var/log/video-fetch.log 2>&1`
- **yt-dlp upkeep:** admin shows installed vs latest GitHub release and can update yt-dlp (`bin/yt-dlp` download from admin).
- **YouTube bot checks:** headless servers often get “Sign in to confirm you’re not a bot”. Fix:
  1. Install **deno** on the server (`setup-server.sh` does this) — yt-dlp needs a JS runtime for YouTube.
  2. Export **Netscape-format** cookies while logged into YouTube:
     - **Mac + Chrome:** `yt-dlp --cookies-from-browser chrome` often fails (Chrome v10/v11 cookie encryption). Use a browser extension instead — e.g. **Get cookies.txt LOCALLY** — open [youtube.com](https://www.youtube.com) signed in, export cookies for that site, save as `cookies.txt`.
     - **Firefox** export via extension also works well.
     - Test on your Mac **before** uploading (needs **yt-dlp 2025.10+** — Homebrew is often older; check with `yt-dlp --version`):
       ```bash
       brew install deno
       # Upgrade yt-dlp — pick one:
       brew upgrade yt-dlp
       # or: pip3 install -U "yt-dlp[default]"
       # or: curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o ~/bin/yt-dlp && chmod +x ~/bin/yt-dlp
       yt-dlp --js-runtimes deno --remote-components ejs:github \
         --cookies cookies.txt -F 'https://www.youtube.com/watch?v=VIDEO_ID'
       ```
       If you see `no such option: --js-runtimes`, your yt-dlp is too old — use pip or the GitHub binary above.
       You must see **mp4/webm** rows (720p, 1080p, …) — not just `sb0` storyboard lines.
  3. Copy to the server: `scp cookies.txt server:/var/www/html/boards/config/cookies/youtube.txt` then `sudo chown www-data:www-data …/youtube.txt && sudo chmod 640 …/youtube.txt`. Re-export when fetches fail again.
  4. **Fallback:** download the video on your Mac (`yt-dlp … -o lantern.mp4`), `scp` to `videos/` on the server, and set the video row to **local file** `lantern.mp4` instead of a YouTube URL — no cookies needed on the server.
- The player loops, so an asset duration slightly longer than the video wraps to the start instead of going black.
- **Requires:** `yt-dlp` in PATH or `bin/yt-dlp`, **deno** (or node) for YouTube, optional `config/cookies/youtube.txt`, plus `ffmpeg`/`ffprobe` for merged downloads and duration readouts.
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

- **In the rotation shell (board.php)** the ticker renders once in the shell itself and is genuinely persistent across page transitions; framed boards are passed `?noticker=1` and use a 72px safe-bottom inset so content isn't clipped. Opened directly, each board still shows its own ticker — and because the scroll position is computed from the wall clock rather than page-load time, it stays seamless even under an external rotator like Anthias.
- **In player.php** the ticker runs in the outer PWA document (the iframe loads `board.php?noticker=1`); it polls a lightweight JSON endpoint so alerts appear and disappear without a full page reload.
- **Setup:** set `TICKER_LAT`/`TICKER_LON` and put a contact email in `TICKER_UA`. One shared cache file means the NWS API is polled at most once per `TICKER_TTL` (5 min) no matter how many boards rotate.
- `TICKER_MODE`: `'scroll'` (marquee) or `'static'` (one alert at a time, 9s each, also clock-phased).
- `TICKER_MIN_SEVERITY`: hide alerts below `Minor`/`Moderate`/`Severe`.
- `TICKER_DEMO = true` renders sample alerts so you can check the layout, then set it back to `false`.
- To add it to future boards: `<?php include __DIR__ . '/ticker.php'; ?>` before `</body>`.

## Rotation & deployment (self-hosted — no Anthias needed)

### board.php — the rotation shell (multi-screen)
Point each kiosk browser at `board.php?screen=<key>`; it cycles that screen's boards with crossfades. Screens, page lists, dwell times, and optional hour windows (0–23, overnight like 22→6 supported) are all edited in **admin.php → Rotation** — no file edits. Two stacked iframes preload each board before revealing it, a configurable hang timeout skips any page that fails to load, and the alert ticker lives in the shell so it persists across transitions.

**Display settings (super admin):** open the **Display settings** panel on the Rotation page to configure per-screen options in one table — weather ticker, clock, debug overlay, crossfade/settle/hang timings (blank = global default), weighted/shuffle rotation, blank hours (CEC off/on), and HDMI-CEC. Kiosk URL per screen: `board.php?screen=KEY` (plain `board.php` = `main`). Operators see a smaller per-display options block inside their playlist panel.

**Playlists:** each screen *is* a playlist — pages in order (or shuffled: check **Shuffle** on the screen's row and the play order randomizes once per full pass, so every page still appears once per cycle, hour windows and Skip still apply, and nothing repeats back-to-back), per-page dwell, per-page hour windows, and a **Skip** checkbox to bench a page without deleting its row (dwell and window settings are kept for when you re-enable it). Screens don't have to map to hardware: define an `ambient` or `guests` playlist and point any display or Channels capture at it when the occasion calls for it.

**Multiple displays:** define screens under **Rotation → Display settings** (one row per display — e.g. `main` / Living Room, `garage` / Garage Bench); after saving, each screen gets its own playlist editor. Point each device at its URL: plain `board.php` is the `main` screen, `board.php?screen=garage` is the garage. Assign operators to exactly one display under **Users**. Any number of devices can share one URL (they render independently; the shared server-side cache means ten screens cost the same API usage as one), and a screen with no pages of its own — or an unknown `?screen=` value — falls back to the main rotation, so a freshly provisioned kiosk always shows something.

**Status:** **admin.php → Status** shows which kiosks are online, what they are playing, and whether photo/slide decks are synced to each display's rotation — without cluttering the Rotation editor.

Entries are relative URLs, so parameterized boards work naturally: `rss.php?feed=krebs`, `grafana.php?d=homelab`, `video.php?v=drone`, `slides.php?slide=birthday.png`, `webcam.php`, `air.php`, `sports.php`, `traffic.php` (set the dwell to the duration `php video.php fetch` reports for video entries).

### setup-server.sh — the web host
Onboards a fresh Ubuntu / Debian / Raspberry Pi OS machine as the signage **server** (Apache, PHP, permissions, hardening):

    sudo bash setup-server.sh
    sudo bash setup-server.sh --webroot /var/www/html/boards --with-video-cron
    sudo bash setup-server.sh --clone https://github.com/you/signage-suite.git
    sudo bash setup-server.sh --domain signage.lan          # dedicated vhost
    sudo bash setup-server.sh --nginx                       # nginx snippet instead of Apache

What it installs and configures:
- Apache (or an nginx snippet), PHP 8.x with curl, xml, mbstring, gd, and **opcache**, plus ffmpeg
- **yt-dlp** via pipx (default; use `--no-ytdlp` to skip) and optional weekly **`php video.php fetch`** cron (`--with-video-cron`)
- Deploys board files to the web root, creates `config/`, `cache/`, `videos/`, and `slides/` owned by `www-data`
- Blocks direct HTTP access to `config/`, `cache/`, and `slides/` (Apache `DirectoryMatch` or nginx `location`)
- Enables **PHP OPcache** (`98-signage-opcache.ini`) — bytecode cache for faster admin and board requests; uses smaller pools on hosts under 2 GB RAM
- Raises PHP / Apache / nginx timeouts to **1 hour** for admin YouTube downloads (`99-signage-timeouts.ini`)
- Generates `slide_backgrounds/` theme PNGs if missing (php-gd)
- Fetches `slide_backgrounds/photos/` from Unsplash/Pexels if missing (outbound HTTPS; skipped when already present)

Re-run safely after pulling updates (preserves your config and uploads):

    cd ~/signage-suite && git pull
    sudo bash setup-server.sh --skip-apt --source ~/signage-suite --webroot /var/www/html/boards

If the webroot **is** the git checkout (installed with `--clone`), updates are just:

    cd /var/www/html/boards && sudo git pull

### setup-kiosk.sh — the display device
Turns a fresh Raspberry Pi OS Lite (Bookworm) install into the kiosk:

    sudo bash setup-kiosk.sh "http://your-server/boards/board.php?screen=garage" [scale]

(Quote the URL when it contains `?screen=`. The optional scale argument — e.g. `2` — fills a 4K display, since boards are designed at 1920×1080.)

It installs cage (minimal Wayland compositor) + Chromium, creates a systemd service that boots into the rotation and restarts on crash, schedules a nightly 04:00 browser restart to flush memory, and (by default) installs **HDMI-CEC power sync** — a timer on the player box that polls the server every minute and sends standby/on based on the schedule you set in **Admin → Rotation → Display settings** (CEC checkbox, Off hr, On hr). Use `--no-cec` to skip CEC on boxes without a CEC-capable TV. Set **Rotation → Timezone** on the server so off/on hours match local wall time. Chromium runs with `--autoplay-policy=no-user-gesture-required`, so un-muted video works if you ever want sound. Any small x86 box works the same way — reuse the unit file.

Re-run after pulling updates to refresh the CEC sync script:

    cd ~/signage-suite && git pull
    sudo bash setup-kiosk.sh "http://your-server/boards/board.php?screen=garage" [scale]

After setup the Pi never needs touching for content: everything is managed on the server through admin.php. The Pi only needs occasional `apt full-upgrade`.

### player.php — PWA player (phones, tablets, testing)
`board.php` is fixed at 1920×1080 by design; `player.php` wraps it in a stage that scales and letterboxes to fit **any** viewport, so you can test rotations on a laptop window, tablet, or phone. Same screen selection: `player.php?screen=garage`. The alert ticker is rendered in the outer document (not inside the scaled iframe), with live polling so alerts appear without reloading the rotation.

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
Every board works unchanged as an Anthias web asset (`lake.php`, `air.php`, `sports.php`, `rss.php?feed=ars`, ...) — set per-asset durations there and skip board.php. The per-board ticker includes handle slide cuts seamlessly either way.

## General notes
- Keep all files in one folder so they share `config/` and `cache/`.
- Runtime directories created on first use: `config/` (settings, user accounts, setup key), `cache/` (API responses, SSO discovery cache, **admin audit log**, kiosk presence), `videos/` (yt-dlp downloads), `slides/` (uploaded and creator-generated slide images), `photos/` (rotator uploads). `slide_backgrounds/` ships theme PNGs; `slide_backgrounds/photos/` is populated by `setup-server.sh` (or first admin visit) if not already in git.
- JSON state files may have companion `*.lock` files during writes; leave them in place.
- Legacy single-password `config/admin.json` is migrated automatically to `config/users.json` on first login.
- Every board shows a small diagnostic stamp bottom-right when an API call fails (HTTP code or curl error) while continuing to render from cache.
