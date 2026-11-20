<?php
/**
 * Settings for the REAL website, kept in a file instead of in the server's environment.
 *
 * Use this when your hosting plan has nowhere to set environment variables (most shared
 * hosting) or you simply prefer one file. It is plain PHP, so it works on every plan, and
 * where an environment variable and this file both set something, the file wins.
 *
 *   1. Copy this file to the project folder as  config.local.php  (next to README.md):
 *          cp docs/config-examples/live.php config.local.php
 *      or upload a copy through your hosting file manager.
 *   2. Replace every value that looks like a placeholder, and delete the lines you do not need.
 *   3. Check that email works:   php bin/mail-test.php you@yourdomain.com
 *      With no terminal: sign in, open Settings, and press "Send a test email to me".
 *
 * config.local.php holds passwords. It is ignored by git, it must stay outside the folder the
 * web serves (the project's .htaccess also refuses config*.php), and it must never be pasted
 * into a ticket or a chat. This example holds placeholders only and returns an array without
 * printing anything, so it is harmless if it is ever requested by mistake.
 *
 * Every email setting is explained in docs/EMAIL.md.
 */
return [
    // ---- The website ------------------------------------------------------------------------
    'app_env' => 'production',
    // The public address, with no slash at the end. Required in production: the link in a
    // password-reset email is built from it.
    'app_url' => 'https://www.example.com',

    // ---- The database (MySQL or MariaDB: "MySQL Databases" in your hosting panel) -----------
    'db_driver' => 'mysql',
    'db_host' => 'localhost',
    'db_name' => 'youraccount_cedar',
    'db_user' => 'youraccount_cedar',
    'db_pass' => 'the-database-password',

    // ---- Email: enquiry alerts and staff password-reset links --------------------------------
    // Recommended: a real mailbox on your own domain (cPanel: Email > Email Accounts). The
    // "outgoing server" details of that mailbox are what goes below.
    'mail_enabled' => true,
    'mail_driver' => 'smtp',
    'mail_from' => 'noreply@example.com',       // an address this mailbox is allowed to send as
    'mail_host' => 'mail.example.com',          // the mailbox's outgoing (SMTP) server
    'mail_port' => 587,                         // 587 with 'tls', or 465 with 'ssl'
    'mail_encryption' => 'tls',
    'mail_username' => 'noreply@example.com',   // usually the full address
    'mail_password' => 'the-mailbox-password',
];
