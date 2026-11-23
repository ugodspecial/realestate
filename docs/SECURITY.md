# Security notes

What this application does to protect itself, where that is enforced in the
code, and how to check it. Written to be read alongside
`tests/security.php`, which drives a running instance and asserts most of what
is described here.

Run the checks:

```bash
php -S 0.0.0.0:8080 -t public public/router.php &
php tests/security.php  http://127.0.0.1:8080
php tests/role-matrix.php http://127.0.0.1:8080
php tests/host-header.php        # no server needed
```

## Roles and permissions

One table decides everything: `Auth::PERMISSIONS`. A role is a name; a
permission is a capability. Pages, navigation, and object writes all ask the
same question.

| | super_admin | admin | listing_manager | content_editor |
|---|---|---|---|---|
| dashboard | yes | yes | yes | yes |
| properties.manage | yes | yes | yes | – |
| properties.approve | yes | yes | – | – |
| enquiries.manage | yes | yes | yes | – |
| content.manage | yes | yes | – | yes |
| users.manage | yes | yes | – | – |
| settings.manage | yes | – | – | – |
| audit.view | yes | yes | – | – |

Two rules follow from this table and are worth stating plainly, because both
were once broken:

1. **A shared page is not a shared permission.** The dashboard is the landing
   page for all four roles. Any panel on it must be gated by the permission for
   the data it shows, not by the fact that the viewer reached the page. A
   counter is data: showing "Awaiting approval" to someone who cannot open a
   listing tells them something about the portfolio. A link is data too: every
   link on the dashboard must open for the role that was shown it, or the page
   is advertising work that answers 403. `tests/dashboard-scope.php` asserts
   both without needing a web server.
2. **Editing published content is publishing it.** `properties.approve` is
   meaningless if a non-approver can change a live listing in place. Any edit to
   a published listing by someone who cannot approve returns it to the queue and
   clears the previous approver.

## Authorization is checked per action, not per page

Every admin action calls `Auth::require('<permission>')` itself. Hiding a link
is presentation; the check is in the controller. Object-level operations (delete
this image, approve this listing) are checked again at the object, so a
guessing URL is refused with 403 rather than acting on someone else's record.

## Sessions

- Passwords are stored with `password_hash()` and rehashed on sign-in when the
  default algorithm changes.
- The session id is rotated on sign-in (`Auth::regenerateSession()`).
- The cookie is `HttpOnly` and `SameSite=Lax`, and `Secure` over HTTPS.
- `users.session_version` is bumped by a password reset or an administrative
  password change. Every request compares the session's copy against the
  database, so a stolen session dies as soon as the password changes, without
  affecting the device that made the change.
- Idle sessions expire after two hours.
- `/health`, `/robots.txt` and `/sitemap.xml` do not start a session
  (`session_wanted()` in `helpers.php`). Monitors and crawlers call them all day
  without keeping a cookie, so a session per request would be a file on disk per
  request, and anyone could do the same on purpose to fill the disk. Anything
  unusual about the request keeps the session: the check only ever errs towards
  starting one. Abandoned sessions are collected by PHP itself (1% of requests,
  after two hours), because some hosts ship with collection switched off.

## Requests that change state

Every state-changing form carries a CSRF token compared with `hash_equals`.
A missing or forged token is answered with 419 and the change is not made.
Tokens are per-session and regenerated on sign-in.

The check lives in each handler (`verify_csrf()`), not in the router, so a
handler added later could forget it. `tests/security.php` ("CSRF covers every
state-changing route") therefore sends every POST route, as a signed-in super
administrator, with no token and with a forged one, and then reads the database
to prove nothing changed. It also proves that a state-changing URL does nothing
when requested with GET, and includes positive controls so a wrong field name
cannot make the negative checks pass by accident. **When you add a route that
changes data, add it to that list.** Each probe uses a disposable row, so a
missing check can only ever damage test data.

## Sign-in

- Rate limited per address *and* address+email, so neither one account nor one
  network can be worked through quickly.
- An address that does not exist is verified against a dummy hash, so it takes
  the same time as a wrong password.
- The response is identical for an unknown address, a wrong password, and a
  deactivated account. The reason is recorded in the audit trail, never shown to
  the visitor.
- Refusals and sign-outs are audited. Attribution is to the account when it
  exists and to the address when it does not. A password is never written to the
  trail.
- Because an unauthenticated stranger controls how often a refusal is logged,
  logging is capped at 20 entries per address per 15 minutes, and a failure to
  write the log cannot turn a refusal into an error.

## Password reset

- A request always gets the same answer, whether or not the address exists, and
  an inactive account is treated as unknown. The *time* it takes is the same too
  where the server can answer before it sends (PHP-FPM, LiteSpeed; see *Email*
  below): the lookup, the token and the mail all come after the response. Where it
  cannot (`mod_php`, CGI, `php -S`) a request for a real account takes as long as
  the mail server does, and a patient observer can tell the two apart.
- Only `sha256(token)` is stored. The token itself exists only in the email.
- One live token per user; requesting again invalidates the previous one.
- Tokens expire after 60 minutes and are single use.
- The emailed link is built from `APP_URL`, never from the request's `Host`
  header (see *The Host header* below).
- A completed reset bumps `session_version`, ending sessions elsewhere while
  leaving the device that made the change signed in.

## Email

Everything the mail code does to protect the site, with the evidence, is in
[EMAIL.md](EMAIL.md#security-and-speed); `tests/mail.php` asserts it. In short:

- **Nothing a visitor types chooses a recipient, a subject or a header.** The enquiry
  alert goes to the office address in Settings; the visitor's address is only a
  `Reply-To`, and only if it is an ordinary address. Names, messages and phone numbers
  are length-capped and written as plain text (quoted-printable), never HTML.
- **One address rule everywhere** (`Mailer::isAddress()`): PHP's `FILTER_VALIDATE_EMAIL`
  accepts control characters inside a quoted local part, so an address with a line break
  and a `Bcc:` line passes it. The Settings form, the staff form, the enquiry form, the
  command-line tools and the mailer itself all refuse it; `SmtpClient` checks the
  envelope again and normalises every line break to CRLF (SMTP smuggling).
- **Floods are bounded.** 10 enquiries an hour per address, `MAIL_ALERT_LIMIT` alerts an
  hour in all (enquiries are still saved), 3 reset requests an hour per address and 9 per
  network address, 5 test emails per ten minutes, 30 OAuth button presses per ten minutes.
- **TLS 1.2 or newer, certificate checked, no credential before encryption.** Only
  `localhost`, `::1` and `127.0.0.0/8` may skip encryption; a name that starts with `127.`
  is not one of them.
- **Secrets stay out of every page and log**: the password, client secret and tokens are
  masked wherever a mail server's reply is shown or recorded (as sent, base64, URL-encoded).
- **The saved OAuth sign-in file** is private before it is written, replaced atomically,
  changed under a lock (a Disconnect cannot be undone by a token request in flight), ignored
  by git, refused by `.htaccess`, and reported by `bin/status.php` if its mode is loose.
- **Admin mail actions** need `settings.manage` (Super Administrator) and a CSRF token; the
  test email goes only to the person pressing the button.

Not covered: SPF, DKIM and DMARC are DNS records, not code, and decide whether mail lands in
an inbox. A MITM position on the network path can still stop mail (not read it, over TLS).

## Output, templates, and the database

- All template output goes through `e()`, which is `htmlspecialchars` with
  `ENT_QUOTES | ENT_SUBSTITUTE` on UTF-8. Values that reach an attribute are
  escaped the same way.
- Queries use bound parameters. Search filters and sort keys are chosen from
  allowlists rather than concatenated, and the page size is clamped.
- Uploads are validated by **content**, not by name: `finfo` must report
  JPEG, PNG, or WebP *and* `getimagesize()` must parse it. Images are re-encoded
  before being stored and given random names, so a polyglot file or a PHP script
  renamed to `.png` does not survive. SVG logos are sanitized before being
  stored. A `.php` file is never written under `public/`.

## Transport and headers

From `app/bootstrap.php` on every response:

| Header | Value / purpose |
|---|---|
| `Content-Security-Policy` | `default-src 'self'`, `script-src 'self'` (no inline script, no `eval`), `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'`. `style-src` also allows `'unsafe-inline'` because the templates carry a few inline `style` attributes; nothing executable can be injected that way. |
| `X-Frame-Options: SAMEORIGIN` | Clickjacking protection for browsers that predate CSP `frame-ancestors`. Sent in every environment; it used to depend on `APP_ENV=production`. |
| `X-Content-Type-Options: nosniff` | Stops a served file being reinterpreted as script. |
| `Referrer-Policy: strict-origin-when-cross-origin` | Keeps paths and query strings (including reset tokens) out of the `Referer` sent elsewhere. |
| `Permissions-Policy` | Camera, microphone, geolocation, payment and USB are off. |
| `Cross-Origin-Opener-Policy: same-origin` | Isolates the browsing context from pages that open it. |
| `Strict-Transport-Security` | One year, whenever the request arrived over HTTPS. |
| `Cache-Control: private, no-store` | Added when `APP_ENV=production`. |

`X-Powered-By` is removed, so the PHP version is not announced. Asset URLs carry
`?v=APP_VERSION`, so a release always loads the current stylesheet.

## The Host header

The `Host` header is chosen by whoever sends the request, so it is believed only
where being wrong is harmless. `trusted_origin()` (in `helpers.php`) is the one
place that decides: `APP_URL` always wins, and a well-formed `Host` (letters,
digits, dots, hyphens, an optional port; nothing else) stands in only for
canonical and Open Graph tags, the sitemap and JSON-LD, which are shown to the
person who asked. A malformed value yields no origin at all rather than a guess.

The link in a **password-reset email** is different, because it is delivered to
someone else. It is built from `APP_URL` or not at all: in production without
`APP_URL` the request is answered with the usual neutral message, no token is
kept and nothing is sent, so a forged `Host` can never put an attacker's address
into a genuine email. `bin/status.php` warns when this is the case.
`tests/host-header.php` asserts all of it and needs no web server.

## Request methods and files

- Only `GET`, `HEAD` and `POST` are served. `OPTIONS` answers `204` and every
  other method `405`, both with an `Allow` header. State changes are protected
  independently: `verify_csrf()` demands a real `POST` with a valid token.
- `admin` is matched exactly (`/admin` or `/admin/...`), so `/administrator` or
  `/admin.php` are ordinary 404s.
- Apache: `.htaccess` denies `app/ database/ storage/ bin/ docs/ tests/`,
  `config*.php`, every dotfile, and the `TRACE`/`TRACK` methods; `uploads/`
  cannot run or serve scripts, HTML or SVG.
- `public/router.php` (the built-in server used for demos and CI) serves a file
  only if it resolves, via `realpath`, to something inside `public/`, is not PHP,
  and has no dot-prefixed path segment (`.well-known/` excepted). It would
  otherwise hand out `.htaccess` or `.git/*`, which Apache refuses by default.
- `Database::insert()` and `update()` accept column names only if they match
  `^[a-z_]+\z` (an anchored `$` would also let a trailing newline through).

## Redirects

`redirect()` always resolves against the configured `base_path`; it cannot emit
an absolute URL. Leading slashes and backslashes are stripped, because browsers
read `/\evil.example` as `//evil.example`, which is a host.

## Deploying safely

- Run `php bin/status.php` before and after an upgrade. It exits non-zero while
  a migration is pending, so it can gate a deploy.
- New code must tolerate an old schema: read new columns defensively, and let the
  status page say the feature is unavailable rather than erroring.
- `php bin/maintenance.php` is safe to run at any time and safe to run twice.
  Schedule it daily.

## Reporting

This is a small application with a single deployment per installation. If you
find a problem, the audit trail (`/admin/audit`, `audit.view`) is the first place
to look: sign-ins, refusals, sign-outs, password events, and every content change
are recorded there with an address and a timestamp.
