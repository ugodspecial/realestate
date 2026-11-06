# Changelog

## 1.0.1 — 2026-11-06

Branding fixes.

- The website name is now editable from `Admin → Settings`. Previously the
  form saved a `site_name` key while the site read `brand_name`, so changes
  never appeared, and the header/footer rendered a hardcoded
  "CEDAR TRANQUILITY" wordmark.
- Added brand logo upload (SVG, PNG, JPG, or WebP, max 2 MB) with a
  preview and a remove option. Uploaded logos replace the default mark in
  the header, footer, staff sign-in page, and admin sidebar. SVG uploads are
  sanitised (scripts, event handlers, and external references stripped);
  raster uploads are re-encoded to WebP.
- Added a `brand_tagline` setting for the small line under the wordmark.
- Migration `002_brand_settings` unifies the brand name under `brand_name`
  and seeds the new settings keys.
- Brand name now drives page titles, meta tags, error pages, the WhatsApp
  link, and enquiry notifications.

## 1.0.0 — 2026-11-05

Baseline release.

- Public site (home, listings, property pages, services, about, contact),
  staff admin (properties, enquiries, taxonomy, testimonials, users,
  settings, audit), and installer with demo seed.
- Upgrade system: versioned migrations in `database/migrations/`,
  `bin/upgrade.php` with automatic pre-upgrade backups, `docs/UPGRADING.md`
  runbook, and CI coverage of the install → upgrade → legacy-adopt path.
