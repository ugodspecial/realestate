# Email — Cedar Tranquility Real Estate

This page says how the website sends email, how to set it up on your own computer
(Laragon) and on a live hosting plan, including **Microsoft 365**, which needs OAuth
instead of a password, how to check that it works, and what to do when it does not.

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

Mail that accepts **only OAuth**, such as Microsoft 365, is `smtp` plus `MAIL_OAUTH_PROVIDER`:
see [Microsoft 365](#microsoft-365-mail-that-needs-oauth).

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
| `MAIL_OAUTH_PROVIDER` | `'mail_oauth_provider'` | `microsoft` signs in with OAuth instead of a password (**[Microsoft 365](#microsoft-365-mail-that-needs-oauth)**). Empty, the default: a password, as before. Naming it also selects SMTP, `smtp.office365.com`, port 587 and STARTTLS, so `MAIL_HOST`, `MAIL_PORT` and `MAIL_ENCRYPTION` can stay empty. `MAIL_USERNAME` is then the mailbox, and `MAIL_PASSWORD` is not used. |
| `MAIL_OAUTH_FLOW` | `'mail_oauth_flow'` | `app` (the website signs in as an app; needs a client secret) or `user` (a person connects once in Settings). Empty: `app` when a client secret is set, otherwise `user`. |
| `MAIL_OAUTH_TENANT` | `'mail_oauth_tenant'` | Your **Directory (tenant) ID** (a GUID), from the app's Overview page in Microsoft Entra. |
| `MAIL_OAUTH_CLIENT_ID` | `'mail_oauth_client_id'` | The app's **Application (client) ID**, from the same page. |
| `MAIL_OAUTH_CLIENT_SECRET` | `'mail_oauth_client_secret'` | The app's client secret **Value** (`app` flow only). A password: never in Settings or in git. |
| `MAIL_OAUTH_AUTHORITY` | `'mail_oauth_authority'` | Leave empty. The address of Microsoft's sign-in service (`https://login.microsoftonline.com`) is built in; this exists for the tests, and for Microsoft's national clouds, which have other addresses (not tried). |
| `MAIL_OAUTH_STORE` | `'mail_oauth_store'` | Leave empty. Where the website keeps the access token it was given and a connected person's sign-in: `storage/mail-oauth.json`. Set a path only if `storage/` cannot be written. |

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
| Microsoft 365 | `smtp.office365.com` (built in) | 587 `tls` | **No password: OAuth.** Microsoft is switching password sign-in off for SMTP; see [Microsoft 365](#microsoft-365-mail-that-needs-oauth). |

## Microsoft 365 (mail that needs OAuth)

Some mail systems no longer let a program sign in with a mailbox address and a password.
**Microsoft 365** (Exchange Online) is the one that matters here. Microsoft's published plan,
as of November 2026: from the **end of December 2026** password sign-in for SMTP is **off by
default for existing organisations** (an administrator can switch it back on for a while),
it is **not available at all to organisations created after that**, and it will be removed
completely on a date Microsoft has not announced yet (second half of 2027). A password setup
then fails with `550 5.7.30 Basic authentication is not supported for Client Submission`.
Dates like these have moved before: check Microsoft's current notice for your organisation.

The website can sign in **with OAuth instead**. Nobody types a password. The website asks
Microsoft for an *access token* (a key for this one job, good for about an hour), shows it
to Microsoft's mail server, and sends. It asks again when the key runs out, not for every
email.

**You do not have to send through Microsoft.** If your domain's mailboxes are in Microsoft
365 but nobody wants to do the work below, send the website's email through one of the mail
services in the table above (Brevo, Mailgun, SendGrid, Amazon SES). That takes about ten
minutes, is not touched by Microsoft's change, and needs the SPF and DKIM records the
service gives you added to your domain. Use OAuth when the mail really has to leave from a
Microsoft 365 mailbox.

### Two ways to sign in

| | **App** (`MAIL_OAUTH_FLOW=app`) | **Person** (`MAIL_OAUTH_FLOW=user`) |
|---|---|---|
| How | The website signs in as an app registered in your Microsoft Entra. | A person signs in as the mailbox once, and the website keeps the permission it was given. |
| One-off work | An administrator: an app registration, a client secret, a permission, three PowerShell commands, one switch on the mailbox. | An administrator registers the app (no secret, no PowerShell). The mailbox owner presses **Connect** and signs in. |
| Afterwards | Nothing, until the client secret expires (24 months at most when made in the portal): put the date in a calendar. | It lasts while the website sends something now and then. It ends when the mailbox's password is changed, when an administrator revokes the person's sessions, or after 90 days with no mail: press **Connect** again. |
| Choose it for | A live site you want to forget about. | When nobody can run the PowerShell commands, or to try this today. |

Both need **Authenticated SMTP** switched on for the mailbox, and mail goes out **as that
mailbox**. `MAIL_FROM` is therefore the mailbox itself (left empty, that is what is used);
Microsoft 365 refuses any other sender unless the mailbox has been given *Send As*
permission for it.

### The app way, step by step

Do this once, signed in to Microsoft as an administrator. The screens are Microsoft's and
get renamed from time to time; the names below are the ones its documentation uses.

1. **Register the app.** Microsoft Entra admin center → *App registrations* → *New
   registration*. Name it (for example `Cedar website email`), choose *Accounts in this
   organizational directory only*, and register. On the app's **Overview** page copy the
   **Directory (tenant) ID** and the **Application (client) ID**.
2. **Make a client secret.** *Certificates & secrets* → *New client secret*. Choose the
   longest expiry and note the date. Copy the **Value** straight away: Microsoft shows it once.
   Do not copy the *Secret ID* next to it, which looks similar and gives the error
   *The client secret is wrong*.
3. **Give the app the SMTP permission.** *API permissions* → *Add a permission* → *APIs my
   organization uses* → **Office 365 Exchange Online** → *Application permissions* →
   **SMTP.SendAsApp** → *Add*. Then press **Grant admin consent**.
4. **Let that app use the mailbox.** In Exchange Online PowerShell (install it once with
   `Install-Module ExchangeOnlineManagement`):

   ```powershell
   Connect-ExchangeOnline -Organization <Directory (tenant) ID>
   New-ServicePrincipal -AppId <Application (client) ID> -ObjectId <Object ID>
   Add-MailboxPermission -Identity "noreply@yourcompany.com" -User <Object ID> -AccessRights FullAccess
   ```

   Take `<Object ID>` from **Enterprise applications** → your app → *Overview*, **not** from
   *App registrations*. The two pages show different IDs, and using the wrong one is the
   usual reason for `535 5.7.3 Authentication unsuccessful`.
5. **Switch on Authenticated SMTP for the mailbox.** Microsoft 365 admin center → *Users* →
   *Active users* → the mailbox → *Mail* → *Manage email apps* → tick **Authenticated SMTP** →
   *Save*. (Microsoft's *security defaults*, or an authentication policy that blocks SMTP, can
   keep it off even after this: see Microsoft's article *Enable or disable SMTP AUTH in
   Exchange Online*.) Changes in Exchange Online can take a little while to apply.
6. **Tell the website.** `docs/config-examples/microsoft365.php` has these lines ready to copy
   into `config.local.php`. Fill in the mailbox, the two IDs and the secret's Value:

   ```php
   'mail_enabled' => true,
   'mail_driver' => 'smtp',
   'mail_oauth_provider' => 'microsoft',
   'mail_oauth_flow' => 'app',
   'mail_username' => 'noreply@yourcompany.com',
   'mail_from' => 'noreply@yourcompany.com',
   'mail_oauth_tenant' => '<Directory (tenant) ID>',
   'mail_oauth_client_id' => '<Application (client) ID>',
   'mail_oauth_client_secret' => '<the secret Value>',
   ```

   There is no host, port or password: Microsoft's server (`smtp.office365.com`, port 587,
   STARTTLS) is built in, and no password is used.
7. **Test it:** `php bin/mail-test.php you@yourcompany.com`, or the **Send a test email**
   button. When the secret is replaced later, the new one is tried at once; the website never
   reuses a token that was obtained with the old one.

### The person way, step by step

1. **Register the app** as in step 1 above, then open *Authentication* and set **Allow public
   client flows** to **Yes**. There is no client secret.
2. *API permissions* → *Add a permission* → **Microsoft Graph** → *Delegated permissions* →
   add **SMTP.Send** and **offline_access** (and *openid* and *profile*, if they are not
   already listed). Grant admin consent if your organisation requires it.
3. **Switch on Authenticated SMTP** for the mailbox (step 5 above).
4. **Tell the website.** The secret, the username and `mail_from` are left out: they come from
   the person who signs in.

   ```php
   'mail_enabled' => true,
   'mail_driver' => 'smtp',
   'mail_oauth_provider' => 'microsoft',
   'mail_oauth_flow' => 'user',
   'mail_oauth_tenant' => '<Directory (tenant) ID>',
   'mail_oauth_client_id' => '<Application (client) ID>',
   ```

5. **Connect.** Sign in to the dashboard as the Super Administrator → **Settings** → **Email
   delivery** → *Microsoft 365 sign-in* → **Connect**. The page shows a short code and a Microsoft
   address. Open the address (on any device), type the code, sign in **as the mailbox that
   should send the email**, accept, come back and press **I have signed in**. From a terminal
   the same thing is `php bin/mail-oauth.php connect`.
6. **Test it** as above.

What is kept is Microsoft's *refresh token*, in `storage/mail-oauth.json`. It is a secret like
`config.local.php`: created readable by its owner only, refused by `.htaccess`, ignored by git.
`php bin/mail-oauth.php status` says whether the site is connected and as whom;
**Disconnect** (or `php bin/mail-oauth.php disconnect`) makes the website forget it. That
does not withdraw the permission at Microsoft: an administrator does that in Microsoft Entra
(*Enterprise applications* → the app → *Permissions*), or by revoking the person's sessions.
If you schedule `php bin/maintenance.php` (see [DEPLOYMENT.md](DEPLOYMENT.md)) it also uses
the connection once a day, so a quiet site does not lose it to the 90 days.

### Good to know

- **Speed.** A new token costs one extra request to Microsoft, about once an hour, not per
  email; the website gives up on it after `MAIL_TIMEOUT` seconds. Mail is still sent while
  the visitor waits for the thank-you page.
- **Limits.** Microsoft limits SMTP submission to about 30 messages a minute and 10,000
  recipients a day per mailbox. A website that emails its own team is nowhere near either.
- **Nothing secret leaks.** The client secret, tokens and the refresh token are never shown
  on a page, printed by `--verbose`, or written to the error log or Activity log. With
  encryption on, the default, a token is never sent to a mail server before the connection
  is encrypted (Settings warns if you switch encryption off).
- **Not included.** Sending through the Microsoft Graph API (HTTPS) instead of SMTP; a
  "Sign in with Microsoft" button that sends the browser away and back; and OAuth for Google
  (a Gmail or Google Workspace mailbox still uses an app password, above). Personal
  Outlook.com accounts are not covered either, nor is going out to Microsoft through an
  HTTP proxy: the website connects to it directly.

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
| *Microsoft would not issue an access token: The client secret is wrong* (or *has expired*) | The secret's **Value**, not its Secret ID, goes in `MAIL_OAUTH_CLIENT_SECRET`. For an expired one, make a new secret in Microsoft Entra (*Certificates & secrets*). |
| *Microsoft would not issue an access token: No app with that client ID exists in that tenant* | `MAIL_OAUTH_CLIENT_ID` and `MAIL_OAUTH_TENANT` both come from the app's Overview page in Microsoft Entra. |
| *Microsoft would not issue an access token* (a consent or permission message) | *API permissions* → **Grant admin consent**, and check that **SMTP.SendAsApp** (app way) or **SMTP.Send** (person way) is listed. |
| *OAuth sign-in was refused* (the reply that follows starts with 535 5.7.3) | In this order: Authenticated SMTP is ticked for the mailbox; `MAIL_USERNAME` is its exact address; for the app way, `New-ServicePrincipal` was given the **Enterprise application's** Object ID and `Add-MailboxPermission` gave it `FullAccess`. Exchange can take a little while to apply a change. |
| *… 5.7.139 … SmtpClientAuthentication is disabled* | Switch on Authenticated SMTP, for the mailbox (*Manage email apps*) or for the whole organisation. |
| *… 550 5.7.30 Basic authentication is not supported* | Microsoft has switched off password sign-in for this organisation. Use OAuth: [Microsoft 365](#microsoft-365-mail-that-needs-oauth). |
| *Microsoft 365 is not connected yet*, or *Microsoft no longer accepts the saved sign-in* | Settings → **Email delivery** → **Connect** (or *Connect again*). The person way needs this after a password change on the mailbox, after sessions are revoked, or after 90 days with no mail. |
| *Could not reach the Microsoft sign-in service* | The server must be able to open `login.microsoftonline.com` on port 443 (and `smtp.office365.com` on port 587). Check its internet access and firewall. |
| *The Microsoft sign-in service answered with something that is not …* (a token, or a sign-in code) | Something between this server and Microsoft is answering with a page of its own: a proxy, a filter, or a firewall's sign-in page. The server has to reach `login.microsoftonline.com` directly on port 443. |
| *A Conditional Access rule … blocked this sign-in* | A Microsoft 365 administrator must exclude this app, or this server's address, from that rule. |

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

OAuth sign-in is tested against **stand-ins written from Microsoft's published
documentation**, not against Microsoft: a fake token service (over HTTPS, with real web
framing: chunked and cut-off answers, redirects, over-long headers, oversized and hostile
replies) and fake SMTP servers that only accept `XOAUTH2`, including the Gmail-style `334`
challenge, "Authenticated SMTP is switched off" and "Basic authentication is not supported".
They prove the website's half: the exact requests, the `XOAUTH2` sign-in string (compared byte
for byte with the examples in Microsoft's and Google's documents), saving and reusing a
token, the refresh after it runs out, the retry after a server refuses a saved token, the
connect-a-person steps in Settings and on the command line, and that no secret reaches a page,
a log or a message.

They **cannot** show that a particular Microsoft 365 organisation is set up the way Microsoft
expects, which is why the test button exists. Nobody has yet run either way end to end against
a real organisation, so three things are still taken from documentation alone: the exact
wording of Microsoft's error messages (the plain-words versions are keyed on the codes it
documents), the address of the delegated permission (`https://outlook.office.com/SMTP.Send`,
as in Microsoft's IMAP/POP/SMTP OAuth article; one third-party guide uses
`outlook.office365.com` instead, and if Microsoft refuses it at **Connect**, `SCOPE_USER` in
`app/Core/MailOAuth.php` is the single line to change), and the PowerShell steps, which follow
the same article. If something here differs from what you see, trust Microsoft's page and
tell us.
