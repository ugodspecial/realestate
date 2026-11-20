# Email — Cedar Tranquility Real Estate

This page says how the website sends email, how to set it up on your own computer
(Laragon) and on a live hosting plan, how to check that it works, and what to do when
it does not.

## What the website emails

Two things, both to your own team. It never emails visitors.

1. **Enquiry alerts.** When someone sends an enquiry, a message goes to the
   *Notification email* in **Settings** (or to the public *Email* there, if the
   notification address is empty). Press *Reply* and the answer goes to the visitor.
2. **Staff password-reset links**, from **Forgot your password?** on the sign-in page.

An enquiry is saved in the dashboard whether or not email works. If an alert cannot be
sent, the **Activity log** records an `enquiry.alert_failed` entry that gives the reason, and
the reason is also written to `storage/logs/php-error.log`. The visitor still sees the normal
thank-you page. A reset email that fails is recorded the same way, as `user.reset_mail_failed`.

## Check it in one minute

Do this once after setting anything up, and again after any change of host.

- **With a terminal** (Laragon: *Menu → Terminal*; hosting: SSH or cPanel Terminal):

  ```
  php bin/mail-test.php you@yourdomain.com
  ```

  Add `--verbose` to see the conversation with the mail server (passwords are hidden).
- **Without a terminal:** sign in as the Super Administrator, open **Settings**, scroll to
  **Email delivery** and press **Send a test email to me**. It runs the same check and
  sends to your own address only.
- `php bin/status.php` shows the same settings and warns about anything missing.

Prefer the **button** when you can. The command line and the web server can run different
PHP versions with different settings (common on shared hosting: the terminal's PHP may have
a function enabled that the website's PHP has switched off), and only the button tests the
PHP that really sends the website's email.

The result is **ACCEPTED** when the mail server took the message, or **NOT SENT** with the
reason in plain words and what to change. *Accepted* is not *delivered*: whether a message
lands in the inbox or in the spam folder is decided by your domain's DNS records
(see [Getting out of the spam folder](#getting-out-of-the-spam-folder)). Always look in the
inbox and the spam folder.

## Choose a method

Set `MAIL_ENABLED=true` and pick a method with `MAIL_DRIVER`:

| `MAIL_DRIVER` | Use it for | What it needs |
|---|---|---|
| `smtp` | **A live website (recommended).** Signs in to a real mailbox or mail service and hands the message over. | `MAIL_HOST`, and usually `MAIL_USERNAME` and `MAIL_PASSWORD` |
| `log` | **Your own computer (Laragon).** Writes each message to `storage/logs/mail.log` and sends nothing, so a reset link is still readable. | Nothing. Refused when `APP_ENV=production`. |
| `mail` | A host whose PHP `mail()` is set up (many cPanel plans, VPS images with a mail program). | Nothing here; the server must have a working mail program. Often lands in spam, and some hosts block it. |

Left empty, the method is `smtp` when `MAIL_HOST` is set and `mail` otherwise, so a site
that already sent mail through `mail()` keeps working unchanged.

## Settings

Each setting can be an **environment variable** (left column) or a line in
**`config.local.php`** (second column; see below). Where both are set, the file wins.

| Environment variable | In `config.local.php` | Meaning |
|---|---|---|
| `MAIL_ENABLED` | `'mail_enabled' => true` | The master switch. `true`, `1`, `yes` or `on` turns email on. Default: off. |
| `MAIL_FROM` | `'mail_from'` | The sender address. Use an address on your own domain that your mail account may send as. If empty, the public *Email* in Settings is used. |
| `MAIL_DRIVER` | `'mail_driver'` | `smtp`, `mail` or `log` (above). |
| `MAIL_HOST` | `'mail_host'` | The mail server, e.g. `mail.yourdomain.com`. |
| `MAIL_PORT` | `'mail_port'` | `587` (STARTTLS), `465` (TLS from the start) or `25`. Left empty it follows `MAIL_ENCRYPTION`. |
| `MAIL_ENCRYPTION` | `'mail_encryption'` | `tls` (STARTTLS, port 587), `ssl` (port 465) or `none`. Left empty: port 465 means `ssl`, a server on this computer means `none`, anything else means `tls`. |
| `MAIL_USERNAME` | `'mail_username'` | The sign-in name, usually the full email address. Leave empty for a server that needs none. |
| `MAIL_PASSWORD` | `'mail_password'` | Its password. Never put it in Settings or in git. |
| `MAIL_VERIFY_PEER` | `'mail_verify_peer'` | Check the server's certificate (default `true`). Set `false` only while testing; the Settings page and `php bin/status.php` flag it. |
| `MAIL_CAFILE` | `'mail_cafile'` | Path to a CA bundle, for a PHP that has none (usually PHP on Windows). |
| `MAIL_TIMEOUT` | `'mail_timeout'` | Seconds to wait for the mail server (default 8). |

With encryption on, the website refuses to send the password to a server that does not
offer it: it stops with a message instead of quietly falling back to plain text.

### Where to put the settings

- **A file, on any hosting plan.** Copy `docs/config-examples/live.php` (live site) or
  `docs/config-examples/local.php` (your computer) to the project folder as
  `config.local.php`, then edit it. A plain PHP file works everywhere, including shared
  hosting that gives you nowhere to set environment variables. It is ignored by git and
  must stay outside the folder the web serves.
- **Environment variables**, where your host offers them (a PHP-FPM pool, `SetEnv` in
  Apache, a process manager). Whether a given shared host passes them to PHP varies, so if
  the check says email is **off** after you set them, use the file instead.

## Laragon (your own computer)

On your own computer you do not want real email going out, and you do not need it to. Three
ways to see what the site would send, easiest first. Whichever you pick, run
`php bin/mail-test.php you@example.com` afterwards: it reports what happened on *your*
machine, which is the only proof that counts.

**1. A log file (no mail server, nothing to install).**

1. Put the project in `C:\laragon\www\cedar`.
2. Copy `docs\config-examples\local.php` to `config.local.php` in that folder. It switches
   email on with `'mail_driver' => 'log'`.
3. In Laragon's terminal, run `php bin/install.php --demo`, then run
   `php -S 127.0.0.1:8080 -t public public/router.php` and open `http://127.0.0.1:8080`. (Laragon
   also makes a `.test` address for the folder, `http://cedar.test`; use it if it shows the site.)
4. Send an enquiry, or request a password reset. Open `storage/logs/mail.log`: the message,
   and the reset link, are there. Nothing leaves your computer.

**2. Laragon's own Mail Catcher.** Laragon can catch what PHP's `mail()` sends: switch it on
under *Menu → Preferences → Mail Catcher* (the exact wording differs a little between Laragon
versions). Caught messages are saved as files, normally under
`C:\laragon\bin\sendmail\output`, and Laragon shows a notification. For this, set
`'mail_driver' => 'mail'` in `config.local.php` (the example file shows the line). The
site itself can only check that PHP's `mail()` accepted the message, so confirm with the test
command above and look in that folder. If the test says PHP could not pass the message to a mail
program, or that nothing answers at the address in `php.ini`, use way 1 or 3.

**3. A mail catcher with a screen** such as **Mailpit** or **MailHog**. Start it and set:

```php
'mail_driver' => 'smtp',
'mail_host' => '127.0.0.1',
'mail_port' => 1025,          // their usual SMTP port; no encryption is needed on 127.0.0.1
```

then open the catcher's page (usually `http://127.0.0.1:8025`).

Laragon also has a *Mail Sender* (*Menu → Preferences → Mail Sender*) that relays `mail()` through a
Gmail account. You do not need it: the site can sign in to Gmail itself (see the table below),
which also works on a live server.

If you run PHP on Windows without Laragon, `mail()` sends to the SMTP server named in `php.ini`
(`SMTP` and `smtp_port`) unless `sendmail_path` is set. The Settings page and the test check
whether anything answers there, and say so when nothing does.

## A live website

### cPanel and other shared hosting

1. In cPanel, open **Email → Email Accounts** and create a mailbox on your domain, for
   example `noreply@yourdomain.com`. Choose a strong password.
2. Open that mailbox's **Connect Devices** (or *Configure Mail Client*) page. Note the
   **outgoing server** (often `mail.yourdomain.com`) and its SMTP port: **587** with
   STARTTLS, or **465** with SSL/TLS.
3. Copy `docs/config-examples/live.php` to `config.local.php` and fill in the email lines:

   ```php
   'mail_enabled' => true,
   'mail_driver' => 'smtp',
   'mail_from' => 'noreply@yourdomain.com',
   'mail_host' => 'mail.yourdomain.com',
   'mail_port' => 587,
   'mail_encryption' => 'tls',
   'mail_username' => 'noreply@yourdomain.com',
   'mail_password' => 'the-mailbox-password',
   ```

4. Run `php bin/mail-test.php you@yourdomain.com`, or use the **Send a test email** button.

A hosting plan can also run without SMTP settings (`mail` method), but mail sent that way is
the most likely to be blocked or sent to spam. Use a mailbox.

### Your own server (VPS)

Either install a mail program such as Postfix and leave `MAIL_DRIVER=mail`, or, more
reliably, use a mail service (below) over SMTP. Many cloud providers (for example AWS,
Google Cloud, Azure and DigitalOcean) block or restrict outgoing mail: port 25 almost
everywhere, and on some plans or new accounts 465 and 587 as well. Check your provider's
policy; a mail service on port 587 (or 2525, where the service offers it) is the usual answer.

### Gmail, and mail services

Copy the host, port and username from the provider's own SMTP page: these have long been
the values, but providers change them.

| Provider | `MAIL_HOST` | Port and encryption | Username and password |
|---|---|---|---|
| Gmail / Google Workspace | `smtp.gmail.com` | 587 `tls` or 465 `ssl` | Full address, and an **app password** (needs 2-Step Verification). Mail goes out as that address, so set `MAIL_FROM` to it. |
| Brevo | `smtp-relay.brevo.com` | 587 `tls` | Your Brevo login, and an SMTP key |
| Mailgun | `smtp.mailgun.org` (`smtp.eu.mailgun.org` in the EU) | 587 `tls` | `postmaster@your-sending-domain`, and its SMTP password |
| SendGrid | `smtp.sendgrid.net` | 587 or 2525 `tls`, 465 `ssl` | The word `apikey`, and an API key |
| Amazon SES | `email-smtp.<region>.amazonaws.com` | 587 `tls` or 465 `ssl` | The SMTP credentials made in the SES console (not your AWS password) |

## Getting out of the spam folder

The website can only hand a message to a mail server. Whether the receiving mailbox
trusts it depends on three DNS records for the domain in `MAIL_FROM`, which only the
domain's DNS settings can fix:

- **SPF** says which servers may send for the domain.
- **DKIM** signs each message.
- **DMARC** says what to do with a message that fails both. `v=DMARC1; p=none` is enough to start.

Your host or mail service shows the exact records to add (cPanel: **Email → Email
Deliverability**; a mail service: its *domain authentication* page). Then:

- Send from an address on your own domain, through that domain's own mailbox or service,
  not from `@gmail.com` through someone else's server.
- A brand-new sender can land in spam at first. Open one message and press *Not spam*.
- If alerts go to a Gmail or Outlook address and still land in spam after the records
  are in place, create a filter there that always accepts mail from `MAIL_FROM`.

## When the test fails

| What it says | What to change |
|---|---|
| *Email is switched off* | Set `MAIL_ENABLED=true` (or `'mail_enabled' => true`). |
| *That host name could not be found* | Check `MAIL_HOST` for a typo. |
| *Nothing is listening there (connection refused)* | Wrong `MAIL_HOST` or `MAIL_PORT`. For a local mail catcher, start it first. |
| *The connection timed out* | The hosting plan or a firewall blocks that port. Use the SMTP host from your hosting panel, or port 587 or 2525 of a mail service. Until it is fixed every enquiry waits up to `MAIL_TIMEOUT` seconds (8 by default) before it is confirmed; lower it if you must live with the block for a while. |
| *Its TLS certificate could not be verified* | Use the host name the certificate names: on shared hosting that is the name your hosting panel gives for the mail server (often `mail.yourdomain.com`, sometimes the server's own name), never an IP address. On Windows PHP, set `MAIL_CAFILE` or `openssl.cafile` in `php.ini`. Last resort while testing: `MAIL_VERIFY_PEER=false`. |
| *This server has switched off the PHP function …* | The host lists network functions in `disable_functions`. Ask the host to allow them, or use `MAIL_DRIVER=mail`. |
| *The server does not offer STARTTLS* | Try `MAIL_ENCRYPTION=ssl` with port 465, or `none` for a server on this computer. |
| *Sign-in was refused* | Check `MAIL_USERNAME` and `MAIL_PASSWORD`. Many hosts want the full address; Gmail wants an app password. |
| *The server refused the sender address* | `MAIL_FROM` must be an address on the account you sign in with. |
| *The server refused the recipient address* | Check the address. A server also refuses other domains until you sign in, so set the username and password. |
| *PHP's mail() could not pass the message to a mail program* | This server has none set up. Use `MAIL_DRIVER=smtp` with a mailbox, or `log` while developing. |
| *MAIL_DRIVER=log is for local development only* | In production use `smtp`. |

## What has and has not been tested

Every release is checked by automated tests that run real mail conversations against a
test server on the same machine, on PHP 8.1 to 8.5: a plain server, sign-in (PLAIN and
LOGIN), STARTTLS, TLS from the first byte, certificate checks, refused senders and
recipients, dropped and silent connections, time-outs, and PHP's `mail()` handing a message
to a mail program. They also check the message itself (readable subject and sender name,
non-English text, no way for a visitor to add headers), that enquiries and reset links
still work when mail fails, and the **Send a test email** button.

They cannot reach your host's mail server, your domain's DNS records, or a Windows PC.
That is why the test above exists: run it once on the real machine, and treat a message
in your inbox as the proof.
