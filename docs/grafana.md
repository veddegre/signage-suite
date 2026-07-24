# Grafana — JWT embed for SSO-protected dashboards

Use this when **self-hosted Grafana** requires SSO for humans, but **signage players** must show private dashboards without an interactive login (same idea as Power BI embed tokens).

Signage signs a short-lived **HS256 JWT** and appends it to the kiosk iframe URL as `auth_token=…`. Grafana validates the token via `[auth.jwt]` and starts a session as a dedicated **Viewer** user.

---

## Overview

| Path | When to use |
|------|-------------|
| **JWT embed** (this guide) | Work Grafana behind SSO; private dashboards on wall players |
| **Public dashboard URL** | Non-sensitive data; no auth |
| **Anonymous Viewer** | Homelab / LAN-only Grafana without SSO |

---

## Quick start

1. Complete [Grafana server setup](#grafana-server-setup) below.
2. Admin → **Dashboards → Grafana**:
   - Enable **JWT auth for embed**
   - **JWT signing secret** — same raw secret encoded in Grafana’s JWK file
   - **JWT key ID (kid)** — default `signage`; must match JWK
   - **JWT login email** — Grafana user for walls (e.g. `signage-wall@yourorg.com`)
3. **Save** → **Test JWT signing**
4. Add a **Dashboards** row with the normal `/d/…` URL from your browser (while logged in via SSO).
5. **Preview ↗**, then add `grafana.php?d=<key>` to rotation.

---

## Grafana server setup

### Prerequisites

- Self-hosted Grafana with SSO already working for staff
- Permission to edit `grafana.ini` (or Helm/env equivalents) and restart Grafana
- A dedicated Grafana user (or `auto_sign_up`) for signage

### Step 1 — Embedding

In `grafana.ini`:

```ini
[security]
allow_embedding = true
```

### Step 2 — Create a signage Grafana user (recommended)

1. Grafana → **Administration → Users** (or invite via your IdP if you sync users).
2. Create **`signage-wall@yourorg.com`** (or a service-style address your org allows).
3. Role: **Viewer** in the org that owns the dashboards.
4. Grant access to the folders/dashboards the wall should see.

Alternatively, set `auto_sign_up = true` under `[auth.jwt]` so the first JWT login creates the user (less control over org role — prefer a pre-created user in production).

### Step 3 — Generate a shared signing secret

Use a long random secret (32+ bytes). Example:

```bash
openssl rand -base64 32
```

Save this value — it goes in **both** Grafana (JWK file) and signage admin (**JWT signing secret**).

### Step 4 — Create Grafana JWK file

Grafana verifies HS256 tokens using a **JWK set file**. The `k` field is **base64url** of the **raw secret string** (UTF-8 bytes).

Example: secret is `my-signing-secret-from-openssl` and kid is `signage`:

```bash
SECRET='paste-your-secret-here'
KID='signage'
K=$(printf '%s' "$SECRET" | openssl base64 -A | tr '+/' '-_' | tr -d '=')
cat > /etc/grafana/signage-jwk.json <<EOF
{
  "keys": [
    {
      "kty": "oct",
      "kid": "$KID",
      "alg": "HS256",
      "k": "$K"
    }
  ]
}
EOF
chmod 640 /etc/grafana/signage-jwk.json
chown grafana:grafana /etc/grafana/signage-jwk.json
```

### Step 5 — Enable JWT auth in Grafana

Add to `grafana.ini` (paths may vary):

```ini
[auth.jwt]
enabled = true
header_name = Authorization
email_claim = email
username_claim = email
jwk_set_file = /etc/grafana/signage-jwk.json
url_login = true
auto_sign_up = false
allow_assign_grafana_admin = false
```

| Setting | Purpose |
|---------|---------|
| `url_login = true` | Accept JWT via `?auth_token=` on the URL (required for iframe embed) |
| `email_claim` / `username_claim` | Must match claims signage sends (`email` + `sub`) |
| `jwk_set_file` | Verifies HS256 signature + `kid` |
| `auto_sign_up = false` | Only existing users can log in via JWT (recommended) |

If you use `expect_claims` in Grafana, set the same **JWT issuer** in signage admin (**JWT issuer** field).

Restart Grafana after changes.

### Step 6 — SSO + JWT together

JWT auth runs **alongside** your existing OAuth/SAML SSO:

- Staff → normal Grafana URL → SSO login
- Signage → `grafana.php` iframe with `auth_token` → JWT login, no SSO redirect

No reverse proxy required.

---

## Signage admin settings

Admin → **Dashboards → Grafana**:

| Field | Description |
|-------|-------------|
| **JWT auth for embed** | Master switch |
| **JWT signing secret** | Raw shared secret (same as used to build JWK `k`) |
| **JWT key ID (kid)** | Default `signage` — must match JWK |
| **JWT login email** | Default Grafana user for all rows |
| **JWT lifetime (seconds)** | Default 3600; player refreshes ~5 min before expiry |
| **JWT issuer (optional)** | Only if Grafana `expect_claims` requires `iss` |

Per dashboard row:

| Column | Description |
|--------|-------------|
| **Dashboard URL** | Full `https://grafana.company.com/d/uid/...` from browser |
| **JWT email override** | Optional different Viewer per dashboard |
| **Refresh / Extra params** | Passed through (`refresh=30s`, template variables, etc.) |

---

## Rotation

```
grafana.php?d=ops
grafana.php?d=network
```

Quick-add: **Rotation → Dashboards → Grafana — &lt;title&gt;**

---

## How token refresh works

1. PHP builds iframe `src` with `kiosk`, theme, refresh params, and `auth_token`.
2. Before the JWT expires, the board fetches `grafana.php?api=1&d=<key>` and reloads the iframe with a fresh token.
3. Hourly full page reload remains as a backstop.

---

## Diagnostics & troubleshooting

### Admin

**Test JWT signing** — verifies secret + email are set and PHP can sign a token.

### CLI

```bash
php scripts/diagnose-grafana.php
php scripts/diagnose-grafana.php --test
php scripts/diagnose-grafana.php --test --key=ops
```

### Common problems

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Redirect to SSO / login page | JWT not enabled or wrong secret/kid | Check `[auth.jwt]`, JWK file, `url_login=true` |
| Login failed / invalid JWT | Secret mismatch between signage and JWK `k` | Rebuild JWK from same raw secret |
| User not found | Email not in Grafana | Create user or set `auto_sign_up=true` |
| Empty dashboard / no permission | Viewer lacks folder access | Add user to team/folder with Viewer role |
| Blank iframe | `allow_embedding = false` | Set `[security] allow_embedding = true` |
| Token works briefly then fails | TTL too short vs refresh | Increase **JWT lifetime** or check browser network to `?api=1` |

Manual check: open (once) in a browser:

```
https://grafana.company.com/d/UID/dashboard-name?kiosk&auth_token=PASTE_TOKEN_FROM_TEST
```

You should land on the dashboard without SSO. Tokens expire quickly — use a fresh one from **Test JWT signing**.

---

## Security notes

- Use HTTPS for Grafana and signage.
- `auth_token` appears in URLs and may be logged by Grafana/web servers — use a **dedicated Viewer**, short TTL, and minimal dashboard access.
- Rotate the signing secret periodically; update JWK file and signage admin together.
- Do not reuse your SSO client secret as the JWT signing secret — use a separate random value.
- JWT embed does not grant Grafana admin; keep `allow_assign_grafana_admin = false`.

---

## Related docs

- [boards.md → grafana.php](boards.md#grafanaphp--grafana-iframe--jwt) — catalog summary
- [powerbi.md](powerbi.md) — similar token-embed pattern for Power BI
