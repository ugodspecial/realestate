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
3. **Never** place `config.local.php`, `storage/*.sqlite`, or
   `storage/demo-credentials.json` on a public server. Delete them if copied by accident.

## 3. Configure the environment

Set these in cPanel (*Software → MultiPHP INI / Environment*) or your process manager:

| Variable       | Example            | Purpose                              |
|----------------|--------------------|--------------------------------------|
| `APP_ENV`      | `production`       | Enables hardened production headers  |
| `APP_URL`      | `https://cedar…`   | Canonical URLs, sitemap, JSON-LD     |
| `DB_DRIVER`    | `mysql`            | Production must use MySQL/MariaDB    |
| `DB_HOST`      | `localhost`        | Database host                        |
| `DB_PORT`      | `3306`             | Database port                        |
| `DB_NAME`      | `you_cedar`        | Database name                        |
| `DB_USER`      | `you_cedar`        | Database user                        |
| `DB_PASS`      | `…`                | Database password                    |
| `MAIL_ENABLED` | `true`             | Enables enquiry alerts **and password reset emails** |
| `MAIL_FROM`    | `noreply@cedar…`   | Sender for outgoing mail             |

Optional: `BASE_PATH` (if installed in a subdirectory), `PRETTY_URLS=false` (if
`mod_rewrite` is unavailable — URLs fall back to `index.php?route=…`).

### Password recovery needs working mail

Staff who forget their password use **Forgot your password?** on the sign-in
page. That link is only useful if `MAIL_ENABLED` is `true` and `MAIL_FROM` is a
real address the mail transport will accept — otherwise the message is never
sent and the request silently does nothing.

Decide what recovery looks like before you need it:

- **With mail configured** (recommended): staff recover themselves. Links are
  single-use and expire after 60 minutes.
- **Without mail**: a Super Administrator or Administrator must issue a
  temporary password from **Staff & users → Edit → Reset password** and pass it
  on. The staff member is then required to choose their own password before
  they can use the dashboard.

Test the mail path after deploying: request a reset for your own address and
confirm the message arrives with a working link.

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

- **Logs:** `storage/logs/php-error.log` (application + PHP errors).
- **Sessions:** `storage/sessions/` (filesystem; no database sessions to manage).
- **Backups:** database + `public/uploads/` + this file set. No build step, no
  Node.js, no cron jobs required.
- **Agent portal (phase two):** tables ship dormant with `agent_portal_enabled=false`.
  Enabling it requires a reviewed release — see `Admin → Agent portal plan`.
