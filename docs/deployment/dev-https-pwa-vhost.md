# Dev HTTPS PWA Vhost Verification

This checklist is for the temporary or permanent Dev virtual host used to verify GitHub Discussion
number 169 and Web Push before production rollout. Browser PWA, Service Worker, and Web Push
behavior requires a real trusted HTTPS origin; the old self-signed `nexum-psa.local` certificate is
not enough for final browser verification.

## Vhost Requirements

- Point the DNS name to the Dev server and serve Nexum from `/var/Projects/tdPSA/public`.
- Use a valid certificate chain for the exact host name, including Subject Alternative Name coverage.
- Serve the canonical app URL over HTTPS with no mixed-content asset warnings.
- Keep HTTP redirected to HTTPS.
- Use the intended PHP runtime and the existing Dev working copy.
- Keep `storage/` and `bootstrap/cache/` writable by the web/PHP-FPM user group.
- Do not expose `.env`, repository files, storage internals, or backup files through the vhost.

## Laravel Environment

After the vhost is created, align the environment with the HTTPS origin:

```dotenv
APP_URL=https://dev.example.invalid
SESSION_SECURE_COOKIE=true
```

If the host is used for authenticated API or Sanctum flows, make sure the relevant stateful-domain
setting includes the new host. Do not print secrets while checking this.

Run:

```bash
php artisan optimize:clear
php artisan queue:restart
```

## HTTP Checks

Use the new HTTPS origin:

```bash
curl -I https://dev.example.invalid/
curl -I https://dev.example.invalid/sw.js
curl -I https://dev.example.invalid/manifest.json
curl -I https://dev.example.invalid/offline.html
```

Expected results:

- `/` returns a normal application response or the expected login redirect.
- `/sw.js` returns HTTP 200 without authentication and a JavaScript content type.
- `/manifest.json` returns HTTP 200 without authentication and a manifest JSON content type.
- `/offline.html` returns HTTP 200 without authentication.
- HTTPS responses include the configured security headers.

## Browser Checks

Run the human review checks in:

- `HR-2026-08-11-003` for the full one-responsive-PWA Discussion #169 acceptance.
- `HR-2026-07-24-001` for Web Push device lifecycle and Service Worker registration.
- `HR-2026-08-11-002` for inbound Email/customer-reply Web Push delivery and read-sync.

Do not mark Discussion #169 complete until the relevant checks are explicitly reviewed by a named
human reviewer. Automated tests prove the code contracts; they do not prove browser installation,
push permission, installed PWA, or mobile viewport behavior.
