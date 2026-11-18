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

## Requests that change state

Every state-changing form carries a CSRF token compared with `hash_equals`.
A missing or forged token is answered with 419 and the change is not made.
Tokens are per-session and regenerated on sign-in.

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
  an inactive account is treated as unknown.
- Only `sha256(token)` is stored. The token itself exists only in the email.
- One live token per user; requesting again invalidates the previous one.
- Tokens expire after 60 minutes and are single use.
- A completed reset bumps `session_version`, ending sessions elsewhere while
  leaving the device that made the change signed in.

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

From `app/bootstrap.php` on every response: `X-Content-Type-Options: nosniff`,
and a Content-Security-Policy with `default-src 'self'`, `script-src 'self'`
(no inline script), `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`,
and `frame-ancestors 'self'`. Asset URLs carry `?v=APP_VERSION`, so a release
always loads the current stylesheet.

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
