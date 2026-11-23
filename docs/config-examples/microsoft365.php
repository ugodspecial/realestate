<?php
/**
 * Email through Microsoft 365 (Exchange Online) WITHOUT a password: the website signs in with OAuth.
 *
 * Why: Microsoft is switching password sign-in off for SMTP (by default from the end of December 2026
 * for existing tenants; new tenants never get it). A mailbox there can then only be used with an access
 * token, which the website asks Microsoft for with the app registration named below.
 *
 * These are the EMAIL lines of config.local.php. Copy them over the email lines of the file you made from
 * docs/config-examples/live.php (the database and APP_URL lines stay as they are). The steps that produce the
 * three values below - one-off work in Microsoft Entra and Exchange Online, for a Microsoft 365 administrator -
 * are under "Microsoft 365" in docs/EMAIL.md. Then run  php bin/mail-test.php you@yourdomain.com  or press
 * "Send a test email to me" in Dashboard > Settings.
 *
 * This is the "app" way: the website signs in as an app and needs no person to sign in. If nobody can run the
 * Exchange PowerShell commands it needs, use the "connect" way instead: delete the client-secret line, and
 * press Connect in Dashboard > Settings > Email delivery (docs/EMAIL.md explains both).
 *
 * The client secret is a password. Keep this file out of git and out of the web folder (the project's .htaccess
 * and .gitignore already do both for config.local.php).
 */
return [
    'mail_enabled' => true,
    'mail_driver' => 'smtp',                  // the host is smtp.office365.com, port 587, STARTTLS: set for you
    'mail_oauth_provider' => 'microsoft',

    // The mailbox that sends the mail. Microsoft 365 only sends as this mailbox or an address it has
    // "Send As" rights for, so the sender is the same address.
    'mail_username' => 'noreply@yourcompany.com',
    'mail_from' => 'noreply@yourcompany.com',

    // From the app's Overview page in Microsoft Entra, and Certificates & secrets (the secret's Value).
    'mail_oauth_flow' => 'app',
    'mail_oauth_tenant' => '00000000-0000-0000-0000-000000000000',        // Directory (tenant) ID
    'mail_oauth_client_id' => '00000000-0000-0000-0000-000000000000',     // Application (client) ID
    'mail_oauth_client_secret' => 'the-client-secret-value',              // made in the portal, a secret lasts 24 months at most: note its expiry
];
