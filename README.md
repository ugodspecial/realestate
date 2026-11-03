# Cedar Tranquility Real Estate

A complete, production-ready premium real estate website — public property portal plus a
secure no-code administration dashboard. Built with dependency-free PHP 8.1+ and
MySQL/MariaDB for maximum compatibility with cPanel shared hosting, LiteSpeed, Apache,
VPS, and dedicated servers.

**Brand:** Cedar Tranquility Real Estate. Typography follows the WPResidence Detroit
reference (Rajdhani display + Roboto body, self-hosted); all branding, layout, copy, and
imagery are original.

## Features

**Public website**
- Premium hero with tabbed property search (sale / rent), filters, and popular searches
- Featured properties, latest listings, categories, featured locations, testimonials
- About, Services, Contact pages with corporate presentation sections
- Property listings with advanced filtering, sorting, grid/list views, pagination
- Property detail pages with galleries, specifications, amenities, enquiry forms, WhatsApp
- SEO: semantic HTML, meta/OG tags, canonical URLs, JSON-LD listings, sitemap, robots
- Fully responsive, dependency-free CSS/JS, self-hosted fonts, lazy-loaded images

**Administration** (`/login` → `/admin`)
- Role-based access: Super Administrator, Administrator, Listing Manager, Content Editor
- Properties: add/edit/delete, approve/publish workflow, featured flags, sold/rented status
- Photo manager: drag-and-drop upload, automatic WebP conversion, cover photos, captions
- Enquiries: status tracking, internal notes, reply-by-email/WhatsApp shortcuts
- Categories, locations, testimonials, staff users, website settings, full activity log
- Hardened: CSRF tokens, strict sessions with idle expiry, login throttling, audit trail

**Prepared for phase two** (dormant, not launched): database tables and an admin roadmap
page ready for a future agent marketplace (registration, subscriptions, Paystack/Flutterwave).

## Requirements

- PHP 8.1+ with `pdo_mysql`, `mbstring`, `gd`, `fileinfo`
- MySQL 5.7+ / MariaDB 10.3+ (SQLite is supported for local demos only)
- Apache/LiteSpeed with `mod_rewrite`, or any server that can route to `public/index.php`

## Quick start (local demo)

```bash
# 1. Copy the example config and point it at SQLite
cp config.local.php config.local.php   # already SQLite-ready for demos

# 2. Install the database with realistic demo content
php bin/install.php --demo

# 3. Serve and open http://127.0.0.1:8080
php -S 127.0.0.1:8080 -t public public/router.php
```

Demo staff credentials are printed once and saved to `storage/demo-credentials.json`
(chmod 0600, never deployed). Sign in at `/login`.

> The installer never overwrites existing users or content — re-running it is safe.

## Deploying to cPanel / production

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for the full guide: MySQL setup,
installer usage (`php bin/install.php --email …` with `CEDAR_ADMIN_PASSWORD`),
document-root configuration, permissions, cron-free operation, and migration checklist.

## Project structure

```
app/
  bootstrap.php        Sessions, security headers, autoloader, error handling
  config.php           Production defaults (env-overridable) + config.local.php merge
  routes.php           Front-controller routing table
  Core/                Database, Auth (+RBAC), RateLimiter, Audit, Properties,
                       Enquiries, Uploads, Mailer, PublicController, AdminController
  views/               Public templates, reusable components, auth, admin dashboard
bin/install.php        CLI installer (schema + seed + admin user)
database/              Portable MySQL/SQLite schema and demo seed data
public/                Web root: index.php, router.php, assets, uploads
storage/               SQLite demo DB, sessions, logs (never web-accessible)
docs/                  Deployment and operations guides
tests/                 CLI smoke test
```

## Security notes

- All queries use prepared statements; all output is escaped; uploads are re-encoded
  through GD (never stored as received) with strict MIME/size/dimension checks.
- Sessions are strict, `HttpOnly`, `SameSite=Lax`, idle-expired, and remotely
  revocable via per-user session versions. Failed logins are throttled per account+IP.
- Content-Security-Policy and other hardening headers are set in `app/bootstrap.php`.
- Never deploy `config.local.php`, `storage/*.sqlite`, or `storage/demo-credentials.json`.

## License & attribution

Website code and original content: © Cedar Tranquility Real Estate.
Fonts (Rajdhani, Roboto) are licensed under the SIL Open Font License 1.1 — see
`public/assets/fonts/`. No reference-site branding or copyrighted content is included.
