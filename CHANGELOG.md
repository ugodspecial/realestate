# Changelog

## 1.4.5 — 2026-11-03

Made a half-finished upgrade impossible to miss.

**Reported symptom.** After running `php bin/upgrade.php` the output ended at
`Migrations applied: 2; pending: 1` and the first-login password change did not
happen. The migration had in fact never been applied — the backup step had
failed, and `bin/upgrade.php` printed its reason to STDERR only. Copied to a
bug report, the visible output was a progress line with no error and no
completion.

**Fixed:**

- Every upgrade failure is now printed to STDOUT as well as STDERR, ending with
  `UPGRADE FAILED: …` and an explicit "Nothing was applied" line.
- The backup failure now says what to do: fix permissions, use
  `--backup-dir=<writable path>`, or `--no-backup` if you have your own backup.
- New `php bin/status.php` reports the schema version, pending migrations and
  whether the first-login password feature is active. Exit code 1 when
  something is pending, so it can gate a deploy.
- The admin area shows a dismissible banner on every page while migrations are
  pending, naming the command to run. Previously the state was silent: the site
  worked, the new features were simply off.

`tests/password-reset.php` now checks that a failed backup reports itself,
applies nothing, and that both commands behave on a healthy database.

## 1.4.4 — 2026-11-03

Fixed a 500 error on sign-in for any installation that had updated the code
but not the database.

**What happened.** `Auth::user()` selected `must_change_password`
unconditionally. That column arrives with migration 003, so on a database where
`php bin/upgrade.php` had not yet run, every sign-in succeeded and then
immediately failed on the next request:

    SQLSTATE[HY000]: no such column: must_change_password

The user saw "500 — LET'S TRY THAT AGAIN" with no explanation.

**Why it shipped.** I tested the upgrade path by running the migrator, but
never by running the application against a database that had not been upgraded.
The tests all began with a migrated schema, so the gap was invisible to them.

**Fixed at three levels:**

- `Auth` asks the schema before using the column and falls back to reading the
  flag as "no change pending", so an un-upgraded database keeps working exactly
  as it did before this feature existed.
- Every write that sets the flag goes through one guard that drops it when the
  column is absent, so creating staff and resetting passwords also keep working.
- A missing column now produces a 503 that names the fix — run
  `php bin/upgrade.php` — instead of an opaque 500.

`tests/password-reset.php` now boots the real `Auth` in a separate process
against a database genuinely built without the column, and checks that signing
in, loading the session and saving a user all work with nothing written to the
error log. Against the previous code that suite aborts with exit code 1, which
is precisely the failure users were hitting.

If you are upgrading: `php bin/upgrade.php` is still the right thing to run. It
is now not fatal to forget it.

## 1.4.3 — 2026-11-03

Password lifecycle: staff choose their own password at first sign-in, and can
recover an account they are locked out of.

**First sign-in.** A password typed by an administrator — whether on the create
form, the edit form, or via **Reset password** — is now temporary. The account
is flagged, and every admin page redirects to a change screen until the person
chooses a password of their own. The issued password cannot be reused for this.
This matters more than it looks: previously an administrator-chosen password
could quietly remain the account's permanent one, so the administrator kept
knowing a working credential for someone else's account, indefinitely.

**Forgotten password.** **Forgot your password?** on the sign-in page emails a
single-use link that expires after 60 minutes. The form answers identically
whether or not the address has an account, so it cannot be used to discover who
works here. Only a hash of the token is stored, a new request invalidates any
earlier link, and completing a reset signs the account out everywhere.

**Also fixed while in here:**

- `password_resets` existed in the schema but nothing used it; it now records
  when a token was issued and spent, so a link is provably single-use.
- Resetting a password did not bump `session_version` in one path, which would
  have left an existing session alive after a reset.
- The four signed-out pages now share one shell instead of duplicating the
  page markup, so they cannot drift apart.
- On mobile the auth panel stacked its contents with no gap inside a fixed
  min-height, so the eyebrow overlapped the wordmark.
- The new recovery link was a 16px-tall tap target on a phone.
- The forced-change screen styled its sign-out button with `.text-link`, which
  renders as an uppercase display-font CTA.

Migration 003 adds `users.must_change_password` and the reset bookkeeping
columns. Existing accounts are **not** forced to change their password on
upgrade — only newly issued ones are.

`php tests/password-reset.php` covers the above and runs in CI.

## 1.3.3 — 2026-11-03

Finished the session-rotation fix from 1.3.2.

The 1.3.2 change only guarded the "no active session" case and left a second
one open: regenerating the session id after the response has started also
raises a PHP warning ("after headers have already been sent"). That state is
easy to reach — any script that prints before signing a user in. Rotation now
checks both conditions and skips silently when either applies.

Reproduced the original CI failure exactly before fixing it: the three
warnings in the log pointed at `Auth.php` lines 32 and 42, which are the
sign-in and sign-out call sites. Both are now behind the guarded helper.

`tests/accounts.php` gained six checks that call the helper directly in every
state — no session, headers already sent, and a clean active session — and
fails if either guard is removed. Verified by reverting to both the original
code and the incomplete 1.3.2 fix: each is caught.

## 1.3.2 — 2026-11-03

Fixed a PHP warning that surfaced when signing in or out without an active
session.

`Auth` called `session_regenerate_id()` unconditionally. On a normal web
request there is always a session, so this never showed up in use — but any
context without one (a CLI script, or a host that disables sessions) logged
the warning, and the deployment treats warnings as failures. Session
rotation now only happens when a session is actually active.

Caught by the new `tests/accounts.php` the moment it was added to CI, which
is exactly why it is there.

## 1.3.1 — 2026-11-03

Closed two gaps in staff account handling, found while auditing what happens
when someone leaves.

Account deactivation already existed and worked: an inactive account cannot
sign in, and deactivating someone bumps their session version so any tab they
still have open is signed out on the next request. What was missing were the
guards around it.

- **The last active super administrator can no longer be removed.** Before
  this, they could be demoted or deactivated like anyone else. With no super
  administrator left, nobody can reach Settings or Staff & users again, and
  recovery needs direct database access. The interface now refuses it and
  explains why.
- **Administrators can no longer grant the super administrator role.** The
  role dropdown offered it to anyone who could manage staff, so an
  administrator could escalate their own access. Both the create and edit
  paths now refuse it unless the signed-in user is already a super
  administrator.
- The server-side checks cover the case where the form is posted directly,
  not just what the dropdown offers.

Added `tests/accounts.php` to CI (10 checks): an active account signs in, a
deactivated one is refused, an existing session is revoked immediately, a
reactivated account works again, and both guards hold. A browser suite also
exercises the whole flow through the real interface at 13 checks, including
signing in as a deactivated user and confirming the open tab is locked out.

## 1.3.0 — 2026-11-03

The enquiry confirmation is now a proper dialog.

Previously a successful enquiry came back as a green banner at the top of
the page. The reference in that message is something a visitor may need to
keep, so the confirmation now opens as a modal that is impossible to miss.

- It fades in and fades out rather than snapping into place.
- It closes four ways: the **Okay** button, the **X** at the top right,
  **Escape**, or a click on the backdrop. Clicking inside the panel keeps it
  open.
- The reference is pulled out of the sentence and shown in its own framed
  row, labelled "Your reference", so it can be read and copied at a glance.
- On phones and small tablets it reflows into a bottom sheet, respecting the
  device safe area; from 641px up it is a centred card.
- Accessibility: it is a labelled, described `role="dialog"` with
  `aria-modal`; focus moves into it on open and is trapped there while it is
  open; the page behind is scroll-locked; the X has an accessible name and
  both buttons meet the 44px tap-target floor; the animation is skipped for
  visitors who prefer reduced motion.
- Every other message (sign-in, sign-out, validation errors) still appears
  as a banner, unchanged.

Refactored the enquiry flash into its own `enquiry` type so the layout can
route it to the dialog and leave other messages alone. Added 22 static checks
to the CI regression suite; the full browser suite covers all four close
paths and seven breakpoints (70 checks).

## 1.2.0 — 2026-11-03

Four fixes, and the site is now ready to be listed on search engines.

- The "Illustrative client stories for this demonstration website." line
  under the testimonials only appears in demo mode. A live site no longer
  carries demonstration wording.
- On phones the phone number in "Give us a call" ran on from its label on
  the same line. A tap-target rule was also matching the wrapping element
  and overriding the block layout, so the number could not sit under the
  label. That rule now only targets inline links, and the label/value pair
  is laid out as a column, so the number always stacks.
- The location section reserved two fixed 220px rows whether or not there
  were cards to fill them, leaving hundreds of pixels of empty background
  on desktop. Rows now come from the cards that actually exist, and the
  whole section is skipped when there are no locations at all.
- Search engine visibility:
  - Live sites publish `index,follow` plus a canonical URL; the demo keeps
    `noindex,nofollow` so an unfinished copy never gets indexed.
  - Every page now carries Open Graph and Twitter card metadata, including
    an image and the page URL.
  - Business structured data (`RealEstateAgent`) is emitted site-wide with
    the phone number, address, opening hours and social profiles; property
    pages add their listing schema alongside it.
  - The sitemap now refuses to publish relative locations when no domain is
    configured, adds the terms page and a last-modified date per property.
  - Google Search Console and Bing verification codes can be set from
    Settings → Search engines, without touching code.

`tests/seo.php` (47 checks) is wired into CI and covers all of the above.

## 1.1.0 — 2026-11-03

Spacing on the about page, and proper contact icons in the footer.

- On the about page, "How we can help" sat directly against the paragraph
  above it: the button's top edge touched the last line of text, with no
  space between them at any width. Supporting copy now always carries its
  own space before a button or a text link, 32px on desktop and 26px on
  phones. The same rule covers the equivalent pairing on the home page.
- The GET IN TOUCH column in the footer now leads each detail with its own
  icon - an envelope for the email address, a handset for the phone number
  and a map pin for the office address. The address is a map link and is
  still driven by the admin office-address setting.
- The bare "Lagos, Nigeria" line that sat under the footer brand logo has
  been removed, so the address now appears in exactly one place: GET IN
  TOUCH in the footer.

Also fixed a stylesheet bug found while verifying this change: an earlier
edit had left an unterminated comment, which silently discarded the
trust-strip responsive rules and brought back the home-page overflow at
320-411px. The comment is repaired, and the regression suite now fails if
the stylesheet ever carries an unbalanced comment or orphaned rule text.

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
