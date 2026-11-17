# Changelog

## 1.0.9 — 2026-11-03

Responsive pass across every public page.

Audited every public page (home, listings, property, about, services,
contact, privacy, terms, sign-in) at nine viewport widths from 320px to
1600px, and fixed each layout that broke.

- The trust strip on the home page held four reassurance points on one
  unbroken row, so on a phone the last two were pushed past the edge of the
  screen and could never be read. It now folds into two columns on tablets
  and a single column on phones.
- The results toolbar on the listings page could not fit the result count,
  the sort control and the grid/list toggle on one line; the toggle was
  pushed off the right edge. It now wraps, and the toggle stays on screen
  down to 320px.
- The "looking for something specific" callout at the foot of the listings
  page stacked its text and its link on one row, so the link ran off the
  edge. It now stacks on phones.
- The floating call and back-to-top buttons sat over the closing copyright
  line. On small screens the footer reserves their height; on wide screens
  the copyright is indented clear of their column. The copyright is now
  fully legible as the last thing in the footer at every width.
- Small labels that were rendering at 7-9px - photo captions, testimonial
  attributions, badge text, property references, demo labels and the brand
  line - now stop at a 10px floor.

Two guards were added so these cannot silently return:

- `tests/responsive-regression.php` pins the consent control, the footer
  copyright order, the text floor and the narrow-screen folding rules, and
  runs in CI.
- The site audit now additionally asserts that the floating buttons never
  cover the copyright line and that the copyright is both the last element
  in the footer and the last one drawn.

## 1.0.8 — 2026-11-07

Stop browsers from serving a stale stylesheet.

- Stylesheets and scripts now carry the application version in their URL,
  and static assets send cache headers, so a browser can no longer keep
  serving an old copy of the stylesheet after a deployment. The old copy is
  what made the header show a close icon that should not have been there
  and the mobile menu behave as it did before the earlier fixes.
- Consolidated the navigation styles. Three separate blocks had built up
  over successive fixes and were overriding each other; there is now one
  rule for the desktop row of links and one for the mobile panel, and the
  close button belongs to the mobile panel alone.

## 1.0.7 — 2026-11-07

Close icon on the mobile menu.

- The mobile menu showed two close icons at once: the one in the header
  toggle and the one in the panel. The panel is a full-screen overlay that
  covers the header, so it carries the only close button that is on
  screen while the menu is open, and the header toggle is hidden. With the
  menu closed the header shows the hamburger alone.

## 1.0.6 — 2026-11-07

Fix the mobile navigation panel.

- The mobile menu panel no longer collapses to a short strip with its links
  spilling out over the page. The glass effect on the header was the
  cause: an element with a backdrop-filter becomes the containing block
  for the position:fixed descendants inside it, so the panel was measured
  against the header instead of the viewport. The blur is now drawn by a
  pseudo-element of the header bar, which leaves the panel free to fill
  the screen.
- The panel now carries its own close button, so the menu can always be
  dismissed, even though the panel covers the header toggle.
- The dimmed overlay now sits beside the header instead of inside it, so
  it can never paint over the menu panel.

## 1.0.5 — 2026-11-07

Sticky navigation on every device, with a glass header.

- The website header now stays pinned to the top while the page scrolls, on
  phones, tablets, and desktops, and carries a frosted-glass finish so the
  content scrolling underneath stays legible. Browsers without
  backdrop-filter support get a solid bar instead.
- The admin navigation now stays visible while the page scrolls. On a phone
  it remains a drawer opened from the menu bar, which is itself pinned to
  the top; from tablet width up it is a column beside the content that
  stays in place.
- Fixed the cause of the menus not sticking: the page was clipping its
  horizontal overflow with `overflow-x: hidden` on the body, and a hidden
  overflow turns the body into the scroll container for sticky
  descendants, so nothing could stick. The clip now uses
  `overflow-x: clip`, which contains the overflow without creating a
  scroll container.

## 1.0.4 — 2026-11-06

Services page call-to-action and editable opening hours.

- Removed the closing call-to-action from the services page. It duplicated
  the one already shown in the footer, so the page now carries a single
  "Start a conversation" button, in the footer.
- Opening hours can now be edited from Website settings in the admin, next
  to the office address. Each line is saved as a separate entry and is
  shown as "When we're here" on the contact page. Leaving the field empty
  hides the opening hours.

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
