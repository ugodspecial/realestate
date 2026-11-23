<?php
$local = is_file(ROOT_PATH . '/config.local.php') ? require ROOT_PATH . '/config.local.php' : [];
$config = array_replace([
    'app_name' => 'Cedar Tranquility Real Estate',
    'app_env' => getenv('APP_ENV') ?: 'production',
    'app_url' => getenv('APP_URL') ?: '',
    'base_path' => getenv('BASE_PATH') ?: '',
    'pretty_urls' => getenv('PRETTY_URLS') !== 'false',
    'demo_mode' => getenv('DEMO_MODE') === 'true',
    'db_driver' => getenv('DB_DRIVER') ?: 'mysql',
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'cedar_realestate',
    'db_user' => getenv('DB_USER') ?: '',
    'db_pass' => getenv('DB_PASS') ?: '',
    'sqlite_path' => ROOT_PATH . '/storage/cedar.sqlite',
    // SQLite only (local demos and CI: production refuses it). WAL is the default, but it
    // coordinates processes through shared memory, which a network filesystem or a locked-down
    // sandbox cannot provide - there each process then sees its own, older copy of the data.
    // SQLITE_JOURNAL_MODE=delete selects the classic rollback journal for those cases.
    'sqlite_journal_mode' => getenv('SQLITE_JOURNAL_MODE') ?: 'wal',
    // Email. MAIL_ENABLED is the master switch; the rest says how a message leaves (see
    // app/Core/Mailer.php). MAIL_DRIVER: mail = PHP's mail(), smtp = a mail server named below,
    // log = write to storage/logs/mail.log (local development only). Left empty it is smtp when
    // MAIL_HOST is set and mail otherwise. MAIL_ENCRYPTION: tls (STARTTLS, port 587), ssl (port
    // 465) or none; left empty it follows the port and the host. Secrets belong in the
    // environment or config.local.php, never in the settings screen or the repository.
    'mail_enabled' => in_array(strtolower((string) getenv('MAIL_ENABLED')), ['true', '1', 'yes', 'on'], true),
    'mail_from' => getenv('MAIL_FROM') ?: '',
    'mail_driver' => getenv('MAIL_DRIVER') ?: '',
    'mail_host' => getenv('MAIL_HOST') ?: '',
    'mail_port' => getenv('MAIL_PORT') ?: '',
    'mail_encryption' => getenv('MAIL_ENCRYPTION') ?: '',
    'mail_username' => getenv('MAIL_USERNAME') ?: '',
    'mail_password' => getenv('MAIL_PASSWORD') ?: '',
    // Check the mail server's TLS certificate. Turn off (false) only while testing.
    'mail_verify_peer' => !in_array(strtolower((string) getenv('MAIL_VERIFY_PEER')), ['false', '0', 'no', 'off'], true),
    // A CA bundle file, for a PHP that has none of its own (typically PHP on Windows).
    'mail_cafile' => getenv('MAIL_CAFILE') ?: '',
    'mail_timeout' => getenv('MAIL_TIMEOUT') ?: '8',
    // Signing in to the mail server with OAuth instead of a password, for providers that no longer take
    // one (Microsoft 365). MAIL_OAUTH_PROVIDER=microsoft turns it on; the rest says how the website
    // proves itself to Microsoft. See app/Core/MailOAuth.php and "Microsoft 365" in docs/EMAIL.md. The
    // client secret is a password: environment or config.local.php only, never the settings screen or git.
    'mail_oauth_provider' => getenv('MAIL_OAUTH_PROVIDER') ?: '',
    'mail_oauth_flow' => getenv('MAIL_OAUTH_FLOW') ?: '',
    'mail_oauth_tenant' => getenv('MAIL_OAUTH_TENANT') ?: '',
    'mail_oauth_client_id' => getenv('MAIL_OAUTH_CLIENT_ID') ?: '',
    'mail_oauth_client_secret' => getenv('MAIL_OAUTH_CLIENT_SECRET') ?: '',
    'mail_oauth_authority' => getenv('MAIL_OAUTH_AUTHORITY') ?: '',
    'mail_oauth_store' => getenv('MAIL_OAUTH_STORE') ?: ROOT_PATH . '/storage/mail-oauth.json',
    'mail_log_file' => ROOT_PATH . '/storage/logs/mail.log',
    'agent_portal_enabled' => false,
    'session_name' => 'cedar_session',
    'upload_max_bytes' => 5 * 1024 * 1024,
], $local);
if ($config['app_env'] === 'production' && $config['db_driver'] !== 'mysql') {
    throw new RuntimeException('Production requires MySQL or MariaDB. SQLite is for local demonstrations only.');
}
return $config;
