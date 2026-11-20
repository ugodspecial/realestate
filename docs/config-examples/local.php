<?php
/**
 * Settings for developing on your own computer (Laragon, XAMPP, or plain "php -S").
 *
 * Nothing here needs a database server or a mail server, so it works straight away:
 *
 *   1. Copy this file to the project folder as  config.local.php  (next to README.md).
 *          Windows:  copy docs\config-examples\local.php config.local.php
 *          Others:   cp docs/config-examples/local.php config.local.php
 *   2. Install the demo content:   php bin/install.php --demo
 *   3. Open the site:              php -S 127.0.0.1:8080 -t public public/router.php
 *      and visit http://127.0.0.1:8080. (In Laragon, with the folder under C:\laragon\www, the
 *      http://<folder>.test address it creates may also show the site; if it does not, use the
 *      line above.)
 *
 * No email leaves your computer from here. With 'log', every message is written to
 * storage/logs/mail.log instead - password-reset links included, so you can open them.
 * Two other ways to see what the site sends (Laragon's own Mail Catcher, or Mailpit with a
 * screen) are under "Laragon" in docs/EMAIL.md, with the lines to use below.
 *
 * This file is for your computer only. The live website uses docs/config-examples/live.php.
 */
return [
    'app_env' => 'local',

    // The demo database lives in storage/cedar.sqlite. Laragon already runs MySQL: to use it
    // instead, change 'sqlite' to 'mysql', uncomment the four lines under it, and create an
    // empty database called "cedar" first (HeidiSQL or phpMyAdmin).
    'db_driver' => 'sqlite',
    // 'db_host' => '127.0.0.1',
    // 'db_name' => 'cedar',
    // 'db_user' => 'root',
    // 'db_pass' => '',                // Laragon's MySQL starts with an empty root password

    'mail_enabled' => true,
    'mail_driver' => 'log',                 // each message is written to storage/logs/mail.log
    'mail_from' => 'noreply@cedar.test',

    // Instead of 'log', to use Laragon's Mail Catcher (switch it on under Menu > Preferences):
    //     'mail_driver' => 'mail',
    // or a catcher with a screen, such as Mailpit or MailHog:
    //     'mail_driver' => 'smtp',  'mail_host' => '127.0.0.1',  'mail_port' => 1025,
];
