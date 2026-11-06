<?php
// 002 — Brand settings. Unifies the website name under a single `brand_name`
// key, adds the `brand_tagline` used for the small line under the wordmark,
// and adds the `brand_logo` slot for an uploaded logo. Existing installs that
// only have `site_name` keep their current text.
return [
    'description' => 'Brand name, tagline, and logo settings',
    'up' => static function (PDO $pdo, string $driver): void {
        $upsert = static function (string $key, string $value) use ($pdo, $driver): void {
            if ($driver === 'mysql') {
                $pdo->prepare('INSERT INTO settings (key_name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$key, $value]);
            } else {
                $pdo->prepare('INSERT INTO settings (key_name,value) VALUES (?,?) ON CONFLICT(key_name) DO UPDATE SET value=excluded.value')->execute([$key, $value]);
            }
        };
        $current = static function (string $key) use ($pdo): string {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key_name=?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? '' : (string)$value;
        };

        $brand = trim($current('brand_name'));
        $site = trim($current('site_name'));
        // Only fill in keys that are missing, and never overwrite a value the
        // site owner has already chosen.
        if ($brand === '' && $site !== '') $upsert('brand_name', $site);
        if ($brand !== '' && $site !== '' && $brand !== $site) $upsert('site_name', $brand);
        if (trim($current('brand_tagline')) === '') $upsert('brand_tagline', 'REAL ESTATE');
        if (trim($current('brand_logo')) === '') $upsert('brand_logo', '');
    },
];
