# Changelog

## 1.0.3 — 2026-11-06

Mobile menu icons and admin navigation drawer.

- Fixed the close (×) icon showing next to the hamburger on the public site
  when the menu was closed. The icon swap relied on the CSS `:has()`
  selector, which older browsers do not support; the state is now driven by
  a `nav-open` class the script sets on `<html>`, so it works everywhere.
- The admin sidebar no longer covers the page content on phones. It is now a
  slide-in drawer opened from a compact "Menu" bar at the top of the page,
  with a close button, a dimmed overlay, and a keyboard-dismissible state.
  The admin content is visible immediately instead of below a full-screen
  menu.

## 1.0.2 — 2026-11-06

Mobile navigation and responsive fixes.

- Fixed the mobile menu not opening. The off-canvas panel was positioned
  with `inset:0 0 0 auto` plus `translateX(100%)`, which pushed it past the
  viewport edge; the links landed outside the visible area and the page
  gained a horizontal scrollbar. The panel is now anchored to the viewport
  edge and the document clips horizontal overflow.
- Fixed the menu being unclosable. The open panel covered the hamburger, so
  the same button that opened the menu could not close it. The toggle now
  sits above the panel, shows a close icon while the menu is open, and
  keeps its accessible label in sync.
- Moved the nav overlay inside the header so it shares a stacking context
  with the panel; previously it painted over the menu links.
- Opening the menu no longer jumps the page to the top. The body is frozen
  in place and its scroll offset is restored on close.
- The menu closes when a destination is chosen and when the viewport grows
  past the mobile breakpoint.
- Responsive layout fixes across phone and tablet widths: no horizontal
  overflow on any page, tap targets raised to a comfortable size, grids
  collapsing to one column, admin tables scrolling inside their card
  instead of stretching the page, and admin headers stacking so no action
  button is pushed out of view.
- Fixed inline `grid-template-columns` overrides on the Categories and
  Testimonials admin pages that prevented them from collapsing.

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
