# TV Guide (Schedules Direct)

Prime-time grid for channels you pick — `tvguide.php?d=<key>` in rotation.

**Install profile:** Available on **home** only. The **work** profile hides TV Guide from admin, rotation, and direct URLs (same as Meal Calendar and homelab boards).

## Requirements

- **[Schedules Direct](https://schedulesdirect.org/)** account (~$35/year)
- PHP **curl** extension (outbound HTTPS)
- Lineup configured in your SD account (up to 4 lineups)

## Setup

1. **admin.php → TV Guide → Board settings**
   - **SD_USERNAME** / **SD_PASSWORD** — your Schedules Direct login (password stored server-side only; plain text or SHA-1 hash)
   - **LINEUP** — lineup ID from your account (e.g. `USA-MI49503-X`)
   - **Prime time start / end** — local wall window (defaults 7:00 PM – 11:00 PM)
   - **Timezone** — must match your wall clock
2. Click **Test connection** — confirms auth and lists lineups / channel count
3. On each **page tab**, check the channels to show (e.g. NBC, CBS, ABC, FOX)
4. **Save**, then add to rotation via **Quick add → Media** or paste `tvguide.php?d=main`

## Pages

| URL | Use |
|-----|-----|
| `tvguide.php` | Main page (`main` key) |
| `tvguide.php?d=networks` | Custom page with its own channel list |

Each page supports **Access** (owner, users, roles) like RSS feeds and Zabbix pages.

## Wall display

- One row per channel (network + call sign by default — not 8.1-style broadcast numbers)
- Columns = each hour in the prime-time window
- Shows program title, episode subtitle when available, and start–end time
- After prime time ends, the board rolls forward to the **next** evening automatically
- Listings cached (`CACHE_TTL`, default 3600 s); stale cache used if SD is temporarily unreachable

## API notes

Uses Schedules Direct JSON API `20141201`:

- `POST /token` — authenticate (password sent as SHA-1)
- `GET /lineups` — account lineups
- `GET /lineups/{lineup}` — channel map
- `POST /schedules` + `POST /programs` — tonight's listings

Docs: [API-20141201](https://github.com/SchedulesDirect/JSON-Service/wiki/API-20141201)

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| “Schedules Direct not configured” | Set username + password under Board settings |
| “Set a lineup in admin” | Paste lineup ID from Test connection / SD website |
| “Select channels for this page” | Check channels on the page tab and Save |
| Empty grid / wrong times | Verify **Timezone** matches your locale |
| Test connection fails | Confirm account is active; password is correct (SD uses SHA-1 of password) |

Token and lineup data are cached under `cache/tvguide_*`.
