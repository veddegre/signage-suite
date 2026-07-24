# Admin & security

All configuration flows through **admin.php**. Most values land in **`config/settings.json`**; **rotation playlist rows** (URL, dwell, hours, weights, …) live in **`config/rotation/pages/<screen>.json`** — one file per display. Board PHP files are never edited on disk; the web server only needs write access to `config/`, `cache/`, `videos/`, `slides/`, and `photos/`.

## How settings work

- Each board has built-in defaults (the old `const` values).
- A **blank field** in admin means “use the default.”
- **Password and API-key fields** left blank on save mean “unchanged” — secrets are never echoed back into HTML.
- **Tools** (super admin) can clear the API cache and view or edit raw JSON for **`settings.json` only** — playlists are not shown there; edit them under **Rotation** or by hand in `config/rotation/pages/`.

## Accounts & roles

Accounts live in `config/users.json`, blocked from direct HTTP like `settings.json`.

**First visit:** create a **super admin** using the one-time key in `config/setup.key` (read over SSH — prevents a stranger from claiming admin on a public host).

| Role | Access |
|------|--------|
| **Super admin** | All boards, **Users**, **Tools**, **Security**, every display |
| **Infrastructure** | Same as **Operator**, plus **Homelab**, **UniFi Network**, **SignalTrace**, **Uptime Kuma**, **Tailscale**, and **ntfy** admin boards |
| **Operator** | Content boards + **Rotation** for assigned display(s) and shared-editor displays; **Account**, **Status** — not the infrastructure-only monitoring boards above |

→ **[User guide](user-guide.md)** — full manual for super admins, infrastructure, and operators (sidebar reference, rotation, sharing, integrations).

### Sidebar layout

Admin boards are grouped in a **collapsible** sidebar — click a category header to expand or collapse. State is saved in the browser (`localStorage`).

| Group | Boards |
|-------|--------|
| **Setup** | Security, Rotation, Ticker |
| **Weather & home** | Weather, Lake, Webcam, Photo, Air, Sports, Calendar, Traffic |
| **Monitoring** | Homelab, SignalTrace, UniFi Network, Uptime Kuma, Tailscale, ntfy (Infrastructure + super admin), Zabbix, TeamDynamix, cloud outages, … |
| **Daily** | Word of the day, This day in history, Dad jokes, Announcements, XKCD |
| **Media** | Slides, Photo Rotator, Video, RSS |
| **Dashboards** | Grafana, Splunk Panels, Splunk Published, Power BI, Websites |

**Users** and **Tools** are super-admin only. Footer: **Status**, **Account**, logout.

| Admin page | Purpose |
|------------|---------|
| **Account** | Change local password (hidden for SSO-linked accounts) |
| **Users** | Create users, assign roles (super / infrastructure / operator), assign display(s) |
| **Status** | Kiosk heartbeats, play log, slide/photo deploy sync |
| **Security** | Idle timeout, outbound URL policy, SSO, multi-display policy, audit settings |
| **Audit** | Sign-ins, saves, user changes (not cleared with API cache) |

**Login:** local username/password and/or SSO, CSRF-protected sessions, configurable idle logout, lockout after repeated failures.

### Display assignment (operators & infrastructure)

Each physical display (rotation screen) has **one primary owner** — enforced on save so the same screen cannot be assigned to two people. **Infrastructure** users use the same display assignment rules as operators.

| Mode | Setting | Behavior |
|------|---------|----------|
| **Single display** (legacy) | **Security → Operators may manage multiple displays** off | Each operator or infrastructure user gets exactly one display via a dropdown |
| **Multiple displays** (default) | Same setting **on** (also toggled on **Users** when saving) | Checkbox picker — assign one or more screens per operator or infrastructure user |

When assigning displays on **Users**, the picker lists only:

- Displays **already assigned to that operator** (so they can keep or remove them)
- Displays **not assigned to anyone**

Screens owned by **other** operators are **hidden** (not greyed out) to avoid confusion.

Operators with multiple displays can manage rotation, deploy targets, and per-screen playlists for **all** of their assigned screens. Super admins see every display.

### Shared display editing

Super admins assign **shared editors** per display under **Rotation** (checkboxes in each playlist panel). A shared editor is an operator who is **not** the primary owner but may manage that display’s **full** configuration:

- Playlist order, dwell, hour windows, skip, weights
- Display options (ticker, **shuffle / weighted** rotation, crossfade timings, **hero status strip**, **location**, **sports teams**, **glance headline columns**)
- Deploy and sync (slides, photos, etc.) including content owned by the primary operator on that display
- Rotation quick-add for boards visible to the primary owner

Shared editors do **not** automatically see the primary owner’s content on **other** displays, or unrelated boards in admin — only what they own, what is shared with them, and what belongs to the primary owner **for displays they edit**.

### Emergency override

Super admins only — **Rotation → Emergency override**. Forces one of three modes on **every** display (within ~30s): custom **ticker** over normal rotation (optionally with NWS weather alerts in the same bar), a single **announcement** wall, or a shared **emergency playlist**. Optional **auto-release**, **ntfy** notifications, and a banner on **Status** for all admins. Operators cannot save rotation while it is active. **Release** restores normal per-display behavior. Logged under **Audit**.

## Content ownership & sharing

On operator boards (**Slides**, **Photo Rotator**, **RSS**, **Websites**, **Video**, **Grafana**, **Splunk**, **Splunk Published**, **Power BI**, **Zabbix**, **TeamDynamix**, **Announcements**, **Calendar**, …), each row has an **Access** control (super admin). Three layers:

| Layer | Purpose |
|-------|---------|
| **Owner** | One user responsible for the row; operators they create own automatically |
| **Shared with users** | Named operators (or any account) who may view and edit |
| **Shared with roles** | Everyone with that role — today **Operators** (super admins already see everything) |

Stored in settings as `owner`, `shared` (user ids), and `shared_roles` (e.g. `["operator"]`).

**Examples:**

- Team slide deck — set owner to one person, check **Operators** under roles so the whole team can edit without listing every username.
- One-off collaboration — add specific users under **Shared with users** only.
- **Slides** deck toolbar — **All operators** bulk-adds the Operators role to selected slides; **All users** adds every account individually.
- **Zabbix / Splunk / TeamDynamix** — super admin **Share all with Operators** on the page bar shares every tab at once; operators can also **+ Add page** to create their own walls (owned automatically).
- **Uptime Kuma** — Infrastructure-only admin; super admin **Share all with Infrastructure** on the page bar when multiple people need the same Kuma pages.

Homelab, UniFi, SignalTrace, Uptime Kuma, Tailscale, and ntfy **admin configuration** stays **super admin** or **Infrastructure** only — operators do not see those sidebar entries or board settings, and those boards are omitted from rotation **quick-add** and hero-strip source pickers. Other monitoring walls (Cloudflare Radar, outages, Zabbix pages when shared, etc.) stay selectable in playlists. Setup/security boards (Users, Security, …) stay super-admin only. API tokens on infra boards stay super-admin **Board settings** unless you delegate via Infrastructure role.

Board-level API secrets (Splunk token, Zabbix token, TeamDynamix BEID/key, Grafana JWT secret, Power BI Azure client secret, TomTom key, etc.) remain super-admin only. Grafana JWT setup: [grafana.md](grafana.md). Power BI Azure setup: [powerbi.md](powerbi.md). TeamDynamix TDAdmin setup: [tdx.md](tdx.md).

## Concurrent saves & JSON storage

Settings, accounts, audit history, and kiosk presence are stored as JSON on disk — no database required. That fits teams on the order of dozens of users.

### Where data lives

| Data | Path | Notes |
|------|------|--------|
| Board settings, API secrets, slide/photo decks, multi-page registries (Zabbix, TDX, …) | `config/settings.json` | Locked read → merge → write |
| **Rotation playlist rows** per display | `config/rotation/pages/<screen>.json` | e.g. `main.json`, `lobby.json` — **separate lock per display** |
| Display names, shared editors, kiosk options, emergency override, playlist templates | `config/settings.json` | Saved with **Rotation** (non-playlist fields) |
| Accounts | `config/users.json` | Super admin **Users** page |
| Kiosk heartbeats | `cache/presence.json` | Written by `board.php` |
| Audit log | `cache/admin_audit.json` | Not cleared with API cache |

Legacy installs may still have `rotation.PAGES_<screen>` keys inside `settings.json` until playlists are loaded once; the server **migrates** those keys into `config/rotation/pages/` automatically and removes them from settings.

### Concurrent edits

**Rotation playlists:** Two operators saving **different** displays lock **different** JSON files — they do not queue on the whole settings file.

**Other admin saves:** Still use locking on `settings.json` (and `users.json` for accounts). If two people save **different** boards at once, the second request waits, reads the latest file, and merges on top — one save no longer silently overwrites another. The same protection applies to deploy-to-rotation actions (playlist file + optional slide deck in settings), SSO linking, JIT provisioning, and kiosk heartbeats.

**Same display, two editors:** Last completed **Rotation → Save** wins for that display’s playlist file — coordinate shared editors on one TV.

**Users page caveat:** that screen posts the entire user table on each save. Two super admins editing **Users** at the same time means whoever saves last wins the whole table (not corruption — just full-form replace). Coordinate user changes.

If a lock cannot be acquired in time, admin shows: *Another admin save is in progress — wait a moment and try again.*

Sidecar `*.lock` files next to JSON files are normal during writes.

**Backups:** Include `config/settings.json`, `config/users.json`, and **`config/rotation/pages/*.json`** together.

## SSO setup (Entra ID & Authentik)

Admin login supports **OpenID Connect** for **Microsoft Entra ID**, **Authentik**, and any standard OIDC provider.

**Prerequisites:** PHP **curl** (already required). HTTPS in front of admin is strongly recommended for production SSO.

### 1. Configure the identity provider

Register a **Web** OAuth2/OIDC application. Note the **client ID**, **client secret**, and **issuer URL**.

| Provider | Issuer URL | Redirect URI |
|----------|------------|--------------|
| **Entra ID** | `https://login.microsoftonline.com/<tenant-id>/v2.0` | Copy from admin after step 2 |
| **Authentik** | Provider issuer URL (often `https://auth.example.com/application/o/<slug>/` — **trailing slash must match exactly**) | Same |

**Entra:** App registration → **Authentication** → add redirect URI as **Web**. Create client secret under **Certificates & secrets**.

**Authentik:** **Applications** → Provider (OAuth2/OpenID) + Application → add redirect URI to the provider.

### 2. Enable SSO in Signage

1. Log in as super admin → **Security**
2. Check **Enable SSO login**
3. Set **SSO provider** to `entra` or `authentik` (preset hints only)
4. Fill **OIDC issuer URL**, **OIDC client ID**, **OIDC client secret**
5. Leave **OIDC scopes** at default `openid profile email` unless your IdP requires more
6. **Save** — copy the **Redirect URI** shown into Entra/Authentik if not already registered

**Useful options:**

| Option | Purpose |
|--------|---------|
| **Username claim** | JWT field matched to admin username (default `preferred_username`; falls back to `email` / Entra `upn`) |
| **Auto-link by email** | On first SSO sign-in, match existing user when email local-part equals username |
| **Allow local password login** | Keep username/password form when SSO is on (default: yes) |
| **SSO just-in-time provisioning** | Auto-create **operator** accounts on first sign-in (never super); optional domain/group filters |
| **Operators may manage multiple displays** | When on (default), assign more than one rotation display per operator under **Users** |
| **Enable audit log** | Record admin actions (default on) |

### 3. Create SSO users (or enable JIT)

**Manual (default):** SSO does not auto-provision unless JIT is enabled.

1. **Users** → **+ Add user** (or edit existing)
2. Set **Auth** to **SSO**
3. **Username** must match what the IdP sends (usually email local-part)
4. Set role and display assignment → **Save users**

On first successful SSO sign-in the account **links** (“Linked” status). Until then: “Pending first sign-in”. Linked accounts authenticate only via SSO.

**JIT provisioning:** enable **Security → SSO just-in-time provisioning**. First sign-in creates an **operator** with no display — super admin assigns a screen under **Users**.

1. Set **JIT allowed email domains** (e.g. `yourcompany.com`)
2. Optionally set **JIT required groups/roles** — user must have any listed value in token `groups` or `roles` (Entra: enable groups/roles in token; Authentik: groups in userinfo)
3. New JIT users appear in **Users** as SSO operators

### Troubleshooting SSO

| Symptom | Likely cause |
|---------|----------------|
| Could not load OpenID discovery | Wrong issuer URL or server cannot reach IdP (Authentik on private LAN still works — SSO calls are allowed to the configured issuer even when **Allow private URL fetches** is off) |
| No matching admin account | Create user under **Users**, enable JIT, or check domain/group filters |
| ID token issuer mismatch | Entra tenant URL or Authentik issuer trailing slash does not match exactly |
| Token exchange failed | Wrong client secret or redirect URI not registered in IdP |

## Security hardening

- `config/settings.json`, `config/users.json`, and **`config/rotation/pages/`** hold configuration and secrets. Admin drops deny-all `.htaccess` into `config/`, `cache/`, `slides/`, and `photos/` (Apache). **nginx:** `location ^~ /boards/(config|cache|slides|photos)/ { deny all; }`
- Login uses CSRF protection, strict session cookies, configurable idle timeout (**Security → Admin idle timeout**), and lockout after failures
- **Outbound fetch policy:** RSS/ICS URLs block private IPs unless **Security → Allow private URL fetches** is enabled (required for LAN Zabbix, homelab, UniFi, some RSS feeds)
- YouTube downloads only accept `youtube.com` / `youtu.be`; yt-dlp updates verify SHA-256 from official GitHub releases
- Put **HTTPS** in front if admin is internet-facing (reverse proxy, Cloudflare Tunnel). VPN or Cloudflare Access recommended on semi-public hosts
- `php video.php fetch` works from CLI; admin can refresh YouTube entries from the Video Board
