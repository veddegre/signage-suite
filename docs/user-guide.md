# Signage user guide

Manual for **super admins**, **infrastructure** staff, and **operators** who configure wall displays through **admin.php**. Give this document to anyone who needs to understand what each board does, where data comes from, and how to configure rotation and sharing.

**Related deep dives:** [boards.md](boards.md) (every board) · [admin-and-security.md](admin-and-security.md) (SSO, hardening) · [rotation-and-deployment.md](rotation-and-deployment.md) (playlists, kiosk) · [tdx.md](tdx.md) · [grafana.md](grafana.md) · [powerbi.md](powerbi.md)

---

## Contents

1. [How signage works](#1-how-signage-works)
2. [Roles — who can do what](#2-roles--who-can-do-what)
3. [Super admin guide](#3-super-admin-guide)
4. [Infrastructure guide](#4-infrastructure-guide)
5. [Operator guide](#5-operator-guide)
6. [Admin sidebar reference](#6-admin-sidebar-reference)
7. [Rotation playbook](#7-rotation-playbook)
8. [Content ownership & sharing](#8-content-ownership--sharing)
9. [Integration setup index](#9-integration-setup-index)
10. [Troubleshooting & diagnostics](#10-troubleshooting--diagnostics)
11. [Glossary](#11-glossary)

---

## 1. How signage works

### The three layers

| Layer | What it is | You edit it in |
|-------|------------|----------------|
| **Boards** | Individual 1920×1080 pages (weather, Zabbix, slides, …) | Admin sidebar → each board |
| **Rotation** | Playlist that crossfades boards on a physical display | **Setup → Rotation** (rows in `config/rotation/pages/<screen>.json`) |
| **Kiosk** | Browser or Pi running `board.php?screen=<key>` fullscreen | [kiosk-setup.md](kiosk-setup.md) |

Most settings save to **`config/settings.json`**. **Playlist rows** (URLs, dwell, hours, weights) save to **`config/rotation/pages/<screen>.json`** — one file per display. Board PHP files are never edited — only configuration changes.

### Display URL

Each TV or monitor points at:

```
http://your-server/boards/board.php?screen=garage
```

The **`screen`** key matches a playlist under **Rotation**. One server can serve many displays (`main`, `garage`, `lobby`, …).

### Data flow

1. A board PHP file runs on the server.
2. It reads settings and calls external APIs (or local files) server-side.
3. Results cache under **`cache/`** for 30s–1h depending on the board.
4. If an API fails, stale cache is shown when possible — the wall stays up with a small diagnostic stamp.

Secrets (API tokens, passwords, BEID keys) **never** reach the display browser.

### Saving settings

- **Blank field** on save = keep default or unchanged (password fields never echo back).
- **Save** at the bottom of each admin page applies that board’s changes.
- **Rotation playlist rows** write to `config/rotation/pages/<screen>.json`; display names and kiosk options still go to `settings.json`.
- Concurrent saves on **different** admin boards merge safely on `settings.json` (file locking). **Different displays’ playlists** use separate files — two operators saving **different** TVs do not block each other.
- **Same display, two editors** — last **Rotation → Save** wins for that playlist file.
- **Users** page is the exception on settings — last save wins if two super admins edit accounts at once.

---

## 2. Roles — who can do what

| Capability | Super admin | Infrastructure | Operator |
|------------|:-----------:|:--------------:|:--------:|
| All admin sidebar boards | ✓ | Partial | Partial |
| **Users**, **Tools**, **Security**, **Audit** | ✓ | — | — |
| Homelab, UniFi, SignalTrace, Kuma, Tailscale, ntfy admin | ✓ | ✓ | — |
| Zabbix, TeamDynamix, Grafana, Splunk, Power BI, slides, RSS, … | ✓ | ✓* | ✓* |
| **Rotation** for any display | ✓ | Assigned only | Assigned only |
| Set global API secrets (Board settings) | ✓ | —** | —** |
| Create users & assign displays | ✓ | — | — |
| Emergency override (all displays) | ✓ | — | — |
| **Share all with Operators** on multi-page boards | ✓ | — | — |

\* Same as operators unless a board is infrastructure-only (Kuma admin config) or content is not shared with them.  
\** Infrastructure users do not get **Board settings** with API secrets unless the board allows operator settings (slides/rotator paths only).

### What “operator” means in practice

Operators **own** content for their assigned display(s): slides, rotation playlist, RSS feeds, announcement pages, Zabbix/TDX pages they create, etc. They cannot see another operator’s display in the **Users** picker unless they are a **shared editor** on that display.

### What “infrastructure” adds

Same as operator, plus admin access to **Homelab**, **UniFi**, **SignalTrace**, **Uptime Kuma**, **Tailscale**, and **ntfy** — including **Board settings** with API keys for those systems. Kuma pages can be bulk-shared with the Infrastructure role.

---

## 3. Super admin guide

### First-time setup checklist

1. **Install server** — `sudo bash setup-server.sh` (see README).
2. **Create super admin** — open admin.php; use one-time key from `config/setup.key` (SSH only).
3. **Security** — idle timeout, **Allow private URL fetches** if you use LAN Zabbix/TDX/homelab URLs.
4. **Weather** — set OpenWeatherMap key and default lat/lon (used by many boards).
5. **Rotation** — create display keys (`main`, `lobby`, …); build playlists.
6. **Users** — create operator/infrastructure accounts; assign displays.
7. **Integrations** — configure API credentials per board (Zabbix, TDX, Grafana, …).
8. **Kiosk** — point displays at `board.php?screen=<key>` ([kiosk-setup.md](kiosk-setup.md)).
9. **SSO** (optional) — [admin-and-security.md → SSO](admin-and-security.md#sso-setup-entra-id--authentik).

### Day-to-day tasks

| Task | Where |
|------|--------|
| Add a new TV / display | **Rotation** → new screen key → **Users** → assign owner |
| Delegate playlist editing | **Users** assign display **or** **Rotation** → shared editors |
| Share Zabbix/TDX pages with a team | Board page → **Share all with Operators** or per-page **Access** |
| Force emergency message | **Rotation → Emergency override** |
| See which kiosks are online | **Status** |
| Audit who changed what | **Audit** (if enabled under Security) |
| Clear stuck API cache | **Tools → Clear cache** |
| Back up configuration | Copy `config/settings.json`, `config/users.json`, and `config/rotation/pages/` |

### API secrets — who sets them

Super admin only (**Board settings** collapsed section on each board):

| Board | Secrets |
|-------|---------|
| TeamDynamix | Base URL, BEID, Web Services Key (or user/password) |
| Zabbix | URL, API token |
| Grafana | JWT secret / RS256 key, JWKS URL (Cloud) |
| Splunk panels | Management URL, token |
| Power BI | Azure tenant, client ID, client secret |
| Traffic | TomTom key |
| Cloudflare Radar | API token |
| Homelab / UniFi / Tailscale / ntfy | Per-board tokens |

Operators configure **page tabs** and **content rows** once secrets exist — not the global credentials.

---

## 4. Infrastructure guide

You have operator capabilities **plus** monitoring infrastructure boards.

### Your admin sidebar extras

Under **Monitoring** (in addition to public feeds and shared Zabbix/TDX pages):

| Board | Purpose | Typical config |
|-------|---------|----------------|
| **Homelab ops** | Proxmox + AdGuard summary | Proxmox URL/token, AdGuard URL |
| **UniFi Network** | UDM/site health | Local admin cookie or API key |
| **SignalTrace** | Export-driven network trace wall | Export token |
| **Uptime Kuma** | Monitor grid | Kuma URL, status page slug per page, optional API key |
| **Tailscale** | Tailnet device status | Tailscale API key |
| **ntfy alerts** | Webhook/poll alert wall | Server URL, topic, webhook token |

These boards are **omitted from operator rotation quick-add** and hero-strip pickers unless operators are given shared pages on Zabbix/TDX/etc.

### Kuma multi-page workflow

1. Super admin or you set **KUMA_URL** in **Board settings**.
2. **+ Add page** per status page slug (or tag filter with API key).
3. **Share all with Infrastructure** if multiple infra staff need the same pages.
4. Quick-add **`kuma.php?d=<key>`** under **Monitoring** in Rotation.

### When to escalate to super admin

- New SSO user or display assignment conflict
- Azure / Grafana / Power BI tenant-wide setup
- Security policy changes (private URL fetches, audit)
- Emergency override

---

## 5. Operator guide

### Your typical workflow

1. Log in to **admin.php** (local password or SSO).
2. Open **Rotation** — you see only display(s) assigned to you (and shared-editor displays).
3. **Add boards** tab — search quick-add, or paste URLs like `slides.php?slide=menu.png`.
4. Edit **dwell**, **hours**, **skip**, **weight** per playlist row.
5. **Kiosk settings** tab — location override, sports teams, hero strip, ticker options.
6. **Save** rotation.
7. Upload content on **Slides**, **Photo Rotator**, **RSS**, **Video**, etc.

### Boards you can own content on

**Media:** Slides, Photo Rotator, Video, RSS  
**Daily:** Announcements, Calendar (feeds you add)  
**Dashboards:** Grafana rows, Splunk pages, Power BI rows, Websites  
**Monitoring:** Zabbix pages, **TeamDynamix pages** (when shared or self-created)  
**Setup:** Rotation (your displays), Account, Status  

You **cannot** open: Users, Tools, Security, Homelab, UniFi, Kuma admin, Tailscale admin, ntfy admin (unless your org grants Infrastructure role).

### Creating your own monitoring pages

**Zabbix** and **TeamDynamix** support **+ Add page**:

1. Open the board in admin.
2. Click **+ Add page** — pick a URL key (`network`, `myqueue`, …).
3. Set filters (host groups or TDX app/filters).
4. You automatically **own** the page; super admin can **Access**-share it.
5. **Rotation → Quick add → Monitoring**.

If **Board settings** say credentials are missing, ask super admin to configure global connection first.

### Preview before adding to rotation

Most boards have **Preview ↗** on each row or page tab — opens the wall with ticker suppressed.

---

## 6. Admin sidebar reference

Grouped as in admin. **Rotation URL** = what you add to a playlist (parameterized boards need `?d=` / `?feed=` / etc.).

### Setup

| Admin board | Wall file | Rotation URL | Data source | Who configures secrets |
|-------------|-----------|--------------|-------------|------------------------|
| **Security** | — | — | — | Super admin |
| **Rotation** | `board.php` | `board.php?screen=KEY` | Local playlist | Operator (own displays) |
| **Ticker** | (overlay) | — | NWS alerts + optional RSS | Super admin |

### Weather & home

| Admin board | Wall file | Rotation URL | Data source |
|-------------|-----------|--------------|-------------|
| **Weather** | `index.php` | `index.php` | OpenWeatherMap |
| **Lake Michigan** | `lake.php` | `lake.php` | NDBC buoy + NWS |
| **Webcam** | `webcam.php` | `webcam.php?cam=KEY` | External streams / images |
| **Mackinac Bridge cam** | `bridgecam.php` | `bridgecam.php` | MDOT feed |
| **Photo conditions** | `photo.php` | `photo.php` | OWM + local photo dir |
| **Air & pollen** | `air.php` | `air.php` | AirNow, Open-Meteo, NWS |
| **UV index** | `uv.php` | `uv.php` | Open-Meteo |
| **Sports** | `sports.php` | `sports.php` | ESPN APIs |
| **Calendar** | `calendar.php` | `calendar.php` | ICS feeds |
| **Today at a glance** | `glance.php` | `glance.php` | Calendar + RSS columns |
| **Meal calendar** | `meals.php` | `meals.php` | Admin-entered meal plan |
| **Traffic map** | `traffic.php` | `traffic.php` | TomTom |
| **MDOT Cams** | `camwall.php` | `camwall.php` | MDOT camera grid |

Per-display **location**, **sports teams**, and **glance columns** override globals under **Rotation → Kiosk settings**.

### Daily

| Admin board | Rotation URL | Notes |
|-------------|--------------|-------|
| **Word of the day** | `wotd.php` | — |
| **This day in history** | `history.php` | — |
| **Dad jokes** | `joke.php` | — |
| **Announcements** | `announce.php?d=KEY` | Countdowns; hero strip option |
| **XKCD** | `xkcd.php` | — |

### Monitoring (feeds & integrations)

| Admin board | Rotation URL | Keys / access |
|-------------|--------------|---------------|
| **Homelab ops** | `homelab.php` | Infra + super |
| **UniFi Network** | `unifi.php` | Infra + super |
| **Uptime Kuma** | `kuma.php?d=KEY` | Infra + super; multi-page |
| **Tailscale** | `tailscale.php` | Infra + super |
| **ntfy alerts** | `ntfy.php` | Infra + super |
| **Cloud outages** | `outages.php` | Optional M365 Graph |
| **Internet infrastructure** | `internet.php` | IODA + `dig` |
| **Internet attacks** | `attacks.php` | DShield — no key |
| **DShield heatmap** | `dshieldmap.php` | — |
| **Attack origins** | `dshieldsrc.php` | — |
| **Top attack ports** | `attackports.php` | — |
| **Outage map (IODA)** | `iodamap.php` | — |
| **Cloudflare Radar** | `radar.php` | Radar API token |
| **Attack map L7** | `attackmap.php` | Radar token |
| **L3 attack map** | `l3map.php` | Radar token |
| **HIBP breaches** | `hibp.php` | — |
| **New CVEs** | `cve.php` | NVD key optional |
| **CISA KEV** | `kev.php` | — |
| **TLS cert expiry** | `certexp.php` | Host list |
| **Ransomware tracker** | `ransomware.php` | — |
| **Phishing & brand** | `phish.php` | URLhaus key optional |
| **SignalTrace** | `signaltrace.php` | Infra + super |
| **Zabbix Monitoring** | `zabbix.php?d=KEY` | Super sets token; multi-page |
| **TeamDynamix** | `tdx.php?d=KEY` | Super sets BEID/key; multi-page |

Full per-board setup: [boards.md](boards.md).

### Media

| Admin board | Rotation URL | Notes |
|-------------|--------------|-------|
| **Slides** | `slides.php?slide=FILE` | Upload, schedule, slide creator |
| **Photo Rotator** | `rotator.php` | `./photos/` directory |
| **Video** | `video.php?v=KEY` | Local + YouTube via yt-dlp |
| **RSS Stories** | `rss.php?feed=KEY` | Image fit per feed |

### Dashboards

| Admin board | Rotation URL | Integration doc |
|-------------|--------------|-----------------|
| **Grafana** | `grafana.php?d=KEY` | [grafana.md](grafana.md) / [grafana-cloud.md](grafana-cloud.md) |
| **Splunk Panels** | `splunk.php?d=KEY` | [boards.md](boards.md) |
| **Splunk Published** | `splunkdash.php?d=KEY` | iframe publish URLs |
| **Power BI** | `powerbi.php?d=KEY` | [powerbi.md](powerbi.md) |
| **Websites** | `web.php?d=KEY` | Any iframe-allowed URL |

---

## 7. Rotation playbook

Each display’s playlist is stored in **`config/rotation/pages/<screen>.json`**. Saving **Rotation** updates that file (plus display options in `settings.json`). After upgrading the server, open **Rotation** once or load each kiosk URL so legacy `rotation.PAGES_*` keys migrate out of `settings.json`.

### Add a board to one display

1. **Rotation** → select display tab (e.g. `garage`).
2. **Add boards** → search or browse quick-add groups.
3. Click **Add** — row appears in playlist.
4. Set **dwell** (seconds on screen), optional **hours** / **weekdays**, **weight** (weighted shuffle).
5. **Save**.

### Multi-page boards

One rotation row per page key:

```
zabbix.php?d=network
zabbix.php?d=signage
tdx.php?d=helpdesk
tdx.php?d=myqueue
grafana.php?d=soc
```

### Display options (per screen)

**Rotation → Kiosk settings** for each display:

| Option | Effect |
|--------|--------|
| **Location** | Overrides lat/lon for weather, air, traffic, NWS ticker |
| **Sports teams** | Filters sports board |
| **Glance columns** | Left/right RSS or page URLs on glance board |
| **Hero strip** | Status bar slots (Zabbix page, announce, ntfy, …) |
| **Shuffle / Weighted** | Random vs weighted airtime |
| **Crossfade timings** | Transition ms |
| **News ticker fallback** | RSS when no NWS alerts |

### Templates

**Rotation → Templates** — load **Kitchen weeknight**, **Weekly planner**, **Security wall**, or save your own.

### Emergency override (super admin)

**Rotation → Emergency override** — forces ticker, announcement, or shared playlist on **all** displays until **Release**.

Details: [rotation-and-deployment.md](rotation-and-deployment.md).

---

## 8. Content ownership & sharing

On most content boards, each row or page tab has **Access**:

| Control | Meaning |
|---------|---------|
| **Owner** | Primary editor; created automatically when operators add rows |
| **Shared with users** | Named accounts |
| **Shared with roles** | All **Operators** (or **Infrastructure** on Kuma share) |

**Bulk actions:**

- **Zabbix / Splunk / TeamDynamix** — **Share all with Operators** (super admin)
- **Uptime Kuma** — **Share all with Infrastructure**
- **Slides** — **All operators** on selected slides

Unowned rows/pages are **super-admin only** on the wall and in quick-add.

Details: [admin-and-security.md → Content ownership](admin-and-security.md#content-ownership--sharing).

---

## 9. Integration setup index

Use these guides for credential setup and troubleshooting — not duplicated here in full.

| System | Operator can add pages? | Setup guide |
|--------|-------------------------|-------------|
| **TeamDynamix** | Yes (after super sets BEID/key) | **[tdx.md](tdx.md)** |
| **Zabbix** | Yes | [boards.md → Zabbix](boards.md#zabbixphp--zabbix-monitoring-json-rpc-7x) |
| **Grafana (self-hosted SSO)** | Dashboard rows | [grafana.md](grafana.md) |
| **Grafana Cloud** | Dashboard rows | [grafana-cloud.md](grafana-cloud.md) |
| **Power BI (private embed)** | Report rows | [powerbi.md](powerbi.md) |
| **Splunk panels** | Multi-page | [boards.md → Splunk](boards.md) |
| **Uptime Kuma** | Infra only (admin) | [boards.md → Kuma](boards.md) |

---

## 10. Troubleshooting & diagnostics

### On the wall

| Stamp / symptom | Meaning |
|-----------------|--------|
| Bottom-right cache message | API failed; showing stale data |
| “Setup” panel | Board not configured in admin |
| Blank rotation | Empty playlist or all rows out of time window |

### In admin

| Symptom | Try |
|---------|-----|
| Cannot save | Another save in progress on `settings.json` — wait and retry |
| Cannot save rotation | Another save on the **same** display playlist — wait and retry (different displays use separate files) |
| **veddersg / display playlist empty after upgrade** | Check `config/rotation/pages/<screen>.json.bak` (created on each save after upgrade), or run `php scripts/recover-rotation-pages.php --screen=veddersg --force` |
| Board missing from sidebar | Your role lacks access |
| Quick-add missing a board | Not shared with you, **Off wall**, or infra-only |
| Preview works, rotation doesn’t | Wrong `?screen=` or row URL typo |

### CLI (on server)

Run from install root (`/var/www/html/boards` typically):

```bash
php scripts/diagnose-zabbix.php main
php scripts/diagnose-tdx.php helpdesk --timing
php scripts/diagnose-powerbi.php --test --key=ops
php scripts/diagnose-grafana.php
php scripts/diagnose-rotation.php SCREEN_KEY   # shows playlist file path
php scripts/diagnose-kev.php
```

Inspect a display playlist on disk: `config/rotation/pages/<screen>.json`.

Clear board cache: delete specific files under `cache/` or **Tools → Clear cache**.

### Private LAN URLs

If Zabbix, TDX, Grafana, homelab, or UniFi use an internal IP/hostname, enable **Security → Allow private URL fetches**.

---

## 11. Glossary

| Term | Definition |
|------|------------|
| **Board** | Single full-screen PHP page (e.g. `lake.php`) |
| **Display / screen** | A physical TV identified by `?screen=` in rotation |
| **Playlist file** | `config/rotation/pages/<screen>.json` — saved rotation rows for one display |
| **Dwell** | Seconds a playlist row stays visible |
| **Quick-add** | Rotation helper that inserts common board URLs |
| **Page key** | Short name in `?d=` for multi-page boards (Zabbix, TDX, Splunk, Kuma, Grafana, …) |
| **Hero strip** | Optional status bar above rotation (announce, Zabbix summary, ntfy, …) |
| **Shared editor** | Operator who may edit another user’s display playlist |
| **BEID** | TeamDynamix backend service account identifier |
| **TDWebApi** | TeamDynamix REST API (`/TDWebApi/api/…`) |

---

*Document version aligns with the signage-suite repository. For installation and server scripts, see [README.md](../README.md).*
