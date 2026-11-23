# Cedar Tranquility Real Estate

[![CI](https://github.com/ugodspecial/realestate/actions/workflows/ci.yml/badge.svg)](https://github.com/ugodspecial/realestate/actions/workflows/ci.yml)

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

- PHP 8.1+ with `pdo_mysql`, `mbstring`, `gd`, `fileinfo` (CI-verified on 8.1–8.5,
  plus a non-blocking nightly watch job that flags future-version breakage early)
- MySQL 5.7+ / MariaDB 10.3+ (SQLite is supported for local demos only)
- Apache/LiteSpeed with `mod_rewrite`, or any server that can route to `public/index.php`

## Quick start (local demo, including Laragon)

```bash
# 1. Create your local settings: SQLite, and email written to a log file
cp docs/config-examples/local.php config.local.php        # Windows: copy docs\config-examples\local.php config.local.php

# 2. Install the database with realistic demo content
php bin/install.php --demo

# 3. Serve and open http://127.0.0.1:8080
php -S 127.0.0.1:8080 -t public public/router.php
```

Demo staff credentials are printed once and saved to `storage/demo-credentials.json`
(chmod 0600, never deployed). Sign in at `/login`.

> The installer never overwrites existing users or content — re-running it is safe.

**Email on your own computer.** Nothing is sent from the setup above: each message the
site would send (an enquiry alert, a password-reset link) is written to
`storage/logs/mail.log`, where you can read it and open the link. Check it with
`php bin/mail-test.php you@example.com`. In Laragon, keep the folder under
`C:\laragon\www`, run the same three steps in its terminal, and open the address in step 3 (or
the `.test` address Laragon makes for the folder, if it shows the site). To see mail arrive in a
real inbox screen, add a local mail catcher such as Mailpit — [docs/EMAIL.md](docs/EMAIL.md)
has the three lines, and covers Laragon's own Mail Catcher too.

## Deploying to cPanel / production

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for the full guide: MySQL setup,
installer usage (`php bin/install.php --email …` with `CEDAR_ADMIN_PASSWORD`),
document-root configuration, permissions, cron-free operation, and migration checklist.

**Email on a live site** needs one decision and one check. Use a real mailbox on your
domain over SMTP (`docs/config-examples/live.php` shows the settings, which work on any
hosting plan because they live in a plain file), then run
`php bin/mail-test.php you@your-domain.com`, or press **Settings → Send a test email**.
**[docs/EMAIL.md](docs/EMAIL.md)** covers cPanel, a VPS, Gmail and the common mail services,
the spam folder, and what every failure message means.

**Microsoft 365 mailboxes** are moving to OAuth-only sign-in for SMTP (no more mailbox
password). The website supports that: it can sign in to Microsoft as an app, or as a person
who connects once from **Settings**, and no password is stored. The steps, and what has and
has not been verified, are in [docs/EMAIL.md](docs/EMAIL.md#microsoft-365-mail-that-needs-oauth);
`php bin/mail-oauth.php` connects from a terminal.

## Upgrading

Existing test and production sites upgrade with `php bin/upgrade.php`, which
backs up the database and applies pending versioned migrations. See
**[docs/UPGRADING.md](docs/UPGRADING.md)** for the runbook, rollback steps,
and how to author a migration. Every release notes its changes in
[CHANGELOG.md](CHANGELOG.md).

## Project structure

```
app/
  bootstrap.php        Sessions, security headers, autoloader, error handling
  config.php           Production defaults (env-overridable) + config.local.php merge
  routes.php           Front-controller routing table
  Core/                Database, Auth (+RBAC), RateLimiter, Audit, Properties,
                       Enquiries, Uploads, Mailer (+SmtpClient, MailOAuth, HttpClient), PublicController, AdminController
  views/               Public templates, reusable components, auth, admin dashboard
bin/                   CLI installer (schema + seed + admin) and upgrader (backup + migrations)
database/              Portable MySQL/SQLite schema, versioned migrations, demo seed
public/                Web root: index.php, router.php, assets, uploads
storage/               SQLite demo DB, sessions, logs, backups (never web-accessible)
docs/                  Deployment, email, upgrading, and operations guides;
                       config-examples/ (copy one to config.local.php)
tests/                 CLI smoke, HTTP, and migration self-tests
```

## Security notes

- All queries use prepared statements; all output is escaped; uploads are re-encoded
  through GD (never stored as received) with strict MIME/size/dimension checks.
- Sessions are strict, `HttpOnly`, `SameSite=Lax`, idle-expired, and remotely
  revocable via per-user session versions. Failed logins are throttled per account+IP.
- Content-Security-Policy and other hardening headers are set in `app/bootstrap.php`.
- `config.local.php` holds your passwords: keep it out of git (it is ignored) and outside
  the folder the web serves. Never deploy `storage/*.sqlite` or `storage/demo-credentials.json`.

## License & attribution

Website code and original content: © Cedar Tranquility Real Estate.
Fonts (Rajdhani, Roboto) are licensed under the SIL Open Font License 1.1 — see
`public/assets/fonts/`. No reference-site branding or copyrighted content is included.
