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
    'mail_enabled' => getenv('MAIL_ENABLED') === 'true',
    'mail_from' => getenv('MAIL_FROM') ?: '',
    'agent_portal_enabled' => false,
    'session_name' => 'cedar_session',
    'upload_max_bytes' => 5 * 1024 * 1024,
], $local);
if ($config['app_env'] === 'production' && $config['db_driver'] !== 'mysql') {
    throw new RuntimeException('Production requires MySQL or MariaDB. SQLite is for local demonstrations only.');
}
return $config;
