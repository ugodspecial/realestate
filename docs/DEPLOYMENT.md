# Deployment Guide — Cedar Tranquility Real Estate

## 1. Production requirements

- PHP 8.1+ with extensions: `pdo_mysql`, `mbstring`, `gd`, `fileinfo`
- MySQL 5.7+ or MariaDB 10.3+, with a dedicated database and user
- Apache or LiteSpeed with `mod_rewrite` (or Nginx — see §6)
- HTTPS certificate (cPanel AutoSSL / Let's Encrypt is fine)

## 2. Upload the files

1. Upload the repository contents to your hosting account, e.g. `/home/you/cedar/`.
2. Point the domain's **document root** at the `public/` directory
   (cPanel: *Domains → Document Root*). If the host forces `public_html/`, upload the
   contents of `public/` into `public_html/` and everything else one level above, then
   adjust the `dirname(__DIR__)` path in `public/index.php` accordingly.
3. **Never** place `storage/*.sqlite` or `storage/demo-credentials.json` on a public
   server. Delete them if copied by accident. Keep passwords out of git: put them in
   environment variables, or in a `config.local.php` in the project folder (§3), which is
   ignored by git, refused by the bundled `.htaccess`, and out of reach when the document
   root is `public/`. Never place it inside `public/`.

## 3. Configure the environment

Set these as environment variables where your host allows it (PHP-FPM pool, Apache
`SetEnv`, a process manager), **or** put the same settings in `config.local.php` in the
project folder: copy `docs/config-examples/live.php` and edit it. A plain file works on every
hosting plan, including those with nowhere to set environment variables, and where both are
set the file wins. In the file the names are lower case (`MAIL_HOST` is `'mail_host'`).

| Variable       | Example            | Purpose                              |
|----------------|--------------------|--------------------------------------|
| `APP_ENV`      | `production`       | Enables hardened production headers  |
| `APP_URL`      | `https://cedar…`   | **Required in production.** Canonical URLs, sitemap, JSON-LD, and the link in password-reset emails |
| `DB_DRIVER`    | `mysql`            | Production must use MySQL/MariaDB    |
| `DB_HOST`      | `localhost`        | Database host                        |
| `DB_PORT`      | `3306`             | Database port                        |
| `DB_NAME`      | `you_cedar`        | Database name                        |
| `DB_USER`      | `you_cedar`        | Database user                        |
| `DB_PASS`      | `…`                | Database password                    |
| `MAIL_ENABLED` | `true`             | Enables enquiry alerts **and password reset emails** (off by default) |
| `MAIL_FROM`    | `noreply@cedar…`   | Sender for outgoing mail: an address on your own domain that your mail account may send as |
| `MAIL_DRIVER`  | `smtp`             | `smtp` (a real mailbox or mail service — recommended), `mail` (PHP `mail()`), or `log` (local development only). Empty: `smtp` if `MAIL_HOST` is set, else `mail` |
| `MAIL_HOST`    | `mail.cedar…`      | The SMTP server (the mailbox's *outgoing server*) |
| `MAIL_PORT`    | `587`              | `587` (STARTTLS), `465` (TLS) or `25`  |
| `MAIL_ENCRYPTION` | `tls`           | `tls`, `ssl` or `none`. Empty: port 465 is `ssl`, a server on this machine is `none`, else `tls` |
| `MAIL_USERNAME` | `noreply@cedar…`  | SMTP sign-in, usually the full address |
| `MAIL_PASSWORD` | `…`               | SMTP password                          |
| `MAIL_VERIFY_PEER` | `true`         | Check the mail server's certificate. `false` only while testing |
| `MAIL_CAFILE`  | `…`                | CA bundle file, for a PHP without one (typically PHP on Windows) |
| `MAIL_TIMEOUT` | `8`                | Seconds to wait for the mail server    |
| `MAIL_OAUTH_PROVIDER` | `microsoft` | Sign in with OAuth instead of a password (Microsoft 365, whose password sign-in for SMTP is being switched off). Empty: password. Also selects `smtp.office365.com` on 587 |
| `MAIL_OAUTH_FLOW` | `app`           | `app`: the website signs in as an app (needs the secret below). `user`: a person connects once in Settings. Empty: `app` if a secret is set, else `user` |
| `MAIL_OAUTH_TENANT` | `…`           | Your Directory (tenant) ID, from the app's Overview page in Microsoft Entra |
| `MAIL_OAUTH_CLIENT_ID` | `…`        | The app's Application (client) ID      |
| `MAIL_OAUTH_CLIENT_SECRET` | `…`    | The app's client secret **Value** (`app` flow). A password: keep it out of git |
| `MAIL_OAUTH_AUTHORITY` | `…`        | Leave empty (Microsoft's sign-in address is built in) |
| `MAIL_OAUTH_STORE` | `…`            | Leave empty (saved token: `storage/mail-oauth.json`) |

Email has its own guide, with recipes for Laragon, cPanel, a VPS and the common mail
services, and a table of what each failure message means: **[docs/EMAIL.md](EMAIL.md)**.

Optional: `BASE_PATH` (if installed in a subdirectory), `PRETTY_URLS=false` (if
`mod_rewrite` is unavailable — URLs fall back to `index.php?route=…`).

### `APP_URL` is not optional

The link in a password-reset email is built from `APP_URL`, never from the
request's `Host` header. A visitor chooses that header, so trusting it would let
anyone ask for a reset with `Host: attacker.example` and have the genuine email,
carrying a live token, point at their server. With `APP_ENV=production` and no
`APP_URL`, reset requests therefore fail closed: nothing is sent, the token is
discarded, the visitor still sees the same neutral answer, and the attempt is
written to the log and the audit trail. `php bin/status.php` warns about it.

### Behind a proxy or CDN

Sign-in and enquiry rate limits are keyed on the connection's address
(`REMOTE_ADDR`). That is correct when PHP is reached directly, which is the
normal shared-hosting setup. Behind a reverse proxy or CDN every visitor appears
to share the proxy's address, so the limits would apply to everyone together.
If you deploy that way, have the web server restore the real client address
before PHP runs (Apache `mod_remoteip`, nginx `real_ip_module`) rather than
trusting a forwarded header inside the application.

### Password recovery needs working mail

Staff who forget their password use **Forgot your password?** on the sign-in
page. That link is only useful if `MAIL_ENABLED` is `true` and the mail server
accepts the message. If it does not, the visitor still sees the same neutral
answer (the page must not reveal which addresses are staff), but the failure is
no longer silent: it is written to `storage/logs/php-error.log` and recorded in the
**Activity log** as `user.reset_mail_failed`, with the reason.

Decide what recovery looks like before you need it:

- **With mail configured** (recommended): staff recover themselves. Links are
  single-use and expire after 60 minutes.
- **Without mail**: a Super Administrator or Administrator must issue a
  temporary password from **Staff & users → Edit → Reset password** and pass it
  on. The staff member is then required to choose their own password before
  they can use the dashboard.

Test the mail path after deploying, before you need it:

```bash
php bin/mail-test.php you@your-domain.com
```

or, with no terminal, **Settings → Email delivery → Send a test email to me**. Then
request a reset for your own address and confirm the message arrives with a working
link. A message that is *accepted* but lands in spam is a DNS matter (SPF, DKIM,
DMARC); [docs/EMAIL.md](EMAIL.md) explains it.

## 4. Install the database

Run once over SSH (or cPanel Terminal) from the project root:

```bash
CEDAR_ADMIN_PASSWORD='choose-a-strong-password' php bin/install.php \
  --name "Adaeze Okafor" --email admin@your-domain.com
```

- Creates all tables (idempotent — safe to re-run; existing content is preserved).
- Creates the Super Administrator from `--email` (required), `--name` (optional),
  and a password of at least 14 characters supplied via `--password`, `--password-file`,
  or the `CEDAR_ADMIN_PASSWORD` environment variable (preferred — keeps it out of
  shell history).
- For a **local staging** copy with sample content: `php bin/install.php --demo`
  (refused automatically unless `APP_ENV=local`).

> **SQLite is for local demos and automated tests only; production refuses it.**
> It runs in WAL mode, which coordinates processes through shared memory. On a
> network filesystem, or in a sandbox whose processes cannot share memory, each
> process can then see its own older copy of recent writes (a test inserts a row
> and the web server cannot find it). Set `SQLITE_JOURNAL_MODE=delete` there to
> use the classic rollback journal. Accepted values are `wal` (default), `delete`,
> `truncate` and `persist`; anything else falls back to `wal`.

## 5. Permissions & go-live checklist

```bash
chmod 755 public/uploads storage storage/sessions storage/logs
chmod 600 storage/demo-credentials.json  # local only; delete on production
```

- [ ] Visit `/health` → `{"status":"ok"}`
- [ ] Visit `/login`, sign in, change the admin password (`My account`)
- [ ] `Admin → Settings`: website name, brand tagline, logo upload, phone,
      WhatsApp number, emails, address, social links
- [ ] Replace demo contact details everywhere (footer, contact page, seed enquiries)
- [ ] Add real properties and photographs; approve and publish them
- [ ] Submit a test enquiry and confirm it appears under `Admin → Enquiries`
- [ ] Email: `php bin/mail-test.php you@your-domain.com` (or **Settings → Send a test
      email**) reports ACCEPTED, and the message is in your inbox or spam folder
- [ ] Check `/sitemap.xml`, page titles, and mobile layout
- [ ] Confirm daily backups cover both the database and `public/uploads/`

## 6. Nginx / VPS snippet

```nginx
root /var/www/cedar/public;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.2-fpm.sock; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
location ~* ^/uploads/.*\.php$ { deny all; }
```

## 7. Migrating hosts

1. Export the MySQL database and copy `public/uploads/`.
2. Move the code, set env vars (§3), import the database.
3. Run `php bin/install.php` (creates any missing tables; preserves content).
4. Verify `/health`, `/login`, and a property page.

## 8. Operations

- **Logs:** `storage/logs/php-error.log` (application + PHP errors, including one
  `Mail not sent (…)` line for every email that could not be sent). The **Activity
  log** also records failed enquiry alerts and reset emails. `storage/logs/mail.log`
  exists only with `MAIL_DRIVER=log`, which production refuses.
- **Sessions:** `storage/sessions/` (filesystem; no database sessions to manage).
- **Backups:** database + `public/uploads/` + this file set. No build step, no
  Node.js, no cron jobs required.
- **Agent portal (phase two):** tables ship dormant with `agent_portal_enabled=false`.
  Enabling it requires a reviewed release — see `Admin → Agent portal plan`.
