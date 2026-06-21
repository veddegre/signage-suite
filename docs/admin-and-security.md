# Admin & security

All configuration flows through **admin.php** into `config/settings.json`. Board PHP files are never edited on disk; the web server only needs write access to `config/`, `cache/`, `videos/`, `slides/`, and `photos/`.

## How settings work

- Each board has built-in defaults (the old `const` values).
- A **blank field** in admin means “use the default.”
- **Password and API-key fields** left blank on save mean “unchanged” — secrets are never echoed back into HTML.
- **Tools** (super admin) can clear the API cache and view or edit raw JSON.

## Accounts & roles

Accounts live in `config/users.json`, blocked from direct HTTP like `settings.json`.

**First visit:** create a **super admin** using the one-time key in `config/setup.key` (read over SSH — prevents a stranger from claiming admin on a public host).

| Role | Access |
|------|--------|
| **Super admin** | All boards, **Users**, **Tools**, **Security**, every display |
| **Operator** | **Slides**, **Photo Rotator**, **RSS**, **Websites**, **Video**, **Grafana**, **Splunk**, **Zabbix**, **Calendar**, **Rotation** (assigned display only); **Account**, **Status** |

### Sidebar layout

| Group | Boards |
|-------|--------|
| **Setup** | Security, Rotation, Ticker |
| **Weather & home** | Weather, Lake, Webcam, Photo, Air, Sports, Calendar, Traffic |
| **Monitoring** | Homelab, SignalTrace, Zabbix |
| **Media** | Slides, Photo Rotator, Video, RSS |
| **Dashboards** | Grafana, Splunk Panels, Splunk Published, Websites |

**Users** and **Tools** are super-admin only. Footer: **Status**, **Account**, logout.

| Admin page | Purpose |
|------------|---------|
| **Account** | Change local password (hidden for SSO-linked accounts) |
| **Users** | Create users, assign roles, one display per operator |
| **Status** | Kiosk heartbeats, play log, slide/photo deploy sync |
| **Security** | Idle timeout, outbound URL policy, SSO, audit settings |
| **Audit** | Sign-ins, saves, user changes (not cleared with API cache) |

**Login:** local username/password and/or SSO, CSRF-protected sessions, configurable idle logout, lockout after repeated failures.

## Content ownership & sharing

On operator boards (**Slides**, **Photo Rotator**, **RSS**, **Websites**, **Video**, **Grafana**, **Splunk**, **Splunk Published**, **Zabbix**, **Calendar**, …), each row can have an **owner** and **shared with** list. Super admins see an **Access** control on each row.

Homelab, SignalTrace, weather, and setup boards stay super-admin only. Operators only see and edit entries they own or that are shared with them; new entries they create are owned automatically.

Board-level API secrets (Splunk token, Zabbix token, TomTom key, etc.) remain super-admin only.

## Concurrent saves & JSON storage

Settings, accounts, audit history, and kiosk presence are stored as JSON on disk — no database required. That fits teams on the order of dozens of users.

**Simultaneous admin saves** use file locking across read → merge → write. If two people save different boards at once, the second request waits, reads the latest file, and merges on top — one save no longer silently overwrites another. The same protection applies to deploy-to-rotation actions, SSO linking, JIT provisioning, and kiosk heartbeats.

If a lock cannot be acquired in time, admin shows: *Another admin save is in progress — wait a moment and try again.*

**Users page caveat:** that screen posts the entire user table on each save. Two super admins editing **Users** at the same time means whoever saves last wins the whole table (not corruption — just full-form replace). Coordinate user changes.

Sidecar `*.lock` files next to JSON files are normal during writes.

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

- `config/settings.json` and `config/users.json` hold secrets. Admin drops deny-all `.htaccess` into `config/`, `cache/`, `slides/`, and `photos/` (Apache). **nginx:** `location ^~ /boards/(config|cache|slides|photos)/ { deny all; }`
- Login uses CSRF protection, strict session cookies, configurable idle timeout (**Security → Admin idle timeout**), and lockout after failures
- **Outbound fetch policy:** RSS/ICS URLs block private IPs unless **Security → Allow private URL fetches** is enabled (required for LAN Zabbix, homelab, some RSS feeds)
- YouTube downloads only accept `youtube.com` / `youtu.be`; yt-dlp updates verify SHA-256 from official GitHub releases
- Put **HTTPS** in front if admin is internet-facing (reverse proxy, Cloudflare Tunnel). VPN or Cloudflare Access recommended on semi-public hosts
- `php video.php fetch` works from CLI; admin can refresh YouTube entries from the Video Board
