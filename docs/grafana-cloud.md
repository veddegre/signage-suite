# Grafana Cloud — embedding on signage

Grafana Cloud (`*.grafana.net`) is **managed** — you cannot edit `grafana.ini` yourself. Embedding private dashboards works differently than on self-hosted Grafana, but signage supports both paths.

---

## Three options on Grafana Cloud

| Option | Private data? | Signage setup | Grafana Cloud setup |
|--------|---------------|---------------|---------------------|
| **JWT embed (RS256)** | Yes | JWT enabled, algorithm **RS256**, PEM key, login email | **Grafana Labs support** must enable authenticated embedding + allow your signage origin |
| **Public / externally shared dashboard** | No (link is public) | Dashboard URL only, JWT **off** (or row JWT = off) | Enable public sharing in Cloud (support if missing) |
| **Snapshot** | Static moment in time | Paste snapshot URL | Create snapshot in Grafana |

There is **no** self-service `allow_embedding = true` on Grafana Cloud the way OSS has. Private iframe embed requires coordination with Grafana Labs.

---

## Option A — Private dashboards (JWT + RS256) — recommended if approved

This mirrors work self-hosted JWT embed, but Cloud verifies tokens via a **JWKS URL** (not a local file).

### 1. Generate an RSA key pair on the signage server

```bash
openssl genrsa -out grafana-signage.pem 4096
chmod 600 grafana-signage.pem
```

Keep the PEM private. Signage never exposes it — only the public key via JWKS.

### 2. Configure signage admin → Grafana

| Field | Value |
|-------|--------|
| **JWT auth for embed** | On |
| **JWT algorithm** | `rs256` |
| **JWT private key (PEM)** | Paste contents of `grafana-signage.pem` |
| **JWT key ID (kid)** | `signage` (default) |
| **JWT login email** | A **Viewer** user in your Cloud stack (e.g. `signage@yourdomain.com`) |
| **JWKS public URL** | Optional — defaults to `https://<your-signage-host>/grafana-jwks.php` |

**Save** → **Test JWT signing** — should report JWKS URL ready.

Verify JWKS is reachable from the internet (Cloud must fetch it):

```bash
curl -sS https://your-signage-host/grafana-jwks.php | jq .
```

You should see an RSA key with matching `kid`.

### 3. Open a Grafana Cloud support ticket

Ask Grafana Labs to enable **authenticated dashboard embedding** for your stack. Provide:

| Item | Example |
|------|---------|
| Stack URL | `https://yourorg.grafana.net` |
| JWKS URL | `https://your-signage-host/grafana-jwks.php` |
| Allowed embed origins | `https://signage.internal` or your player URL(s) — HTTPS origins for `frame-ancestors` |
| Viewer identity | Email claim must match your **JWT login email** |
| Token delivery | `auth_token` query parameter (`url_login`) |

Grafana Cloud configures JWT validation and **Content-Security-Policy `frame-ancestors`** on their side — you cannot do this in the UI.

Reference: [Grafana blog — embedding authenticated dashboards](https://grafana.com/blog/how-to-embed-grafana-dashboards-into-web-applications/) (May 2026 update covers Cloud enablement).

### 4. Add dashboards in signage

- **Dashboard URL** — normal private URL while logged in, e.g.  
  `https://yourorg.grafana.net/d/abc123/my-dashboard?orgId=1`
- **JWT** column — `auto` or `on`
- Rotation: `grafana.php?d=<key>`

Signage appends `kiosk`, theme, refresh, and a fresh `auth_token` automatically.

### Token lifetime

Use a **short TTL** (900–3600s) for Cloud. The board refreshes tokens before expiry via `grafana.php?api=1&d=<key>`.

---

## Option B — Public dashboards (personal / homelab Cloud)

When data is **not sensitive**:

1. In Grafana Cloud: **Share → Public dashboard** (or externally shared dashboard — contact support if the menu is missing).
2. Copy the public URL (`…/public-dashboards/…`).
3. Signage admin row:
   - Paste public URL
   - **JWT** = `off` (or leave JWT disabled globally)
4. Add to rotation.

**Caveats:**

- Anyone with the link can view the data.
- Historically Grafana Cloud sent `X-Frame-Options: deny` for many URLs; public dashboards may still be blocked in iframes depending on your Cloud plan and support settings. If the preview shows a blank frame, ask Cloud support whether iframe embedding is allowed for public dashboards on your stack.

---

## Option C — Snapshots

Good for “show this chart at a point in time” without live queries:

1. Grafana → Share → Snapshot
2. Paste snapshot URL in signage (JWT off)
3. Snapshots are public once published — treat links like public dashboards

---

## Self-hosted vs Cloud — quick comparison

| | Self-hosted (work) | Grafana Cloud (personal) |
|--|-------------------|---------------------------|
| Config | You edit `grafana.ini` | Grafana Labs support |
| JWT algorithm | **HS256** + local JWK file | **RS256** + signage `grafana-jwks.php` |
| JWKS | `jwk_set_file` on disk | `jwk_set_url` → your signage server |
| SSO | JWT runs alongside SSO | Same — JWT for players, SSO for humans |
| Public dashboards | Optional | Often easiest without support ticket |

Self-hosted JWT guide: [grafana.md](grafana.md)

---

## Diagnostics

```bash
php scripts/diagnose-grafana.php
php scripts/diagnose-grafana.php --test
php scripts/diagnose-grafana.php --test --key=homelab
```

Cloud dashboard rows show `cloud: yes` in the built URL metadata when the host ends in `.grafana.net`.

---

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Blank iframe on Cloud | Embedding not enabled for your stack/origin — support ticket |
| Login page in iframe | JWT embed not enabled on Cloud, or wrong login email |
| JWKS fetch failed | `grafana-jwks.php` not reachable from internet — fix DNS/firewall/TLS |
| Works in browser tab, not iframe | `frame-ancestors` doesn’t include signage origin — support ticket |
| Public URL still blocked | Cloud iframe policy — support; or use snapshot |

---

## Security notes

- RS256 private key lives in `settings.json` — protect filesystem access.
- `auth_token` in URLs may appear in logs — short TTL, HTTPS, dedicated Viewer user.
- Do not use public dashboards for production/security-sensitive metrics.

---

## Related

- [grafana.md](grafana.md) — self-hosted JWT (HS256)
- [boards.md → grafana.php](boards.md#grafanaphp--grafana-iframe--jwt)
