# Changelog

## 1.10.0 — 2026-11-23

**The website can send email through Microsoft 365 with OAuth, which is what Microsoft
is replacing passwords with. Nobody types a password; the Settings page can connect a
mailbox; and the command line can do the same.**

*Why.* Microsoft's published plan, as of this date: from the end of December 2026,
password sign-in for SMTP is off by default for existing Microsoft 365 organisations,
it is not available to organisations created after that, and it will be removed
completely on a date still to be announced (second half of 2027). A website set up with
a mailbox address and a password (1.9.0) then gets `550 5.7.30 Basic authentication is
not supported for Client Submission`. Dates like these have moved before; the guide says
so and tells the reader to check Microsoft's current notice.

*What is new.*

- **OAuth sign-in to the mail server (XOAUTH2)** in `SmtpClient`: the access token is
  sent in the form the protocol asks for, including the empty line a server expects after
  an error explanation (without it a refusal was reported as a hang). The token is never
  sent before the connection is encrypted, and a server that offers only passwords is told
  so before anything is sent.
- **`MailOAuth` and `HttpClient`** (new): ask Microsoft for a token with nothing but PHP's
  own sockets (no curl, no packages). Tokens are kept in `storage/mail-oauth.json` (created
  readable by its owner only, refused by `.htaccess`, ignored by git) and reused until five
  minutes before they run out. A saved token is filed under the app, the tenant, the flow
  *and the client secret it was made with*, so changing the secret takes effect at once
  instead of an hour later.
- **Two ways to sign in.** *App*: the website signs in as an app registered in Microsoft
  Entra (client credentials, `SMTP.SendAsApp`), no person involved. *Person*: a person signs
  in as the mailbox once, on a Microsoft page, with a short code (the device-code flow); the
  website keeps Microsoft's refresh token and replaces it each time it uses it. That ends
  when the mailbox's password is changed, when its sessions are revoked, or after 90 days
  with no mail: press Connect again (the card and the guide say so).
- **A sign-in card in Settings** (Dashboard, Settings, Email delivery; Super Administrator
  only). It shows *Not connected*, then the steps and the code, then *Connected as …*, with
  Connect, *I have signed in*, Cancel and Disconnect. After a press the page lands on the
  card and the answer is shown inside it, not flashed at the top of a long page the person
  has already scrolled away from. Every press needs the CSRF token and the permission, is
  rate limited (30 per ten minutes), and is written to the Activity log without any secret.
- **`php bin/mail-oauth.php connect | status | disconnect`** for the same thing from a
  terminal (exit 0 done or connected, 1 failed, refused or not connected, 2 not set up for
  this or bad arguments), and
  `php bin/maintenance.php` now uses a connected sign-in once a day so a quiet site does not
  lose it to Microsoft's 90 days of silence. `php bin/mail-test.php` says how it signed in
  and `php bin/status.php` shows the connection and, until someone connects, the command
  that does it.
- **Seven settings**, each from an environment variable or `config.local.php`, all written
  up in `docs/EMAIL.md` and `docs/DEPLOYMENT.md` and checked against the code by the suite:
  `MAIL_OAUTH_PROVIDER`, `_FLOW`, `_TENANT`, `_CLIENT_ID`, `_CLIENT_SECRET`, `_AUTHORITY`
  and `_STORE`. With the provider set, the host defaults to `smtp.office365.com` (587,
  STARTTLS) and the sender to the mailbox. A third example file,
  `docs/config-examples/microsoft365.php`, is parsed by the tests like the other two.
- **`docs/EMAIL.md` has a Microsoft 365 chapter**: the timeline above, which way to choose,
  every click of the Entra app registration and the PowerShell for the app way, the person
  way, what each failure means (ten new rows in the failure table), and an offer of the
  alternative that needs none of this: send through Brevo, Mailgun, SendGrid or Amazon SES.

*Care taken with what comes from outside.* Everything Microsoft's side says is treated as
untrusted text. Secrets and tokens are masked wherever a message could carry them (the
settings page, `--verbose`, the error log, the Activity log), as sent, in base64 and
URL-encoded, and even when a long reply cuts one off in the middle. A sign-in page address
is offered as a link only when it is `https` and made of ordinary address characters
(otherwise the standard Microsoft page is shown); a sign-in code must be plain letters and
digits; answers are limited in size, not followed to another address, and read with a
deadline; plain `http` is refused for anything but this machine.

*Proven here, and how.* `tests/mail.php` now runs 466 checks in this sandbox (498 with the
command-line part, which needs a PHP that can start another PHP; CI runs those too). It
starts a stand-in for Microsoft: a mail server that speaks XOAUTH2 (`oauth`, a server that
explains with a 334, one that switches OAuth off, one that echoes the token back, one with
no encryption, one that has stopped taking passwords) and a token service over HTTP and
HTTPS that behaves in 20 different ways (a good answer, a slow one, a web page instead of
JSON, chunked, close-delimited, endless, a redirect, hostile addresses and codes, and so
on). Real sockets, the real code, every path: both flows end to end, connect, renewal,
revoke, a changed secret, a refused saved token replaced once and a fresh one not tried
twice, the Settings card at every step and through the buttons, the permission and CSRF
checks over HTTP (`tests/security.php`: 22 more checks), and the command line. A guard now
fails if any message in the failure table of the guide is not one the code can print. 65
new deliberate breakages of this code and its guides (a missing empty line after a 334, a
token left in a log, a saved file open to everyone, plain `http` to any host, a secret that
no longer invalidates a saved token, an unescaped address, a typo in an example file, and
so on) each fail at least one check, and so do the 45 from 1.9.0. A browser run of the
card at 1366, 768 and 390 pixels (not connected, code shown, not yet, connected, test mail
sent, disconnected, a failure, and the app way) found no overflow, and showed that the
answer to a press was landing a screen away from it, which is why the page now lands on
the card.

*What the tests found while this was being built.* A saved token was still used after
the client secret was changed, so a wrong new secret would have looked fine for an hour; the
secret is now part of what a token is filed under. A reply longer than one line buffer is
read in pieces, and the base64 form of a token cut in the middle by that no longer matched
the mask, so a piece of it reached the Settings page; masking now hides the start of a
secret as well as the whole of it, and its URL-encoded forms. Nine of the first 51
deliberate breakages were not caught at first: one was that real weakness, and the other
eight were checks that were too weak (the size limit on chunked and endless answers,
whether STARTTLS is still demanded before a token, the link's `rel`, what the card says
when pressed for the wrong flow, and others); they are sharper now. A row of the guide
quoted a message with different punctuation from the one the code prints, which is why the
failure table is now checked against the code.

*Not proven, and cannot be from here.* **A real Microsoft 365 organisation was never
used.** Everything above was run against stand-ins that follow Microsoft's documentation,
so three things come from documentation alone: the wording of Microsoft's own error
messages (the plain-words versions are keyed on the error codes it documents), the address
of the delegated permission (`https://outlook.office.com/SMTP.Send`, as in Microsoft's
IMAP/POP/SMTP OAuth article; one third-party guide uses `outlook.office365.com`, and if
Microsoft refuses it at **Connect**, `SCOPE_USER` in `app/Core/MailOAuth.php` is the one
line to change), and the PowerShell steps. Also not proven: PHP's real behaviour on a
closed port and the command-line exit codes (CI is the first place those run), Windows,
and whether the receiving mailbox accepts the message (SPF, DKIM and DMARC are the sending
domain's business, not this code's). The test button and `php bin/mail-test.php` are how to
find out on the real machine: a message in the inbox is the proof.

*Not built.* Sending through the Microsoft Graph API over HTTPS (port 443) instead of SMTP
(useful where port 587 is blocked); the "Sign in with Microsoft" redirect flow; OAuth for
Google (a Gmail or Google Workspace mailbox still uses an app password); personal
Outlook.com accounts; going out through an HTTP proxy. Mail is still sent while the visitor
waits for the thank-you page, so a blocked port can stall it for up to `MAIL_TIMEOUT`
seconds twice; the guide says how to lower that.

*CI.* The mail suite already runs in the SQLite and MySQL jobs with every skipped part
counted as a failure; it now covers the above in both. Nothing public changed: the only
stylesheet addition is two rules for the sign-in card in the dashboard.

## 1.9.0 — 2026-11-20

**Email can now be set up on a developer's computer (Laragon) and on a live hosting
plan, can be tested from the Settings screen or a terminal, and says plainly why
when it cannot send.**

*What was wrong.* The question was whether the existing code would send email on
Laragon and on any hosting plan. It would not have been safe to say yes, and an
audit (rather than an assumption) found why. Every point below was true of 1.8.9:

1. The site could only hand a message to PHP's `mail()`. There were no settings
   for an SMTP mailbox at all, which is what most hosting plans need: `mail()` is
   switched off on some, blocked or throttled on others, and its messages are the
   likeliest to land in spam. On Windows `mail()` talks to whatever `php.ini`
   names, which is nothing on a fresh install.
2. Email was off unless the `MAIL_ENABLED` environment variable was exactly the
   text `true`. Most shared hosting has nowhere to set environment variables, there
   was no example settings file, the README's quick start said
   `cp config.local.php config.local.php` (a file copied onto itself), and the
   deployment guide said never to place `config.local.php` on a server, which is
   the one file that works on every plan.
3. A failure was silent. A password-reset email that could not be sent looked
   exactly like one that was (the visitor sees the same neutral page by design, so
   they cannot tell), and nothing was recorded anywhere.
4. The enquiry alert's recipient fallback never worked. It fell back from the
   notification address to the public email only when the notification setting was
   missing, but the Settings screen saves an emptied box as empty text, so after
   anyone saved that screen with the box empty, the alerts went nowhere.
5. `mail()` was given no envelope sender (`-f`), so on shared hosting the message
   is sent "from" the server's own account name, which fails the domain's SPF check
   and is a classic reason for spam-foldering. The From name was not encoded, so a
   site name with an accent or a comma could break the header.
6. Nothing had ever sent a message in CI. Every test ran with email switched off,
   so "email works" was an assumption, not a result.

*What changed.*

- **Three ways to send** (`MAIL_DRIVER`). `smtp` signs in to a real mailbox or mail
  service. It is a small client of our own on PHP's stream functions, so there is no
  library to install and nothing needed beyond PHP's openssl for encryption. It does
  STARTTLS (port 587), TLS from the first byte (465) or none (a local catcher), AUTH
  PLAIN and LOGIN, checks the server's certificate, and never sends the password to
  a server that has not offered the encryption it was told to require. `mail` is the
  old way, now with the envelope sender. `log` writes each message to
  `storage/logs/mail.log` for development and is refused in production, since it
  would hold live reset links. A site that sets nothing new behaves exactly as
  before: with `MAIL_HOST` empty the method stays `mail`.
- **Settings can live in a file.** `config.local.php` works on every plan; environment
  variables still work and the file wins where both are set. Two ready-to-copy
  examples: `docs/config-examples/local.php` (SQLite, mail to a log file) and
  `docs/config-examples/live.php` (MySQL, an SMTP mailbox, placeholders only).
  `MAIL_ENABLED` also accepts `1`, `yes` and `on`. New: `MAIL_DRIVER`, `MAIL_HOST`,
  `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_VERIFY_PEER`, `MAIL_CAFILE`, `MAIL_TIMEOUT`.
- **A better message.** UTF-8 quoted-printable, a Date and a Message-ID, a From name
  that cannot break or add a header whatever the site is called, and a line that
  starts with a dot written so no transport can take it for the end of the message.
  The enquiry alert now has the visitor as its Reply-To, so replying answers them,
  while it is still sent from the site. Only a plain address is ever used for
  Reply-To or `-f`; a quoted address that PHP's own validator accepts, and that has
  been used to inject options into mail programs, is left out.
- **Failures are visible, and nobody is misled.** Settings has an *Email delivery*
  card that says how mail leaves the site and names anything that cannot work. Its
  **Send a test email to me** button (Super Administrator only, to your own address
  only, five per ten minutes, CSRF-protected) checks the PHP that really serves the
  website, which on shared hosting can differ from the terminal's. From a terminal,
  `php bin/mail-test.php [address] [--verbose]` does the same and shows the
  conversation with the mail server, passwords masked. `php bin/status.php` has an
  Email block. A failed enquiry alert or reset email is recorded in the Activity log
  (`enquiry.alert_failed`, `user.reset_mail_failed`) with the reason, and as one
  `Mail not sent` line in `php-error.log`, never as a PHP warning. The visitor still
  sees the same neutral page, and an enquiry is saved whether or not its alert goes.
- **The notification-address fallback works**: a valid notification address, else a
  valid public email, else the activity log says no alert could be sent and why.
- **Written down for the people who have to set it up.** `docs/EMAIL.md`: what the
  site emails; Laragon (a log file, Laragon's own Mail Catcher, or Mailpit); cPanel
  and shared hosting step by step; a VPS; Gmail, Brevo, Mailgun, SendGrid and Amazon
  SES; SPF, DKIM and DMARC and the spam folder; and a table of what each failure
  message means and what to change. The README quick start now really copies a file,
  and the deployment guide no longer contradicts itself.

*Proven here, and how.* A new suite, `tests/mail.php` (278 checks run in this
sandbox; the CI run adds the command-line checks and one that needs a PHP that
refuses connections the way real PHP does), starts a fake SMTP server
(`tests/fixtures/smtp-sink.py`: python3, plus openssl for the TLS cases; 16 behaviours) and sends real messages
over real sockets to it. It covers a plain server, AUTH PLAIN and LOGIN, STARTTLS,
TLS from the first byte, certificates refused and then trusted, a wrong password, a
server that offers no encryption or no sign-in or only methods we lack, refused
senders and recipients, a 451 deferral, a size limit, a server that hangs up, never
answers, is not a mail server at all, or refuses sign-in with a reply that echoes
the password. It compares what the server received with what was rendered, byte for
byte, and reads the message the way a mail program would. It checks PHP's `mail()`
handing a correct message, and `-f`, to a stand-in for sendmail
(`tests/fixtures/mail-capture.sh`), and a broken mail program being reported rather
than ignored. It runs both real flows end to end (enquiry alert, reset link), with
the mail server up and down. It runs the Settings button directly and over HTTP and
in a browser at 1366, 768 and 390px, including the failure text. It parses both
example settings files and checks that every setting the application reads is
written up. 45 deliberate breakages of the mail code and guides (no dot-stuffing,
certificates never checked, sign-in before encryption, the password left in an
error, a Reply-To taken raw, `-f` for any address, the log driver allowed in
production, the fallback removed, a typo in an example file, and so on) each fail at
least one check.

*What the tests found while this was being built.* A server that echoes the
password could have put it in the `--verbose` conversation; server text is now
cleaned everywhere it is kept. A Reply-To is cleaned where it is written, once per
place (headers, log file) rather than once on the way in. The WebAssembly PHP used
for local checks sometimes reported a closed connection as a timeout, so a timeout
now also has to have waited as long as it was allowed to. And the first draft of the
Windows advice would have warned a Laragon user whose Mail Catcher is working: it now
tries the address in `php.ini` before saying anything.

*Not proven, and cannot be from here.* Laragon and PHP on Windows themselves (the
Windows branch of the suite has never run); a real host's mail server and whether
it lets PHP out on port 587 or 465; and whether the receiving mailbox accepts the
message, which is decided by the sending domain's SPF, DKIM and DMARC records and
not by this code. That is what the test button and `php bin/mail-test.php` are for:
run one on the real machine, and treat a message in your inbox as the proof.

*CI.* The SQLite jobs (PHP 8.1 to 8.5) run the mail suite with the sendmail
stand-in, then once more with a mail program that does not exist. The MySQL jobs
run it too, since production is MySQL and the notification fallback, the Activity
log and the rate limit touch the database. `MAIL_TEST_REQUIRE=1` turns any skipped
part into a failure, so a broken test harness cannot hide behind a green build.
Also: 19 new checks in the smoke suite (200; `config.local.php` ignored by git and
refused by `.htaccess`, `bin/` scripts refuse the web, the guides and examples
exist and say what the code does) and 7 in the security suite (147: the test button
without a token or with a forged one, as a content editor or a listing manager,
signed out, and never reaching the mailer in any of those cases). Nothing public
changed: no stylesheet, script or public page is touched.

## 1.8.9 — 2026-11-20

**Paragraph text now uses the width it has. It fills the column it sits in, up to
one shared readable width, instead of stopping at a different small cap in each
section.**

*What was wrong.* Paragraphs looked narrow, with unused space beside them and
sections that felt lopsided. Rather than judge it by eye, every paragraph of 45
characters or more was measured in a real browser: 456 measurements over 10
pages at 390, 768, 1024, 1280, 1568 and 1920px. 25 groups of paragraphs were held
well short of the column they sit in. The cause was a cap on each paragraph,
added one section at a time over many releases, in px and in ch, in about a
dozen separate layers (the last rule wins): 30ch on the service cards, 34 on the
values and the footer blurb, 42-48 on the contact and intro copy, 44-46 on the
two step lists, 52-68 on the section intros, service details and FAQ, 330-370px
on three more. On 14px text that is 250-570px. At 1568px the text used 36-77% of
its column (the Services intro 36%, the footer blurb 66%, the home contact copy
77%), and in the stacked layouts of a tablet 48-84%. The home "Why" section was
the worst: its whole left block was held to 44ch (346px) inside a 590px column.

*What changed.* One rule replaces the layers. Every one of those paragraphs now
fills its column and stops at a single `--measure` of 86ch. `ch` follows each
paragraph's own font size, so the limit is about 680px for 14px text and 770px
for 16px, and a line holds about 105 characters at most. In a card or a narrow
column the limit is never reached and the text simply fills the column; in a
wide column it stops at a comfortable width. The number lives in one place
(`:root`), so it can be tuned for the whole site at once. At 1568px:

| Paragraph | Before | After | Its column |
| --- | --- | --- | --- |
| Services intro | 441px | 677px | 1240px row, beside the heading |
| Services detail text | 567px | 717px | 1045px |
| Properties page intro | 409px | 677px | 754px |
| Home featured intro | 409px | 538px | 538px |
| Home Why, left block | 346px | 590px | 590px |
| Home Why, list | 367px | 516px | 516px |
| Home contact copy | 330px | 428px | 428px |
| Footer blurb | 248px | 373px | 373px |
| About, process intro and steps | 378px / 383px | 586px / 510px | 586px / 510px |
| Property description | 519px | 677px | 868px |
| Property enquiry intro | 360px | 477px | 477px |
| Contact information | 378px | 415px | 415px |
| FAQ answers | 519px | 677px | 710px |

On a phone the service cards and the About values go from 252px and 268px to the
full card (359px and 333px), and on a tablet the Why, contact, process and footer
text no longer stops at 330-380px in a 640-710px column.

Three places needed more than a number:

1. *Why section.* Its left block was capped (44ch) on the block itself, not on
the paragraph, so it now has no cap and takes its column.
2. *A heading with its intro on one row (the Services page).* A wider intro makes
the row overflow, and a flex row then shrinks every item in proportion to its
size: at 768px that squeezed "THOUGHTFUL SUPPORT." into four lines in 144px. The
intro now starts from zero and grows into whatever the heading leaves free (never
below 18rem; from 641px, since below that the row stacks). The heading keeps two
lines at every width from 641px (it was four lines at 641px and three at 700px)
and the intro takes the rest.
3. *Hero.* The lead was a fixed 490px and the line under it a fixed 430px, which
wrapped "LASTING VALUE." onto a second row at 1568px. The lead is now capped in
ch, so it follows its font size, and it breaks evenly: its two sentences each
take a line at every width (between 768 and 1024px it used to break after
"Discover a"). The line under it takes the width of its text.

*Deliberately not changed.*
- The privacy and terms pages. Their text runs the whole 1240px container, 140
  to 200 characters per line on a laptop. That is the opposite fault, and capping
  it to a column left the page lopsided with a void beside it, so it is left for
  a separate decision. Adding `.legal-content > p`, `> ul` and `> ol` to the
  `--measure` rule would cap it.
- The page banners and the one-line "A place to belong" paragraph on a property
  page. They already fit on one line; capping them would only have wrapped them.
- The hero lead stays narrower than the heading above it, by design.

*Checked in a real browser.* Of the 456 paragraph measurements, 223 are wider,
233 were already filling their column and are unchanged, none is narrower, 124
take fewer lines and none takes more. The groups flagged as narrow went from 25 to
0. The wider regression sweep is clean: 209 page/width combinations with no
overflow, no tight spacing or small tap targets, 0 of 295 reveal-gated elements
left hidden, 7 of 7 menu items reachable, 23 of 23 menu-flow checks, the footer
at 11 widths, the category panel 0.0px off-centre at every width, and the
category tiles still clean in all 242 width and data combinations.

*Tests.* 14 new pins in the responsive regression suite (170 to 184): the measure
is one length in ch between 70 and 100; every one of the 17 paragraph selectors is
capped by it and by nothing else (a later fixed-width cap on any of them fails the
pin); the Why block, the heading row and the hero rules; and a simulation that a
column up to 600px is filled at 14, 15 and 16px and no line passes about 125
characters. Each of 34 deliberate breakages (drop a selector, shrink the measure,
re-cap the FAQ or the footer blurb, restore the 44ch block, unwrap the row rule,
put the hero back to 490px, and so on) fails at least one pin.

## 1.8.8 — 2026-11-20

**The home-page category tiles are fixed at every screen size: the "N
properties" line no longer sits on top of the icon, the tiles are one divided
panel, and an odd tile fills its row instead of leaving a hole.**

*What was wrong.* It was reported at 423px and 1568px. Rather than patch those
two widths, the tiles were measured in a real browser at 22 widths from 320 to
2560px with 11 data shapes (no listings, large counts, 1 to 11 categories, long
names): all 242 combinations had at least one defect. There were three causes.

1. *The footer was in the number row.* A tile has four parts but only three had
a place in its grid. The icon was meant to be a grid item yet still inherited
`position:absolute` from an earlier layer, and an absolutely positioned child
takes no grid cell. The footer ("3 properties ↗") had no row of its own, so
auto-placement put it in the first free cell, which was the icon's: the icon was
painted over the arrow (a 289px² overlap at 423px) and the footer sat 144px
(423px) to 152px (1568px) above the bottom edge instead of on it. Every part now
has an explicit place: number top-left, icon top-right, label in the middle,
count bottom-left, arrow bottom-right, pinned to the bottom padding.
2. *The tiles did not touch.* The site-wide rule that gives grids a 16-28px gap
applied here too, so every divider was broken or doubled, and the content of
tiles 2-5 sat one gap further from its divider than tile 1's: at 1568px
APARTMENTS was 1px wider than the box it was in. The tiles are now one panel
with no gap, and each tile draws its own right and bottom hairline, so every
divider is a single continuous 1px line in any layout.
3. *An odd tile left a hole.* The ladder was 5 / 4 / 3 / 2 / 1 columns, so at
423px LAND sat alone in a third row with an empty cell beside it, and at
861-1024px the fifth tile sat alone in the second row. The tiles now sit on a
60-track grid (60 divides by 5, 3, 2 and 1), and the tiles left on an incomplete
last row share it evenly, so a row is always full. An admin can add and remove
categories, so this holds for any number of them. Every row is the same height
(`grid-auto-rows:1fr`), so a two-word name that wraps cannot make one row taller
than another.

*The new ladder.* Five columns from 1025px, three from 641 to 1024px, two from
401 to 640px, and one compact row per tile at 400px and below (number, name
over count, icon). Type, icon and padding are fractions of one tile's width
(name 12.5%, number 24%, icon 10%, padding 9.8%), which is how the reference is
proportioned, so a tile is the reference scaled at any width and any column
count, with no per-breakpoint font ramp to drift out of step.

*Smaller things found on the way.*
- A single listing read "1 properties". The count now says "1 property" (and
  "1 available property" on the location cards) through one helper,
  `property_count_label()`.
- The index beside the heading was fixed at "01 — 05". It now follows the number
  of categories. The ghost numbers always have two digits and are hidden from
  assistive technology (the heading carries the name). With no categories the
  whole section is left out instead of showing an empty box.
- With reduced motion the arrow still nudged on hover. The reset for it sat above
  an unconditional rule with the same specificity, so it never applied. Fixed.
- The panel is now its own stacking context, so WebKit clips the hover wash and
  the reveal animation to its rounded corners.
- Hover (green wash, white name), the keyboard focus ring (inset, so the panel
  cannot clip it) and the reveal are unchanged.

*Checked in a real browser.* The tile sweep went from 242 of 242 failing to 0 of
242. It checks that no two parts overlap, 12px of room from every edge, the
footer within 2.5px of the bottom padding, no name split or overflowing, equal
padding and height, no gap or hole between tiles, footers level across a row,
and single 1px dividers by pixel scan. The panel is 0.0px off-centre at 320,
423, 768, 1024, 1568 and 1920px. Hover, press, reduced motion and focus were
checked at 423, 768 and 1568px. The wider regression sweep is clean: 209
page/width combinations with no overflow, no tight spacing or small tap targets,
0 of 295 reveal-gated elements left hidden, 7 of 7 menu items reachable, 23 of
23 menu-flow checks, and the footer at 11 widths.

*Tests.* 38 new pins in the responsive regression suite (132 to 170). One
simulates the stylesheet's own rules for every count from 1 to 40 categories at
5, 3 and 2 columns and requires every row to add up to exactly 60 tracks, so a
hole cannot come back unnoticed. Four new smoke checks cover the count wording.
Each of 32 deliberate breakages (drop the footer's row, restore the gap, drop an
orphan rule, put the icon back to absolute, and so on) fails at least one pin.
The first run let one through because a pin had matched a word in a CSS comment;
the pins now ignore comments.

## 1.8.7 — 2026-11-19

**No menu item could be tapped. The footer is two columns on phones and
tablets. A full audit of the site — layout, spacing, performance and security —
and the fixes it found.**

> **Upgrade note.** In production, `APP_URL` is now required for password-reset
> emails. Without it no reset email is sent (see *Security* below). Set it to
> your public address, e.g. `APP_URL=https://www.example.com`; `php
> bin/status.php` warns when it is missing.

*The mobile menu.* Every item was dead: 0 of 7 reachable in a real browser. The
dimming layer behind the drawer sat outside the sticky header. A sticky header
is always its own stacking context, so no `z-index` can lift the drawer's links
above a layer outside it, and every tap landed on the dimmer, which closed the
menu. (Setting the header to `z-index:auto` was tried first and measured worse.)
The fix is structural: the dimmer now lives inside the header and shares the
drawer's stacking context. Drawer links are 48px rows, the floating WhatsApp and
back-to-top buttons step aside while the menu is open, and the dimmer is removed
from layout on desktop. 7 of 7 items are reachable at 360, 390, 414, 768 and
1024px, and the open/close/Escape/resize flow passes 22 of 22 checks at 390 and
768. A smoke test had been pinning the broken structure ("the overlay sits
beside the header"); it now asserts the working one.

*Footer.* Explore and Our company sit side by side on phones and tablets (two
equal columns, with the brand and the contact details spanning both). Link rows
are 44px. On wider phones and tablets the contact details run two across.
Privacy policy and Terms of use are a two-column row with a divider, and the
note and copyright are centred. The page now reserves 124px beneath the footer:
at 360-390px the centred copyright ran under the back-to-top button with the
old 84px. Checked at 11 widths from 320 to 1280, desktop unchanged.

*Audit: layout and spacing.* Every public page at 19 widths from 320 to 1920 (209
loads) in a real browser, then the spacing rules on 10 pages at 10 widths:
paragraph line height at least 1.45, paragraph-to-paragraph and text-to-button
gaps, button-to-button gaps, tap targets of 40px or more. Found and fixed:

- The category band overflowed its container. `margin-inline:auto` cannot
  centre a box wider than its container, so above about 1320px the band sat
  flush left and ran 14px off the right edge at 1440 and half a tile off at
  1920. It is now placed from its own centre line and is centred and fully
  inside the viewport at every width from 320 to 2560.
- The header logo, the most-used link on a phone, was 35-42px tall. Its hit
  area is now 48px (45px while the header is shrunk on scroll); the artwork does
  not move.
- The decorative watermark words (CEDAR, HELLO, PURPOSE) were read aloud by
  screen readers before the page heading. They are now `aria-hidden`.
- Nothing is left invisible: after scrolling every page top to bottom, 0 of 295
  reveal-gated elements were still hidden (55 loads, 5 widths), and with
  `prefers-reduced-motion` everything is shown at once.

*Audit: performance.* The two preloaded fonts were each downloaded twice, about
860 KB on a first visit: the preload URL carried `?v=<version>` and the
stylesheet's `@font-face` cannot, so the browser treated them as different files.
`asset()` gained an unversioned form, used for the preloads; each font is now
fetched once (1250 KB, down from 2108 KB of fonts). The `.htaccess` files now
ask Apache to compress CSS, JS and uncompressed font files (the build
environment has no Apache, so the rules are checked for structure, not
measured). HTML is deliberately left out: pages carry CSRF tokens and echo
visitor input, which is what the BREACH attack needs.

*Audit: security.* No website can honestly be called unhackable; this is what
was reviewed, what was wrong, and what is now enforced by tests.

- **Password-reset poisoning (high).** The emailed reset link was built from the
  request's `Host` header, which the sender of a request chooses. Anyone could
  ask for a reset for a member of staff with `Host: evil.example` and the genuine
  email, with a live token, would point at their server: an account takeover.
  The link now comes from `APP_URL` or not at all. In production without
  `APP_URL` a reset request fails closed: the token is discarded, nothing is
  sent, the visitor sees the same neutral message, and the attempt is logged and
  audited.
- **The same header elsewhere.** Canonical and Open Graph tags, JSON-LD and the
  sitemap used `Host` even when `APP_URL` was set. `APP_URL` now always wins, and
  a `Host` is accepted only if it is a well-formed host name.
- **The built-in server handed out dotfiles.** `public/router.php` served
  `/.htaccess` and `/uploads/.htaccess`. Dotfiles, traversal and control
  characters now get the application's 404.
- **Methods and routing.** Only GET, HEAD and POST are served (OPTIONS answers
  204, anything else 405). `admin` is matched exactly, so `/admin.php` and
  `/administrator` are ordinary 404s. The `.htaccess` rules also refuse
  TRACE/TRACK and dotfiles (untestable here for the same reason).
- **Headers.** `X-Frame-Options` and HSTS were sent only when `APP_ENV` was
  `production`, so forgetting that one variable dropped both; they are now sent
  everywhere. Added `Cross-Origin-Opener-Policy`, payment and USB to the
  permissions policy, and removed `X-Powered-By`.
- **Sessions.** `/health`, `/robots.txt` and `/sitemap.xml` no longer start a
  session. Monitors and crawlers call them constantly and keep no cookie, so each
  call wrote a file to `storage/sessions`, and anyone could do it on purpose to
  fill the disk. Session garbage collection is switched on explicitly, and
  `session.use_trans_sid` is off.
- **The activity log lost the name on every category and location save.** The
  messages were written `“$name”`, and PHP reads a byte above 0x7F as part of a
  variable's name, so it looked up a variable called `name”`: the audit entry
  said "Created Category “" with no name and an unclosed quote, and each save
  wrote a PHP warning to the error log. They now use `{$name}`. It had never been
  noticed because no test completed a category save; the new route-coverage
  test did, and CI's own "fail on logged warnings" guard caught it. A source scan
  now fails on the pattern anywhere in the code, and a live check reads the audit
  entry back.
- **Smaller fixes.** `Database::identifier()` used `$`, which also matches before
  a trailing newline (now `\z`). The enquiry notification email contained the
  literal text `brand() . ' dashboard.` instead of the site name. `robots.txt`
  now disallows the password-recovery pages.
- **Reviewed and found sound:** CSRF on every state-changing route; output
  escaping (864 template echoes; every helper that returns HTML escapes); SQL
  (all values bound, identifiers whitelisted); uploads (re-encoded, SVG
  sanitised, scripts refused); cookie flags; the content security policy (no
  inline or `eval` script); sign-in throttling; account enumeration; redirects;
  and the installer, which refuses a short password and demo data outside
  `APP_ENV=local`.

*Tests.* Each of these fails if the behaviour regresses, and was checked by
deliberately breaking the code and watching the right check go red:

- `tests/security.php` has a new section that sends **every state-changing
  route** (20 admin POSTs and 6 public ones) with no token and a forged one,
  then reads the database to prove nothing changed, plus GET-must-not-act checks
  and positive controls so a wrong field name cannot pass by accident. It also
  caught that the existing "changes nothing" check on settings compared a cached
  value with itself and could never fail; it now reads the database. New
  sections cover reflected input and the session policy. All seven handlers whose
  token check was removed in turn were detected.
- `tests/host-header.php` (no server needed) covers the reset link, the origin
  helper and the sitemap. `tests/smoke.php` gained a guard that no template
  echoes request data unescaped, the session-policy table, font preload parity,
  `.htaccess` structure and compression rules. `tests/responsive-regression.php`
  pins the footer, the logo hit area and the centred band.
- The tests no longer call `curl_close()` and `setAccessible()`, which have done
  nothing since PHP 8.0 and 8.1 and which PHP 8.5 reports as deprecated. They
  were the only noise in the error log, so CI's guard against logged warnings now
  means something even on a runner that shows deprecations.
- **`SQLITE_JOURNAL_MODE`** (`wal` by default; `delete`, `truncate` or `persist`
  also accepted, anything else falls back to `wal`) lets a demo or CI database
  run where WAL's shared memory is unavailable. In such a place the test process
  and the web server each saw their own older copy of recent writes, which hid
  real results: with it set, the password-reset suite is 71 of 71 (it was 61 of
  71) and the security suite 137 of 137. SQLite remains refused in production.

*Documentation.* `docs/SECURITY.md` covers the Host header, request methods,
sessions and the CSRF coverage rule ("when you add a route that changes data,
add it to that list"). `docs/DEPLOYMENT.md` marks `APP_URL` as required and
explains running behind a proxy or CDN, where rate limits would otherwise treat
every visitor as one address.

## 1.8.6 — 2026-11-03

**The About copy never appeared on a production install. Tile labels broke
mid-word. The service heading was boxed.**

*About copy — the actual cause.* The About page has always rendered
`about_body`, and the demo database always contained the paragraphs, which is
why this looked fixed twice. It was not: `bin/install.php` seeded `about_body`
as an empty string. A production install therefore had a lead paragraph and no
company description — exactly the panel in the screenshot, whose summary text
("Thoughtful real estate, with people at its heart") is the installer's default,
not the demo seed's.

The copy now lives in one place, `ABOUT_BODY_DEFAULT`, used by the installer,
the demo seed and the About view. The view uses `?:` rather than `??` because
`setting()` only falls back on a missing key, and an empty string is present —
that distinction is why an earlier fallback attempt changed nothing. Verified by
clearing `about_body` in the database: both paragraphs still render.

*Category labels broke mid-word.* `overflow-wrap:anywhere` with `hyphens:auto`
let the browser break inside a word, so APARTMENTS and COMMERCIAL rendered as
"APARTMEN / TS" and "COMMERCI / AL". A property type is one word; the rule is
now `overflow-wrap:normal`, and the size ramp is verified to fit the longest
label at every breakpoint instead of breaking to escape.

*The icon went back to the corner.* It is once more in the top-right of the
tile, on the number's line, as it was before the visual-effects work — but as a
grid item in that row rather than an absolute overlay. That keeps the original
position and still guarantees the label can never slide beneath it. Because the
label now owns the full tile width, it is set at 2.55vw (up from 2.05vw).

*Mobile tiles.* One column below 400px became a compact horizontal row —
number, label and count on the left, icon top-right — instead of a full-screen
stack of five near-identical panels.

*Service headings.* `.service-detail h2` was capped at `max-width:22ch` inside a
1050px column, so a three-word heading broke over three lines and read as
though it were boxed, and it was lumped into the 20-29px sub-heading ramp. It
is now 24-34px at 46ch, and fits on one line at every width from 768 up.

## 1.8.5 — 2026-11-03

**Fix the stray dark pill, and size the category tiles to the approved
reference.**

*The pill on every page.* A 62x41px dark rounded rectangle floated above the
mobile navigation on every page at every scroll position. It was the toast — the
element that carries "Saved", "Link copied" and similar confirmations.

The toast exists in the DOM on every page so screen readers can announce it, and
it is empty until there is something to say. It carried
`animation: toast-in .55s both` unconditionally, and a `both` fill-mode keeps an
animation's end state forever — the end state here being `opacity:1`. So the
empty toast was painted at full opacity from first paint, overriding the
`opacity:0` in its own base rule. With no text it collapsed to its padding, which
is exactly the 62x41px pill in the screenshot. Because `--z-toast` (500) sits
above `--z-drawer` (210), it also drew on top of the mobile menu.

The animation now belongs to `.toast.show`, so it only runs when there is a
message to animate in, and `.toast:empty` is held at `opacity:0` regardless.

*Category tiles.* Rebuilt to the proportions of the supplied reference, which
measured 337x312px with an 83px ghost number, a 34px icon and a 43px label. The
tiles were 120-152px tall with a 30px label, and could not grow because the
tile headline was grouped into two shared type ramps (`.property-card h3,
.service-card h3, .category-card h3, ...`) that pinned it to 17-21px, and two
older breakpoints restated it at 30-34px. The headline is now out of both ramps
and sized only by the tile block.

Five tiles at the reference width need a 1713px band, which is wider than the
1240px container. The tiles therefore sit in their own centred band capped at
94vw, while the section heading stays on the normal container edge. The label
is capped at 42px — the largest size at which APARTMENTS (5.406em) still clears
the icon track at 1025px, where the five-across band is tightest. Verified at
fourteen widths from 360 to 1920: no label wraps, no icon collides, tile content
always fits its height, and all five tiles share one height at every size.

## 1.8.4 — 2026-11-03

**Stack the split panels, clear the floats, align the category tiles.** Three
layout faults reported from screenshots, all of them cases of an element being
laid out for a width it was not being shown at.

*Split panels stacked only in theory.* `.split-layout` had a `@media
(max-width:1024px)` rule that turned it into a single column, but two later
unconditional rules set two columns again, so the media rule never took effect.
The About panel (eyebrow over headline, one word per line, a ragged checklist,
figures crushed to ~80px cells) and the "Cedar difference" panel on the home page
were both two columns at every width down to 360px. The stack rule now sits at
the end of the stylesheet where nothing overrides it, and the copy is ordered
above the image on narrow screens so the panel opens with the words.

*Text ran under the floating controls.* The WhatsApp and back-to-top buttons are
fixed to the bottom-right, and on a narrow screen the last lines of a section
passed underneath them. Below 1024px the footer reserves room for them and both
buttons sit closer to the edge, so no content can be trapped behind one.

*Category tiles were not one height.* The icon was absolutely positioned in the
top-right while the label ran the full width of the card, so the two collided —
APARTMENTS and COMMERCIAL overlapped their own icons by 4px at 1025px and 29px at
1920px, which is the collision in the screenshot. The icon is now a grid track
in the label's own row, so the label takes every pixel the icon does not need and
can never slide beneath it; the card is a four-row grid whose flexible spacer
makes all five tiles share one height, with the footers on a common baseline.

## 1.8.3 — 2026-11-03

**Restore the About page body copy.** The previous release removed the two
`about_body` paragraphs on a misreading of the review note. A company page with
no explanation of the company is not a design improvement — the copy is restored
in full and in order beneath the summary:

  "Cedar Tranquility Real Estate connects people with places they can believe
  in. From the energy of Lagos to the possibilities of Abuja, we bring local
  knowledge, careful selection, and a personal approach to every property
  journey."

  "We take time to understand what matters to you. Then we help you explore the
  options, ask the right questions, and move forward with clarity. No
  unnecessary complexity. No one-size-fits-all advice. Just thoughtful real
  estate, with people at its heart."

The panel now reads: eyebrow, title, summary, the two paragraphs, then the
call to action. An empty paragraph is skipped rather than rendering as a gap, so
an editor clearing the field leaves no stray spacing.

## 1.8.2 — 2026-11-03

Four corrections from review.

**About page.** (Superseded by 1.8.3 — the body copy was restored.)

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
