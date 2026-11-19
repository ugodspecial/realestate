# Changelog

## 1.8.2 — 2026-11-03

Four corrections from review.

**About page.** The two body paragraphs (`about_body`) are removed, so the panel
now runs eyebrow, title, one-line summary, link — the composition in the review
screenshot. The `about_body` setting is untouched; the home page still draws its
first paragraph from it.

**Home about image.** Reported as no longer visible while the About page showed
the same file. The difference was the `mask` reveal on the home copy: it starts
fully clipped (`clip-path:inset(0 0 100% 0)`) and only `.is-in` clears it, but the
shared reveal transition listed opacity, transform and filter — not clip-path —
while `will-change` promoted the element to its own composited layer. Clipping a
promoted layer under an `overflow:clip` ancestor is the combination that can leave
an element painted clipped. `clip-path` now joins the transition, the mask clears
its clip on `.is-in` with `!important`, and a JS safety net reveals any element
still hidden shortly after load. A reveal is an enhancement, never a precondition
for visibility.

**Category strip.** The five property types had been split by the previous grid
ladder into four columns, so the fifth wrapped alone. They are one row of five
again from 1024px up. Below that the arithmetic sets the ladder: the longest
label, APARTMENTS, advances 5.406em, which leaves 158px of text and 28.7px type
at 1024 but only 46px of text and 8.4px type at 360. Five across is therefore
desktop only — four on tablet, three at 860, two at 640, one below 400. Padding,
tracking and type scale with the card so no label touches a tile edge at any of
the five counts; verified to fit at twelve widths from 360 to 1920.

**About figures.** The row had `padding:18px 14px 18px 0` — nothing on the left,
14px on the right — so each figure crowded the divider on its left and sat clear
of the one on its right, and the first column started flush against the copy
above. Padding is now symmetric on every cell, scaled to the viewport, with the
divider drawn as a pseudo-element inside the gap rather than as a border (a
border sits outside the box, which is what skewed the centring in the first
place). Three across on desktop, two plus a full-width third on phones.

All suites pass: smoke, responsive-regression (113), seo (48), accounts (17),
dashboard-scope (38), migrations, logo-sanitizer (28); jsdom drawer, toast,
gallery, sort, motion, reveal and a11y clean.

## 1.8.1 — 2026-11-03

Responsiveness and chrome corrections.

**Container gutters.** The container width changed in four fixed steps — 28px of
gutter at 360, 40 to 640, 56 to 1024, then 96 — so at the 1024 boundary the left
edge of every section jumped 20px right while the container simultaneously shrank
20px. Turning a tablet or dragging a window through that band shifted the whole
page sideways. One `clamp(14px,4vw,48px)` gutter replaces all four steps with no
discontinuity at any width.

**Grid ladder.** Each grid had its own column counts at its own breakpoints:
property cards went 1-2-3, but the category tiles went 1-2-3-**5** at exactly
1201px and the location mosaic sat on two columns across the whole 1025–1200 band
before doubling. Every grid now steps 1-2-3-4 on the same two boundaries (641 and
1025), so columns line up across sections and nothing doubles at a single pixel.

**Navigation.** The current page had collected three competing treatments: a pale
green rounded pill behind the label, a 2px gold bar down its left edge, and an 8px
indent on hover. All three are gone. The underline is now the only state — a 2px
rule sized exactly to the word and sitting 5px beneath it, measured against the
15px type and 33px anchor padding. The drawer keeps a hairline inset on the left
edge plus its row divider.

**Footer WhatsApp.** "Chat on WhatsApp" carried a background fill, a border and a
corner radius, making it the only boxed control in the footer and reading as a
primary button beside the real calls to action. It is now an ordinary footer link
like the phone number and address above it.

**Trust strip.** The strip scrolled itself on a 34s loop, but the markup holds one
copy of the four items rather than two, so the loop dragged them off the left edge
and left the right half blank before snapping back. It was also the one animation
on the site that conveyed nothing. Now a static wrapping grid: one column on small
phones, two to 1024, four above.

All suites pass: smoke, responsive-regression (113), seo (48), accounts (17),
dashboard-scope (38), migrations, logo-sanitizer (28); jsdom drawer, toast,
gallery, sort, motion, reveal and a11y clean.

## 1.8.0 — 2026-11-03

Third and final design pass. The first two passes built systems; this one removes
what the systems had accumulated, and fixes a hero headline that was clipping.

**What was wrong.** Successive design passes had *stacked* effects rather than
replacing them. Twenty-six hover selectors were declared two to four times with
conflicting values: `.property-card:hover` carried four different transforms
(-5px, -8px, none, -5px) and `.location-card:hover` fired five animations at
once — a lift, a shadow, a 1.06 image scale, a 6px copy slide and a 4px icon
slide. Each was defensible alone; together the eye could not tell which movement
was the point. The file also held 166 `:hover` rules, 28 transitions with no
easing curve (falling back to the browser's lifeless default `ease`), and
sixteen different section paddings.

**Removed.**

- `:hover` rules 166 → 103. Movement properties stripped from the superseded
  declarations at their original line sites, not merely overridden later — an
  appended rule renders correctly but leaves the file carrying dead weight.
- No hover rule anywhere now performs two geometric moves at once. One transform
  per element, one shadow, one direction of travel for every "go" icon (3px up
  and right, replacing twelve different offsets).
- The hero was running two competing entrance choreographies (`hero-rise`, 0.9s
  staggered, and `enter-up`, 0.62–0.78s hard-coded). The second never rendered —
  it lost the cascade — so its delays were dead code. One choreography remains.
- An infinite 34-second sheen on the services band. It could not be perceived
  during a scroll past, so it was only a repaint cost. Now a static gradient.

**Timing.** Every transition draws from four durations and two named curves.
Nothing is left on the browser default. The reveal stagger is now adaptive: a
fixed 90ms step meant a five-tile grid spent 450ms starting its last tile and a
nine-card results page would have spent 720ms. The step shrinks as the group
grows, so any row finishes within ~310ms.

**Type.** Headings were pinned to a flat 47px above 767px and 35px below — a
step, not a scale, and two headings of equal rank could differ by 9px at the same
viewport. All display headings now use one fluid ramp, verified monotonic from
320 to 2560px, with each rank holding a guaranteed floor above the rank beneath.

**Fixed: the hero headline was clipping on phones.** Read from the shipped
Rajdhani Bold file, "FIND YOUR SPACE." advances 7.318em plus 0.14em tracking per
gap: at 38px that is 358px of type against 304px of usable width on a 360px
phone. It overflowed inside the hero's `overflow:hidden` on every screen below
414px. The ramp is now derived from those measured metrics and holds ≤92% column
fill at all widths; narrow phones drop tracking rather than type size.

**Accessibility and touch.**

- Body text moved from 4.23:1 to 4.89:1 on paper (5.26:1 on white, 4.54:1 on the
  pale green wash) — it was under the 4.5:1 threshold on every surface.
- The save button (33px square) and detail-header icon buttons were below the
  44px touch minimum; both now meet it, with 18px of clearance from the badges.

**Performance.** The stylesheet's design-rationale comments cost 23 KB gzipped —
more than half of what it now weighs. They are preserved verbatim in
`docs/design-notes-css.md`; the shipped CSS keeps only section markers.

- CSS 50.7 → **32.8 KB gzipped** (222 → 167 KB raw, −37%).
- JS 7.2 → 7.4 KB gzipped (adaptive stagger).
- Home page markup unchanged at 71 KB.

**Verified.** smoke, responsive-regression (113), seo (48), accounts (17),
dashboard-scope (38), migrations, logo-sanitizer (28); jsdom suites for drawer,
toast, gallery, sort, motion, reveal and a11y all clean. Zero heading or
longest-word overflows across 8 pages × 10 viewport widths. All 9 keyframes
declared and used, no orphans in either direction; no empty rules, no unbalanced
braces.

## 1.7.0 — 2026-11-03

Second design pass. The first pass gave every section its own composition; this
one makes the details agree with each other, and fixes four features that had
never worked.

**What was still wrong.** Measured across the stylesheet: thirty-six distinct
letter-spacing values, twenty-eight line-heights, nineteen z-index values, and a
hundred and one hex colours matching no token. Nothing was wrong on its own —
a 1px tracking difference is invisible — but nothing was repeated either, and
that is what "assembled" rather than "art-directed" looks like. Three interaction
states were missing almost everywhere: only buttons had an `:active`, and no
component had a `:focus-visible`.

**Systems added.**

- A 4px-rooted spacing scale (`--space-1` … `--space-13`) plus fluid section
  rhythm (`--gutter`, `--section-y`, `--stack`), replacing ad-hoc 5/9/13/18/22/
  26/34px steps.
- A five-step tracking scale named by role, replacing 36 values with 5.
- A named layer stack (`--z-sticky` … `--z-skip`) replacing 19 loose numbers.
- A typographic rhythm: four line-height roles, heading margins that follow
  heading size, a 66ch measure for running copy, and `text-wrap:balance` on
  headings so no last line is left with one orphaned word.

**States.** Every control now has rest, hover, active and focus-visible. Presses
move 1px and drop their shadow, which is what makes a surface feel physical.
Card images scale on hover; card titles grow an underline from the left; text
links retract their rule to the right on exit so it reads as one line moving,
not two lines swapping.

**Motion.** Reveals no longer share one 0.9s duration — small content settles in
0.55s, large media takes 0.9s. Offsets shrank from 34px to 22px, which reads as a
settle rather than a slide. `will-change` is now scoped to the moving state; it
had been left on permanently, promoting every revealed element to its own
compositing layer for the life of the page. The hero arrives in reading order
with a short overlap, and the mobile drawer steps its items in.

**Bugs fixed.**

- **The save control never worked.** The markup emitted `data-save` while the
  JavaScript queried `[data-save-property]`, so no handler was ever bound.
- **The gallery never opened.** The JavaScript looked for `id="galleryDialog"`
  and found `id="gallery-dialog"`; the buttons carried `data-lightbox` where it
  needed `data-gallery-item` and `data-full`. Three mismatches in one feature.
- **There was no toast element in the DOM at all**, so every "Saved" and "Link
  copied" message the script sent was silently discarded. It now exists, is
  announced with `role="status"`, and sits above the header rather than level
  with it.
- **Sort did nothing.** The select carried `data-auto-submit` and nothing read
  it; choosing a sort order required finding the Apply button.
- The WhatsApp hover label targeted `.whatsapp-label`, a class that never
  existed; the element is a bare `<span>`.
- `.img-reveal` was fully implemented and applied to nothing. It now runs on the
  property gallery's main frame.

**Accessibility.** Fourteen muted greys introduced in the first pass measured
between 2.2:1 and 4.4:1 — below the 4.5:1 that body text needs, and in several
cases below the 3:1 even large text needs. They were all slightly different,
which is why the page read as faded rather than restrained. Three measured steps
replace them (`--ink-muted` 5.74:1, `--ink-subtle` 4.53:1, `--ink-faint` 3.90:1),
and two on the deep-green footer (6.53:1, 7.94:1). A 9px badge and a 9px
reference number were raised; 10px is now the floor for letter-spaced micro
labels. Long unbreakable tokens (the contact email) can now wrap instead of
forcing a horizontal scrollbar at 360px.

**Layout.** Four fixed gutters of 80–105px survived from the original stylesheet
and starved the copy columns; they are now fluid. The property page's sidebar
was a fixed 330px and its gallery a fixed 460px, both now proportional.

**Verified.** `smoke` 120, `responsive-regression` 113, `seo` 48,
`dashboard-scope` 38, `accounts` 17, `migrations`, `logo-sanitizer` 28 — all
pass. Every public page: one `h1`, no heading jumps, no duplicate ids, no
focusable content in `aria-hidden`, well-formed documents. Save, gallery,
toast, sort and drawer were each exercised in a real DOM and now behave.

## 1.6.0 — 2026-11-03

Visual redesign. Every public page was rebuilt in place: the markup, content,
routes, permissions and business logic are unchanged, and the stylesheet was
extended rather than replaced.

**What was wrong.** The site was correct and forgettable. Each page was the
same shape — eyebrow, heading, card grid, repeat — so nothing guided the eye or
gave a section its own identity. The hero left its right half empty on wide
screens, the about story was a wall of text, every feature was three equal
boxes, and the property page was one long undifferentiated column. There was no
motion at all, and the pages were static on arrival.

**The design system.** A fluid type scale (`--step--1` … `--step-6`) replaces
fixed sizes, so headings scale with the viewport instead of snapping at
breakpoints. Elevation is a five-step shadow scale rather than one blur, easing
is named (`--ease-out`, `--ease-in-out`, `--ease-spring`), and durations are
tokens so motion stays consistent. A grain overlay and layered gradients give
the dark surfaces depth without an image.

**Each section now has its own composition.** The featured listings open with a
large lead card ringed by two smaller ones. The about story is an editorial
split with a figures rail drawn from the live database. The values are a
hairline-separated row, not cards. Locations are a mosaic whose first tile
carries the section at twice the size. The difference section is a numbered
rail with icons that invert on hover. Testimonials lead with one large quote and
stack the rest beside it. The services band gets a vertical sheen and cards
whose left rule lights up.

**Motion.** Reveals are opt-in per element (`data-reveal="up|fade|left|right|
scale|mask"`), staggered automatically inside a `data-reveal-group`, and driven
by one shared `IntersectionObserver`. The hero adds a masked rise and a drawn
rule on arrival; images open through a mask; the hero image drifts against the
scroll (`data-parallax`); a reading-progress bar tracks the long pages; the
header turns glass once scrolled; the mobile drawer staggers its items. Every
effect is bound to a single rAF-batched scroll listener and writes only
`transform` or `opacity`.

**Accessibility.** `[data-reveal]` is hidden only under `.js`, so a visitor
without JavaScript sees the finished page. If `IntersectionObserver` is
missing, everything is revealed immediately. `prefers-reduced-motion:reduce`
zeroes every animation and transition, forces reveals visible, hides the
progress bar, and skips both parallax and tilt. Two contrast failures found by
measurement were corrected — the gold process ordinal (2.26:1 → 4.85:1) and the
contact detail lines (3.35:1 → 5.07:1). A missing `h2` on the results list
restored the heading order, and a duplicate `data-reveal` attribute was removed.

**Fixed along the way.**

- The stylesheet's later global rules were overriding the original stylesheet's
  breakpoint-specific ones, silently breaking the mobile layout. Every affected
  selector is now reconciled inside its own media query, and the mosaic,
  testimonial lead card and featured lead card each collapse properly.
- `media()` guards `matchMedia`: a partial implementation returning `undefined`
  no longer throws and takes the whole motion module down with it.

**Verification.** All six public pages render, parse as well-formed documents,
and pass structural accessibility checks (one `h1`, no heading jumps, alt text,
labelled controls, no duplicate ids, no focusable content inside `aria-hidden`).
`smoke` 120, `responsive-regression` 113, `seo` 48, `dashboard-scope` 38 all
pass. `role-matrix` and `security` fail identically on an untouched checkout —
their `shell_exec(PHP_BINARY …)` cannot spawn PHP under the wasm runtime — so
that is environmental, not a regression.

## 1.5.1 — 2026-11-03

The listing manager and the content editor were being shown the same dashboard.

**Reported.** Both roles landed on an identical page and appeared to have the
same access.

**What was actually wrong.** The permission model was already correct and the
two roles differ in every check — one holds listings and enquiries, the other
holds content and no listings at all. The dashboard simply did not consult it.
The template rendered the property panel, the property counters, the
enquiries panel, the audit panel, and the staff counter for everyone, so a
content editor was handed:

- "Published listings" and "Awaiting approval" counts, for data they cannot open;
- a "Recently updated properties" table whose every row linked to
  `/admin/properties/{id}/edit` — a page that answers **403** for them;
- five links they cannot open, on the one page every user sees first.

A listing manager additionally saw the content group in the navigation but no
content tools on the page, and neither role was told what its job actually was.

**Fixed:**

- `AdminController::dashboard()` computes each figure only when the viewer holds
  the permission behind it, and passes the flags through to the view.
- The view gates every panel, counter, and action button on those flags, so the
  property table cannot be rendered for a role that cannot open a listing.
- Content editors get a panel of their own: categories, locations, and
  testimonials, each with a count and a link to the page that manages it.
- The welcome line now states the role's remit, so a content editor is told what
  they look after instead of inferring it from an empty page.
- The dashboard no longer links to anything the viewer cannot open.

`tests/dashboard-scope.php` (38 checks) renders the dashboard for all four roles
in-process and asserts what each is and is not shown, that the two operational
roles differ, and that no dashboard link leads to a 403. It needs no web server,
so the rule is still checked where HTTP is unavailable. `tests/role-matrix.php`
grows to 93 checks with the same assertions over real HTTP.

## 1.5.0 — 2026-11-03

Verified what each of the four roles can actually do, then fixed what the
verification turned up. Adds a permission matrix, a security suite, and an
authorization fix that closes a publication hole.

**Roles, verified by driving the running site rather than by reading the code.**
`tests/role-matrix.php` signs in as each role and walks 13 paths, the navigation
that role is shown, the dashboard panels, privilege escalation by direct POST,
session invalidation, and CSRF. 81 checks. It confirmed the matrix is
`super_admin` (everything) > `admin` (everything but settings) >
`listing_manager` (listings and enquiries) > `content_editor` (content only).

**Fixed — a listing manager could edit a live listing in place.** Publication was
only checked when a listing was *not* already published, so a manager could
change the price or description of a listing the public was reading and it
stayed published, with the original approver still credited. Editing published
content is publication. Any write to a live listing by someone who cannot
approve it now returns it to the queue, clears `approved_by`, and says so in the
flash message and the audit trail. Verified against the old code: the same
request now lands in `pending`.

**Fixed — the dashboard leaked what the navigation was hiding.** One dashboard
serves all four roles, and it queried enquiries and the audit trail
unconditionally. A listing manager was refused `/admin/enquiries` but shown the
five most recent enquirers by name on the dashboard. Both panels and their
counters are now only queried and rendered when the viewer holds the matching
permission.

**Fixed — redirects could leave the site.** `redirect()` passed any absolute URL
through to the `Location` header. No caller used it, but nothing stopped a
future one from becoming an open redirect, so it now always resolves against the
base path. Leading slashes *and* backslashes are stripped: browsers normalise
`\` to `/`, so `/\evil.example` is `//evil.example`, which reads as a host.

**Added — security logging.** Refused sign-ins and sign-outs are now recorded.
A refusal is attributed to the account when it exists and to the address when it
does not, and the reason distinguishes a wrong password from a deactivated
account. The password is never recorded. Logging is capped at 20 entries per
address per 15 minutes, because an unauthenticated stranger controls how often
this fires, and a failure to write the log can never turn a refusal into an
error page.

**Added — `tests/security.php`**, 53 checks against a running instance:
script payloads through real forms (listing title, enquiry message, audit
details) come back escaped; object-level authorization, not just page-level;
CSRF rejection with no state change; cookie flags and security headers;
identical answers for an unknown address and a wrong password; uploads that
reject PHP disguised as an image; throttling; audit integrity. It creates and
deletes its own records and asserts at the end that the demo content is
untouched.

**Added — `bin/maintenance.php`** for routine housekeeping: spent and expired
reset tokens, closed rate-limit windows, abandoned session files. Audit history
is kept by default; set `AUDIT_RETENTION_DAYS` to bound it.

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
