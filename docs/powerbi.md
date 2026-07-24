# Power BI — private reports on signage

The Power BI board (`powerbi.php?d=<key>`) supports two embed paths:

| Path | Best for | Viewer auth | Data visibility |
|------|----------|-------------|-----------------|
| **Publish to web** | Lobby dashboards, public metrics | None | **Public** — anyone with the link |
| **Embed tokens** (Azure AD service principal) | Private operational reports on wall displays | None on the player | Private — same as Yodeck-style Power BI embed |

For **private** reports on unattended kiosk players, use **embed tokens**. The signage server authenticates to Azure as a **service principal**, requests a short-lived **embed token** from the Power BI REST API, and the board embeds the report with the Power BI JavaScript SDK. Tokens refresh automatically before they expire.

---

## Quick start (private report)

1. Complete [Azure setup](#azure-setup-one-time) below (Entra app + Power BI admin settings + workspace access).
2. Admin → **Dashboards → Power BI** — paste **Azure tenant ID**, **client ID**, and **client secret**.
3. Click **Test Azure + Power BI API** — should report success.
4. Add a **Reports & dashboards** row:
   - **Key** — used in rotation (`powerbi.php?d=<key>`)
   - **Title** — label in admin / quick-add
   - **Mode** — `token` or `auto`
   - **URL** — paste Share → **Embed** link (`reportEmbed?…`), *or* leave blank and fill IDs below
   - **Workspace ID** — Power BI workspace GUID (`groupId`)
   - **Report ID** — report GUID (or use **Dashboard ID** instead)
5. **Save**, preview the row, add `powerbi.php?d=<key>` to your rotation playlist.

---

## Azure setup (one time)

These steps create an Entra ID **app registration** that acts as a **service principal**. Power BI treats it like a non-interactive user: it can request embed tokens for reports in workspaces where it has been granted access.

You need:

- **Entra ID** (Azure AD) — permission to create app registrations and grant admin consent
- **Power BI** — Pro or Premium capacity (or Fabric capacity) for the workspace holding your reports
- **Power BI administrator** — access to the Power BI **Admin portal** tenant settings (or someone who can enable service principals for you)

### Step 1 — Register an application in Entra ID

1. Open [Microsoft Entra admin center](https://entra.microsoft.com/) → **Identity** → **Applications** → **App registrations**.
2. **New registration**.
3. **Name** — e.g. `Signage Power BI Embed`.
4. **Supported account types** — *Accounts in this organizational directory only* (single tenant).
5. **Redirect URI** — leave blank (not used for client-credentials / service principal flow).
6. **Register**.

Copy and save:

| Value | Where to find it |
|-------|------------------|
| **Application (client) ID** | App registration **Overview** |
| **Directory (tenant) ID** | App registration **Overview** |

### Step 2 — Create a client secret

1. App registration → **Certificates & secrets** → **Client secrets** → **New client secret**.
2. Add a description (e.g. `signage embed`) and choose an expiry (note the date — you must rotate before it expires).
3. **Add** — immediately copy the **Value** (secret). It is shown only once.

Paste into admin → **Power BI → Azure client secret**. Treat it like any API key — never commit to git.

### Step 3 — Add Power BI API permissions (application)

1. App registration → **API permissions** → **Add a permission**.
2. **APIs my organization uses** → search **Power BI Service** → select it.
3. **Application permissions** (not Delegated) — add:

   | Permission | Purpose |
   |------------|---------|
   | `Report.Read.All` | Read reports and generate report embed tokens |
   | `Dataset.Read.All` | Read datasets (required for embed token generation) |
   | `Dashboard.Read.All` | Embed dashboards (if you use dashboard rows) |
   | `Workspace.Read.All` | List/read workspace metadata |

4. **Add permissions**.
5. **Grant admin consent for &lt;your tenant&gt;** — status should show green checkmarks for all four.

If **Grant admin consent** is greyed out, an Entra **Global Administrator** or **Privileged Role Administrator** must perform this step.

> **Note:** Permission names may appear under **Power BI Service** in the Entra portal. Do not use Microsoft Graph permissions for embed-token generation.

### Step 4 — Enable service principals in Power BI Admin portal

A Power BI **administrator** must allow service principals to call Power BI APIs:

1. Open [Power BI service](https://app.powerbi.com/) → **Settings (gear)** → **Admin portal** (requires Fabric/Power BI admin role).
2. **Tenant settings** → expand **Developer settings**.
3. Enable **Allow service principals to use Power BI APIs**:
   - Either **Entire organization**, or
   - **Specific security groups** — create a group, add the app’s service principal (see step 5), and scope the setting to that group.
4. **Apply**.

If your tenant also has a separate **Embed content in apps** or **Service principals can call Fabric public APIs** toggle, ensure embed/API access aligns with your Fabric/Power BI licensing — the exact labels vary slightly by tenant version.

### Step 5 — Add the service principal to your workspace

The app registration creates a **service principal** in Entra (same name as the app). It must be a member of the Power BI **workspace** that owns the report:

1. In Power BI service, open the target **workspace**.
2. **Manage access** (or workspace **Access**).
3. **Add people or groups**.
4. Search for your app name (e.g. `Signage Power BI Embed`) — it appears as a **service principal**, not a user mailbox.
5. Role: **Member** or **Admin** (Member is usually enough for embed).
6. **Add**.

Without workspace access, token generation returns **403 Forbidden** even when Azure AD authentication succeeds.

### Step 6 — Verify from signage admin

Admin → **Dashboards → Power BI**:

| Field | Value |
|-------|--------|
| **Azure tenant ID** | Directory (tenant) ID from step 1 |
| **Azure app (client) ID** | Application (client) ID from step 1 |
| **Azure client secret** | Secret value from step 2 |

Click **Save**, then **Test Azure + Power BI API**.

CLI equivalent:

```bash
php scripts/diagnose-powerbi.php --test
```

---

## Finding workspace, report, and dashboard IDs

### From the Share → Embed link (easiest)

1. Open the report in Power BI service.
2. **File** → **Embed report** → **Website or portal** (or **Share → Embed**).
3. Copy the embed URL, e.g.:

   ```
   https://app.powerbi.com/reportEmbed?reportId=aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee&groupId=ffffffff-gggg-hhhh-iiii-jjjjjjjjjjjj&w=2&config=...
   ```

4. Paste the full URL into the row **URL** field — the board parses `reportId` and `groupId` automatically.

You can also copy IDs into explicit columns:

| Admin column | URL parameter |
|--------------|---------------|
| **Workspace ID** | `groupId` |
| **Report ID** | `reportId` |
| **Dashboard ID** | `dashboardId` (dashboard embed links only) |

### From the browser address bar

Report URL shape:

```
https://app.powerbi.com/groups/<workspace-id>/reports/<report-id>/ReportSection
```

### My Workspace (no group)

Reports stored in **My Workspace** omit `groupId`. Leave **Workspace ID** blank and set **Report ID** only. The service principal must still have access to that content (My Workspace is harder to share with SPs — prefer a shared workspace for signage).

---

## Signage admin — per-report rows

Admin → **Dashboards → Power BI → Reports & dashboards**:

| Column | Description |
|--------|-------------|
| **Key** | Short id for `powerbi.php?d=<key>` |
| **Title** | Display name in rotation quick-add |
| **Mode** | See below |
| **URL** | Publish link or `reportEmbed` / `dashboardEmbed` URL |
| **Workspace ID** | Optional if present in URL |
| **Report ID** | Report GUID (or use Dashboard ID) |
| **Dashboard ID** | For dashboards instead of reports |
| **RLS username** | Effective identity username when the dataset uses row-level security |
| **RLS roles** | Comma-separated RLS role names |
| **Reload (s)** | Full page reload backstop (0 = disabled). Token boards refresh tokens automatically. |

### Mode

| Mode | Behavior |
|------|----------|
| **auto** (default) | Publish URL → public iframe; otherwise token embed when Azure is configured |
| **publish** | Force publish-to-web iframe only |
| **token** | Force private embed tokens (requires Azure + report/dashboard IDs) |

Per-row **Access** (owner, users, roles) works like other operator boards — operators only see rows they are allowed to edit.

---

## Publish to web (public reports)

For **non-sensitive** dashboards only:

1. Power BI service → report → **File** → **Embed report** → **Publish to web**.
2. Copy the link (`https://app.powerbi.com/view?r=…`).
3. Admin row: **Mode** = `publish` or `auto`, paste URL, save.
4. Add `powerbi.php?d=<key>` to rotation.

**Warning:** Publish-to-web is publicly accessible. Do not use for HR, finance, security, or other confidential data.

---

## Row-level security (RLS)

If the Power BI dataset enforces RLS, embed tokens must include an **effective identity**:

| Column | Example |
|--------|---------|
| **RLS username** | `signage@contoso.com` or a dedicated viewer account |
| **RLS roles** | `StoreManager,RegionWest` |

The username/roles must match roles defined in the Power BI dataset. If RLS is misconfigured, the report may render empty or token generation may fail.

---

## Rotation

Add each report as its own playlist line:

```
powerbi.php?d=ops_dashboard
powerbi.php?d=security_kpis
```

Or use **Rotation → Quick add → Dashboards → Power BI — &lt;title&gt;**.

Recommended dwell: **60–120 seconds** for dense reports.

---

## How token refresh works

1. On load, the board requests an embed token server-side and embeds via the Power BI JS SDK.
2. The browser polls `powerbi.php?api=1&d=<key>` before token expiry (~every 55 minutes).
3. The SDK calls `setAccessToken()` — no Microsoft login prompt on the player.
4. A full page reload runs hourly as a backstop.

Embed tokens and Azure AD tokens are cached under `cache/powerbi_*.json` on the server.

---

## Diagnostics & troubleshooting

### Admin

- **Test Azure + Power BI API** — verifies Entra client credentials and Power BI API reachability.
- Row **Preview ↗** — opens the board with ticker suppressed.

### CLI

```bash
# List configured rows and effective embed mode
php scripts/diagnose-powerbi.php

# Test Azure AD + Power BI API
php scripts/diagnose-powerbi.php --test

# Test embed token for one row
php scripts/diagnose-powerbi.php --test --key=ops_dashboard
```

### Common errors

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Test fails: Azure AD credentials not configured | Missing tenant/client/secret in admin | Fill all three Azure fields and Save |
| Azure AD token failed / invalid_client | Wrong client ID or secret | Regenerate secret; update admin |
| Power BI API 401 | Expired secret or missing admin consent | Re-grant API permissions + admin consent |
| GenerateToken **403** | Service principal not in workspace | Add SP to workspace as Member/Admin |
| GenerateToken **404** | Wrong workspace or report ID | Re-copy IDs from embed URL |
| Empty report with RLS | Missing/wrong RLS username or roles | Set **RLS username** and **RLS roles** on the row |
| “Service principals not allowed” | Tenant setting disabled | Enable in Power BI Admin portal (step 4) |
| Report works in admin preview but not on player | Display-scoped access / wrong screen | Check row **Access** and rotation screen assignment |

After changing Azure credentials or permissions, clear cached tokens:

```bash
rm -f cache/powerbi_*.json
php scripts/diagnose-powerbi.php --test --key=<your-key>
```

---

## Security notes

- **Client secret** is stored in `config/settings.json` (same as other API keys) — restrict filesystem permissions and never commit secrets.
- Embed tokens are short-lived and scoped to one report/dashboard; they are served to browsers loading `powerbi.php` (same trust model as other signage boards on your LAN).
- Use a **dedicated** Entra app for signage — not your primary admin or SSO app.
- Prefer a **shared workspace** with least-privilege workspace role (Member) over granting broad tenant-wide API access beyond the four read permissions listed above.
- **Publish to web** bypasses all of the above — treat those URLs as public.

---

## Related docs

- [boards.md → powerbi.php](boards.md#powerbiphp--power-bi-iframe--embed-tokens) — summary in the board catalog
- [rotation-and-deployment.md](rotation-and-deployment.md) — playlist and kiosk deployment
