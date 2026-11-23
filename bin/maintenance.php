<?php
/**
 * Routine housekeeping. Safe to run at any time, and safe to run twice.
 *
 * Usage: php bin/maintenance.php
 * Schedule it daily, e.g. a cron entry:
 *   0 3 * * * cd /path/to/site && php bin/maintenance.php >> storage/logs/maintenance.log 2>&1
 *
 * Nothing here is required for the site to work. What it prevents is slow
 * accretion: spent reset tokens, closed rate-limit windows, and old session
 * and audit rows accumulating forever.
 */
if (PHP_SAPI !== 'cli') exit('CLI only.' . PHP_EOL);
require dirname(__DIR__) . '/app/bootstrap.php';

$report = static function (string $label, int $count): void {
    printf("  %-28s %d\n", $label, $count);
};
$countThenDelete = static function (string $table, string $sql, array $params = []): int {
    $count = (int) Database::value('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $sql, $params);
    if ($count > 0) Database::query('DELETE FROM ' . $table . ' WHERE ' . $sql, $params);
    return $count;
};

echo 'Cedar Tranquility maintenance — app v' . APP_VERSION . PHP_EOL;

// Reset tokens: spent or expired ones are dead weight and a liability.
$tokensExpired = $countThenDelete('password_resets', 'expires_at < ?', [Database::now()]);
$tokensSpent = $countThenDelete('password_resets', 'used_at IS NOT NULL');
$report('Expired reset tokens', $tokensExpired);
$report('Spent reset tokens', $tokensSpent);

// Rate-limit counters past their window.
$ratesExpired = $countThenDelete('rate_limits', 'expires_at < ?', [Database::now()]);
$report('Closed rate-limit windows', $ratesExpired);

// Session files PHP has already collected are removed by PHP itself; the
// application leaves storage/sessions to gc_maxlifetime. Files older than a
// day that PHP never touched are usually abandoned, not live.
$sessionDir = ROOT_PATH . '/storage/sessions';
$staleSessions = 0;
if (is_dir($sessionDir)) {
    $cutoff = time() - 86400;
    foreach (glob($sessionDir . '/sess_*') ?: [] as $file) {
        if (@filemtime($file) < $cutoff) { @unlink($file); $staleSessions++; }
    }
}
$report('Abandoned session files', $staleSessions);

// Audit history is the security record, so it is kept rather than trimmed by
// default. An installation that needs a bound can set AUDIT_RETENTION_DAYS.
$retention = (int) (getenv('AUDIT_RETENTION_DAYS') ?: 0);
if ($retention > 0) {
    $cutoff = gmdate('Y-m-d H:i:s', time() - $retention * 86400);
    $removed = $countThenDelete('audit_logs', 'created_at < ?', [$cutoff]);
    $report('Audit rows older than ' . $retention . 'd', $removed);
} else {
    $report('Audit rows', (int) Database::value('SELECT COUNT(*) FROM audit_logs'));
    echo '  (AUDIT_RETENTION_DAYS unset: history is kept)' . PHP_EOL;
}

// A person's Microsoft 365 sign-in kept by the website (MAIL_OAUTH_FLOW=user) is Microsoft's refresh token,
// which lapses after 90 days without use. Every message sent renews it, so a busy site never notices; a quiet
// one would find out at the next enquiry. Using it once a day, here, costs one short request at most.
if (Mailer::enabled() && MailOAuth::enabled() && MailOAuth::settings()['flow'] === 'user' && MailOAuth::settings()['connected']) {
    $mail = Mailer::settings();
    try {
        MailOAuth::accessToken(['timeout' => $mail['timeout'], 'verify_peer' => $mail['verify_peer'], 'cafile' => $mail['cafile']]);
        echo '  ' . str_pad($mail['oauth'] . ' sign-in', 28) . 'renewed' . PHP_EOL;
    } catch (RuntimeException $e) {
        echo 'WARNING: the ' . $mail['oauth'] . ' sign-in could not be renewed: ' . $e->getMessage() . PHP_EOL;
        $advice = Mailer::hint($e->getMessage());
        if ($advice !== '') echo '         What to try: ' . $advice . PHP_EOL;
    }
}

echo 'Done.' . PHP_EOL;
exit(0);
