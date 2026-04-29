# Texas Mountaineers Member Portal
Self-hosted WordPress + Paid Memberships Pro stack with a custom `tm-member-portal` plugin that adds:
- Annual individual ($30) / couple ($40) memberships, recurring.
- Class registrations (Beginner $95 / $175 couples, Sport $60, Intro to Trad $80, free Anchor) with class+membership bundles.
- Waitlists with auto-promotion when seats open, and the promoted student is notified by email.
- Liability waiver e-signature, captured per membership year.
- Internal certifications: Beginner / Anchor / Sport / Intermediate / Assisted-* / Qualified Trad Leader / First Aid / Instructor.
- Gear-loan check-in/check-out for the Equipment Coordinator.
- Mass email to a class roster (confirmed only / waitlist only / both) with merge tags and an audit log.
- Self-service member dashboard: status, renewal date, waiver status, certifications, classes (with waitlist position), gear loans, renew/cancel buttons.
- Member directory (members-only, gated on a current-year waiver).
- Discord invite hand-off on activation.
- Roles for Eboard, Training Coordinator, Equipment Coordinator, Treasurer, Member, Pending Member.
- REST API at `/wp-json/tm/v1/*` for the member's own status, roster CSV export, and waitlist promotion.
## Why WordPress (and not a SaaS)
For ≤500 members with the requested features, evaluated alternatives all cost more:
- Self-hosted WordPress + PMPro (free) on $5–10/mo VPS: ~$60–$150/yr.
- Wild Apricot 500-contact: ~$960–$1,200/yr.
- ClubExpress: ~$2,000–$2,800/yr.
- Raklet: ~$700–$900/yr.
- MembershipWorks Plus: ~$420/yr.
- Outseta Pro w/ events: ~$870/yr.
- Memberstack + Webflow: ~$494/yr + admin UI build cost.
WordPress wins on cost (3–10× cheaper), keeps the existing site content in place, and the WP admin + PMPro UI is acceptable for a volunteer eboard. Re-evaluate annually.
## Repository layout
- `docker-compose.yml` — Nginx + PHP-FPM 8.2 + MariaDB 10.11 + Redis + WP-CLI + backup.
- `nginx/default.conf` — vhost with security headers, login + xmlrpc rate limits, blocked user-enum endpoint.
- `php/Dockerfile` + `php.ini` + `uploads.ini` — extends the official `wordpress:6.7-php8.2-fpm` image with `redis`, `imagick`, OPcache JIT, and WP-CLI.
- `scripts/init-wp.sh` — idempotent bootstrap (installs core, plugins, theme, seeds PMPro levels and pages).
- `scripts/backup.sh` — nightly `wp db export` + uploads tarball with rotation.
- `wp-content/mu-plugins/tm-defaults.php` — locked-in defaults (no file editor, no pingback, etc.).
- `wp-content/plugins/tm-member-portal/` — the custom plugin.
- `wp-content/themes/tm-portal/` — Astra child theme with TM colors.
## Quickstart (local dev)
Prereqs: Docker Desktop or Docker Engine + Compose v2.
```
cp .env.example .env
# Edit .env: set ADMIN_PASSWORD, DB_PASSWORD, DB_ROOT_PASSWORD at minimum.
docker compose up -d --build
docker compose --profile tools run --rm \
    -e SITE_URL -e SITE_TITLE -e ADMIN_USER -e ADMIN_EMAIL -e ADMIN_PASSWORD \
    -e SMTP_HOST -e SMTP_PORT -e SMTP_USER -e SMTP_PASSWORD -e SMTP_SECURE \
    -e MAIL_FROM -e MAIL_FROM_NAME \
    --env-file .env wpcli /scripts/init-wp.sh
```
Then visit `http://localhost:8080/` and log in at `/wp-admin` with the admin credentials from `.env`.
## Backups
```
docker compose --profile tools run --rm --env-file .env backup
```
Output goes to `./backups/`. Schedule it via `cron` on the host:
```
0 2 * * *  cd /opt/tm-portal && docker compose --profile tools run --rm --env-file .env backup >> /var/log/tm-backup.log 2>&1
```
Restore (replace the volume contents):
```
gunzip -c backups/db-20260101-020000.sql.gz | docker compose exec -T db \
    mariadb -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"
tar -xzf backups/uploads-20260101-020000.tar.gz -C ./wp-content-restore/
```
## Production cutover (high level)
1. Provision a $5–10/mo VPS (e.g. Hetzner CX22, DigitalOcean Basic) with Docker.
2. Point a DNS A record (e.g. `portal.texasmountaineers.org`) at the VPS.
3. `git clone` this repo, copy `.env.example` to `.env`, set `SITE_URL=https://portal.texasmountaineers.org`, `FORCE_SSL_ADMIN=true`, fill in SMTP + Stripe + PayPal + Discord webhook.
4. Front the stack with Caddy or Traefik to terminate TLS via Let's Encrypt (or run `certbot` in a sidecar). Bind it to `nginx:80`.
5. `docker compose up -d --build` and run the init script as in Quickstart.
6. In wp-admin, finish PMPro gateway setup (Stripe/PayPal keys), create the first class as a Training Coordinator, and import existing members via WP-CLI:
   ```
   docker compose --profile tools run --rm wpcli user import-csv /scripts/members.csv --send-email=false
   ```
7. (Optional) Migrate posts/pages from the live site by exporting a WordPress XML and `wp import` inside the wpcli container.
## How the eboard uses the portal
- **Members** sign up via `/membership/`, sign the waiver at `/waiver/`, and self-serve at `/member-dashboard/` (status, certs, classes, waitlist position, gear on loan).
- **Training Coordinator** schedules a class (Classes → Add New, set capacity, prereq certs, date), grants certifications (Users → TM Certifications), and emails the roster (Classes → Email Class).
- **Equipment Coordinator** records gear loans (Gear Loans menu).
- **Treasurer** exports the member roster from PMPro Memberships → Members.
- **Eboard** has all of the above plus content editing.
## Out of scope
- Migrating existing posts/pages from the live site (`wp import` step left to the webmaster).
- DNS cutover and TLS issuance on the production host.
- PayPal/Stripe live keys (placeholders only).
## Security
- Nginx rate-limits `/wp-login.php` to 10 req/min per IP and `/xmlrpc.php` to 2 req/min.
- `/wp-json/wp/v2/users` returns 401 to anonymous traffic (blocks user-enumeration).
- `DISALLOW_FILE_EDIT` set in `wp-config` (no in-admin file editor).
- WP-2FA + Wordfence installed and activated by `init-wp.sh`.
- Security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) set globally.
- Pingback XML-RPC method removed (DDoS amplification surface).
## Contributing
- Code style: WordPress PHP coding standards. Use single quotes unless interpolating; escape on output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- All DB writes through `$wpdb->prepare()` / `$wpdb->insert()` / `$wpdb->update()` / `$wpdb->replace()`.
- All admin-post handlers must call `check_admin_referer()` and `current_user_can()`.
