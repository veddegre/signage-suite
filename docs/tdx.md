# TeamDynamix (TDX) — ticket wall via TDWebApi

Native **1920×1080** wall for open tickets from [TeamDynamix TDWebApi](https://demotemplate.teamdynamix.com/TDWebApi/swagger) — no iframe, no SSO on the kiosk. JWT credentials stay server-side.

Rotation URL pattern: **`tdx.php?d=<key>`** — same multi-page model as Zabbix and Splunk.

---

## Overview

| Approach | When to use |
|----------|-------------|
| **This board (TDWebApi)** | Ticket queues on a wall — filter by app, group, user, type, status |
| **TDX embedded widget (iframe)** | Pre-built TDX dashboard widgets — requires SSO or public embed |
| **Report Builder API** | Fixed report rows — different use case |

This board calls **`POST /api/{appId}/tickets/search`** server-side and renders ticket ID, title, status, priority, responsible group/person, overdue/SLA flags, and age.

---

## Quick start

1. Complete [TDAdmin credentials](#tdadmin--service-account-one-time) (BEID + Web Services Key recommended).
2. Admin → **Monitoring → TeamDynamix → Board settings:**
   - **TDX base URL** — e.g. `https://yourorg.teamdynamix.com`
   - **Auth mode** — `admin`
   - **BEID** + **Web Services Key**
   - **Verify TLS** — off if using self-signed certs on LAN
3. If TDX is on a private IP → **Security → Allow private URL fetches**
4. **Save** → **Test connection** (should list visible applications)
5. **+ Add page** — set **Application ID**, optional filters
6. **Refresh metadata** — copy app/type/status/group/priority IDs into the page tab
7. **Preview ↗**, then add `tdx.php?d=<key>` to rotation (quick-add under **Monitoring**)

---

## TDAdmin / service account (one time)

Administrative API calls use a **key-based service account**, not a normal user password. This matches how TeamDynamix documents [admin auth (`loginadmin`)](https://demotemplate.teamdynamix.com/TDWebApi/swagger) — tokens from normal user login may lack permission for some ticket searches.

### Prerequisites

- TDAdmin access with permission to view **Organization** credentials (often **Add BE Administrators** or equivalent in your tenant)
- Know your production hostname, e.g. `https://yourorg.teamdynamix.com`
- Signage server can reach that host (enable **Allow private URL fetches** if TDX is LAN-only)

### Step 1 — Open TDAdmin

1. Browse to your org’s **TDAdmin** URL (varies by tenant — often linked from your TDX portal footer or IT documentation).
2. Sign in with an account that can manage organization/backend settings.

### Step 2 — Locate BEID and Web Services Key

Exact menu labels vary by TeamDynamix version; look for:

- **Organization** → **Details**, **Backend**, or **Web Services**
- A section showing **BEID** (GUID) and **Web Services Key** (GUID)

Copy both values to a password manager — treat the Web Services Key like an API secret.

| Value | Used in signage as |
|-------|-------------------|
| BEID | **BEID (admin auth)** |
| Web Services Key | **Web Services Key** |

### Step 3 — Verify the service account is Active

Inactive service accounts return auth failures. In TDAdmin, confirm the backend/service account status is **Active** before continuing.

### Step 4 — Note your ticketing application ID(s)

You need a numeric **Application ID** per page tab:

**Option A — Signage metadata (after step 5 below)**  
**Refresh metadata** lists all applications with IDs.

**Option B — TDAdmin / TDX portal**  
Open the ticketing application you want on the wall; the app ID often appears in URLs or application settings.

**Option C — API**  
After auth works, `GET /api/applications` returns every app the service account can see.

### Step 5 — Enter credentials in signage admin

1. **admin.php** → **Monitoring → TeamDynamix**
2. Expand **Board settings**
3. Set:

| Field | Example |
|-------|---------|
| **TDX base URL** | `https://yourorg.teamdynamix.com` |
| **Auth mode** | `admin` |
| **BEID** | paste from TDAdmin |
| **Web Services Key** | paste from TDAdmin |
| **Verify TLS** | off only for self-signed LAN certs |

4. **Save**
5. Click **Test connection** — expect success with an application count
6. Click **Refresh metadata** — reference tables appear below

### Step 6 — Create page tabs for each wall

For each rotation slot you want (help desk, personal queue, projects, …):

1. **+ Add page** — URL key e.g. `helpdesk`, `myqueue`
2. **Page title / subtitle** — shown on the wall header
3. **Application ID** — from metadata table
4. Filters (optional):

| Goal | Set these fields |
|------|------------------|
| Team queue | **Responsible group IDs** |
| One person’s tickets | **Responsible users** = email or username |
| Incidents only | **Type IDs** |
| High priority only | **Priority IDs** |
| Custom status set | **Status IDs** (blank = open/in-process/on-hold) |

5. **Access** — share with Operators if others should quick-add this page
6. **Preview ↗** → add `tdx.php?d=<key>` to **Rotation**

### Step 7 — Share with operators (optional)

Super admin → **Share all with Operators** on the page bar, **or** set **Access** on each tab for specific users/roles.

Operators can then **+ Add page** for their own scopes without seeing BEID/key (**Board settings** stays super-admin only).

### Alternative: user auth

Set **Auth mode** to `user` and supply a dedicated TDX username/password. Useful for testing; production often requires **admin** auth for reliable ticket search. User tokens expire in ~24h; signage refreshes automatically.

---

## Signage admin UI walkthrough

### Page tabs bar

Each tab = one `tdx.php?d=<key>` rotation entry. Tabs are saved as `tdx.PAGES` in settings.

### Board settings (collapsed)

Global connection + cache TTLs. Only super admins (and roles with board settings access) see this section. Operators see page tabs only after super admin configures credentials.

### Test connection

POST auth + `GET /api/applications`. Does **not** save settings — save first if you changed URL or keys.

### Refresh metadata

Fetches and caches:

- All **applications**
- **Types**, **statuses**, **priorities** for the active tab’s app ID
- **Groups** org-wide

Reference tables appear under Board settings; datalists on filter fields help pick IDs.

### Advanced JSON

Super admin only — replace entire `tdx.PAGES` from JSON. Useful for bulk migration; see [example configs](#example-page-configs).

---

## Role-specific workflows

### Super admin

1. TDAdmin → BEID + key → signage **Board settings**
2. Create baseline pages (help desk, major incident, …)
3. **Share all with Operators** or per-page **Access**
4. Add pages to rotation templates

### Infrastructure

- Use TDX pages **shared** via Access (same as operators)
- Cannot edit **Board settings** or BEID/key
- May own rotation for assigned displays including `tdx.php?d=…` rows

### Operator

1. Confirm preamble says connection is configured (or ask super admin)
2. **+ Add page** for team-specific filters (e.g. `responsible_users` = self)
3. **Rotation → Quick add → Monitoring**
4. Cannot view or change global TDX URL/credentials

---

## Diagnostics & troubleshooting (extended)

### Admin buttons

| Button | Verifies |
|--------|----------|
| **Test connection** | Auth + applications list |
| **Refresh metadata** | Types/statuses/groups/priorities |
| **Preview ↗** | Full wall render for active tab |

### CLI

```bash
# Page config + search + wall cache
php scripts/diagnose-tdx.php main

# Include auth timing
php scripts/diagnose-tdx.php helpdesk --timing

# From git clone against live install
SIGNAGE_ROOT=/var/www/html/boards php scripts/diagnose-tdx.php myqueue
```

### Clear caches after credential change

```bash
rm -f cache/tdx_token_*.json cache/tdx_metadata.json cache/tdx_wall_*.json cache/tdx_person_*.json
```

Then **Test connection** in admin again.

---

## Board settings (global)

Admin → **Monitoring → TeamDynamix → Board settings**

| Setting | Purpose |
|---------|---------|
| **TDX base URL** | Org hostname — `/TDWebApi` appended if omitted |
| **Auth mode** | `admin` (BEID + key) or `user` (username + password) |
| **BEID** | Service account BEID (admin auth) |
| **Web Services Key** | Service account key (admin auth) |
| **Username / password** | User auth alternative |
| **Verify TLS** | Off for LAN self-signed certificates |
| **Default page title / subtitle** | Used when a page tab has no title |
| **Metadata cache (s)** | How long app/type/status/group lists are cached (default 3600) |
| **Cache TTL** | How long ticket search results are cached on the wall (default 60) |
| **Timezone** | Clock overlay on the board |

**Test connection** — authenticates and calls `GET /api/applications`.

**Refresh metadata** — reloads reference IDs for the active page tab's app (or all apps if no app ID set). Shows copy-paste tables under Board settings.

---

## Multiple pages

Each admin tab is its own wall and rotation entry:

```
tdx.php?d=main
tdx.php?d=helpdesk
tdx.php?d=myqueue
tdx.php?d=projects
```

### Roles and sharing

Same model as **Zabbix Monitoring**:

| Role | Typical use |
|------|-------------|
| **Super admin** | Sets global URL + credentials; can share all pages with Operators |
| **Operators** | Create pages for their team; **Access** controls who sees each page |
| **Infrastructure** | Can use pages shared to them via **Access** |

- Pages without an owner are super-admin only on the wall and in rotation quick-add.
- **Share all with Operators** — bulk-shares every page tab.
- Per-page **Access** — owner, users, roles (Operators).

---

## Per-page filters

| Field | API field | Purpose |
|-------|-----------|---------|
| **Application ID** | `{appId}` in URL | **Required** — which ticketing app to search |
| **Type IDs** | `TypeIDs` | Comma-separated ticket type IDs (optional) |
| **Status IDs** | `StatusIDs` | Comma-separated — **blank** = open, in-process, and on-hold only |
| **Responsible users** | `ResponsibilityUids` | Comma-separated **email or username** — tickets where person is **Responsible**; resolved via `GET /people/lookup` |
| **Responsible user UIDs** | `ResponsibilityUids` | Optional person GUIDs if lookup is ambiguous |
| **Responsible group IDs** | `ResponsibilityGroupIDs` | Comma-separated group IDs — tickets assigned to those groups |
| **Priority IDs** | `PriorityIDs` | Optional priority filter |
| **Include closed** | (status class 3) | When status IDs blank, also include closed statuses |
| **Include cancelled** | (status class 4) | When status IDs blank, also include cancelled |
| **Max tickets** | `MaxResults` | 1–50 (default 20) |
| **Off wall** | — | Hidden from kiosk and rotation quick-add |

Filters combine in a single search (TDX AND semantics). Leave a field empty to skip that filter.

### Example page configs

**Help desk queue (by group):**

```json
{
  "helpdesk": {
    "title": "Help Desk",
    "sub": "Open queue",
    "app_id": 12345,
    "group_ids": "7, 12"
  }
}
```

**Personal queue (by user):**

```json
{
  "myqueue": {
    "title": "My tickets",
    "sub": "Assigned to me",
    "app_id": 12345,
    "responsible_users": "jane.doe@yourorg.edu"
  }
}
```

**Incidents only, high priority:**

```json
{
  "incidents": {
    "title": "Incidents",
    "app_id": 12345,
    "type_ids": "3",
    "priority_ids": "4, 5",
    "max_tickets": 30
  }
}
```

### Finding IDs

1. **Refresh metadata** in Board settings (uses active tab's app ID for types/statuses/priorities).
2. Reference tables list **Applications**, **Types**, **Statuses**, **Groups**, **Priorities** with numeric IDs.
3. Datalists on page filter fields suggest IDs when metadata is loaded.
4. For **responsible users**, use work email or TDX username — signage resolves to a person GUID automatically.

If a **Responsible users** entry cannot be resolved, the wall shows an error (it will not fall back to unfiltered tickets).

---

## Wall layout

- **Header** — page title, subtitle, optional clock
- **Summary pills** — app name, open count, overdue count, SLA breach count, counts by priority
- **Ticket list** — `#ID`, priority (color-coded), title, type · group · responsible, status, age since last modified
- **Flags** — Overdue, SLA on individual rows
- **Footer** — cache refresh interval

Auto-reloads every **`CACHE_TTL`** seconds (default 60).

---

## Rotation

Add each page separately to the playlist:

```
tdx.php?d=helpdesk
tdx.php?d=network
```

**Quick add:** Admin → **Rotation** → **Monitoring** → *TeamDynamix — &lt;page title&gt;*

Recommended dwell: **60s** (matches default cache TTL).

Pages with **Off wall** checked or without an **Application ID** are omitted from quick-add.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Setup message on wall | URL or credentials missing | Board settings → save BEID/key or user/pass |
| `Authentication failed` | Wrong BEID/key or inactive service account | TDAdmin → verify service account Active |
| `401` on search | User token used for admin-only API | Switch to **admin** auth mode |
| `blocked URL` / connection failed | Private TDX host | **Security → Allow private URL fetches** |
| `Person not found` | Email/username typo or not in TDX | Fix **Responsible users** or use **Responsible user UIDs** |
| Empty list, no error | Filters too narrow | Clear status/type/group filters; check app ID |
| Stale data after TDX changes | Cache | Lower **Cache TTL** or wait for expiry |
| `429 Too Many Requests` | Rate limit (search ~30/min/IP) | Raise **Cache TTL**; reduce unique pages / refresh rate |

See also [Diagnostics & troubleshooting (extended)](#diagnostics--troubleshooting-extended) above for CLI and cache clearing.

---

## Rate limits and caching

| Call | Typical limit |
|------|----------------|
| Ticket search | 30 requests / 60s / IP |
| Applications, metadata | 60 requests / 60s / IP |

Signage caches:

- **JWT** — until ~2 min before expiry
- **Ticket wall data** — `CACHE_TTL` (default 60s)
- **Metadata + person lookup** — `METADATA_CACHE_TTL` (default 3600s)

---

## Security notes

- **BEID** and **Web Services Key** live in `config/settings.json` — same protection as other API secrets (filesystem permissions, no git commit).
- JWTs are cached server-side only; the kiosk never sees credentials.
- **Responsible users** lookup sends email/username to TDX `people/lookup` — use service accounts allowed to search people.
- Prefer a **dedicated** TDAdmin service account for signage — not a personal admin password in **user** auth mode.
- Ticket search results are summary fields only — no ticket descriptions or attachments on the wall.

---

## API reference

Official docs: [TDWebApi swagger](https://demotemplate.teamdynamix.com/TDWebApi/swagger)

| Operation | Method | Path |
|-----------|--------|------|
| User auth | POST | `/api/auth` |
| Admin auth | POST | `/api/auth/loginadmin` |
| Applications | GET | `/api/applications` |
| Ticket search | POST | `/api/{appId}/tickets/search` |
| Ticket types | GET | `/api/{appId}/tickets/types?isActive=true` |
| Ticket statuses | GET | `/api/{appId}/tickets/statuses` |
| Priorities | GET | `/api/{appId}/tickets/priorities` |
| Groups | POST | `/api/groups/search` |
| People lookup | GET | `/api/people/lookup?searchText=…` |

**Ticket search body** (subset used by signage):

```json
{
  "MaxResults": 20,
  "StatusIDs": [1, 2, 3],
  "TypeIDs": [4],
  "ResponsibilityUids": ["00000000-0000-0000-0000-000000000000"],
  "ResponsibilityGroupIDs": ["7", "12"],
  "PriorityIDs": [3, 4]
}
```

Search results omit full descriptions and custom attributes — sufficient for a NOC-style ticket list.

---

## Related docs

- **[user-guide.md](user-guide.md)** — roles, rotation, sidebar reference (give this to operators and infra staff)
- [boards.md → tdx.php](boards.md#tdxphp--teamdynamix-tickets-tdwebapi) — board catalog entry
- [admin-and-security.md](admin-and-security.md) — private URL fetches, operator roles, sharing
