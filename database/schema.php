<?php
// One portable schema; the production target is MySQL 8 / MariaDB 10.4+.
return static function (string $driver): array {
    $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $ref = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
    $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tables = [
        "CREATE TABLE IF NOT EXISTS settings (key_name VARCHAR(100) PRIMARY KEY, value TEXT NOT NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS users (id $id, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL, active TINYINT NOT NULL DEFAULT 1, session_version INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, last_login DATETIME NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS categories (id $id, name VARCHAR(80) NOT NULL, slug VARCHAR(100) NOT NULL UNIQUE, description TEXT NOT NULL, icon VARCHAR(30) NOT NULL DEFAULT 'home')$suffix",
        "CREATE TABLE IF NOT EXISTS locations (id $id, name VARCHAR(100) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, city VARCHAR(80) NOT NULL, description TEXT NOT NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS properties (id $id, listing_id VARCHAR(40) NOT NULL UNIQUE, slug VARCHAR(180) NOT NULL UNIQUE, title VARCHAR(180) NOT NULL, category_id $ref NOT NULL, location_id $ref NOT NULL, price DECIMAL(15,2) NOT NULL, deal VARCHAR(10) NOT NULL DEFAULT 'sale', status VARCHAR(20) NOT NULL DEFAULT 'available', publish_status VARCHAR(20) NOT NULL DEFAULT 'draft', featured TINYINT NOT NULL DEFAULT 0, beds INTEGER NOT NULL DEFAULT 0, baths INTEGER NOT NULL DEFAULT 0, parking INTEGER NOT NULL DEFAULT 0, area_sqm DECIMAL(12,2) NOT NULL DEFAULT 0, address VARCHAR(255) NOT NULL, description TEXT NOT NULL, amenities TEXT NOT NULL, latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL, created_by $ref NULL, approved_by $ref NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, seo_title VARCHAR(180) NOT NULL DEFAULT '', seo_description VARCHAR(320) NOT NULL DEFAULT '', FOREIGN KEY(category_id) REFERENCES categories(id), FOREIGN KEY(location_id) REFERENCES locations(id), FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS property_images (id $id, property_id $ref NOT NULL, path VARCHAR(255) NOT NULL, alt VARCHAR(255) NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, is_cover TINYINT NOT NULL DEFAULT 0, FOREIGN KEY(property_id) REFERENCES properties(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS enquiries (id $id, reference VARCHAR(40) NOT NULL UNIQUE, property_id $ref NULL, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(40) NOT NULL, message TEXT NOT NULL, type VARCHAR(30) NOT NULL DEFAULT 'enquiry', preferred_date VARCHAR(20) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'new', admin_notes TEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(property_id) REFERENCES properties(id) ON DELETE SET NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS testimonials (id $id, name VARCHAR(120) NOT NULL, location VARCHAR(120) NOT NULL, quote TEXT NOT NULL, rating INTEGER NOT NULL DEFAULT 5, approved TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS audit_logs (id $id, user_id $ref NULL, action VARCHAR(80) NOT NULL, entity_type VARCHAR(40) NOT NULL, entity_id $ref NULL, details TEXT NOT NULL, ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS rate_limits (key_hash VARCHAR(64) PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 1, expires_at DATETIME NOT NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS password_resets (token_hash VARCHAR(64) PRIMARY KEY, user_id $ref NOT NULL, expires_at DATETIME NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        // Phase two: dormant until a reviewed release explicitly enables the agent portal.
        "CREATE TABLE IF NOT EXISTS agent_profiles (id $id, user_id $ref NOT NULL UNIQUE, business_name VARCHAR(160) NOT NULL, phone VARCHAR(40) NOT NULL, bio TEXT NOT NULL, verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified', created_at DATETIME NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS subscription_packages (id $id, name VARCHAR(100) NOT NULL, price DECIMAL(15,2) NOT NULL, currency VARCHAR(3) NOT NULL DEFAULT 'NGN', duration_days INTEGER NOT NULL, listing_limit INTEGER NOT NULL, featured_limit INTEGER NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 0)$suffix",
        "CREATE TABLE IF NOT EXISTS subscriptions (id $id, agent_id $ref NOT NULL, package_id $ref NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', starts_at DATETIME NULL, expires_at DATETIME NULL, auto_renew TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, FOREIGN KEY(agent_id) REFERENCES agent_profiles(id), FOREIGN KEY(package_id) REFERENCES subscription_packages(id))$suffix",
        "CREATE TABLE IF NOT EXISTS payment_events (id $id, subscription_id $ref NOT NULL, provider VARCHAR(20) NOT NULL, provider_reference VARCHAR(190) NOT NULL, amount DECIMAL(15,2) NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', payload_hash VARCHAR(64) NOT NULL, verified_at DATETIME NULL, created_at DATETIME NOT NULL, UNIQUE(provider,provider_reference), FOREIGN KEY(subscription_id) REFERENCES subscriptions(id))$suffix",
    ];
    $indexes = [
        'CREATE INDEX idx_property_search ON properties(publish_status,status,deal,category_id,location_id,price)',
        'CREATE INDEX idx_property_featured ON properties(publish_status,featured,created_at)',
        'CREATE INDEX idx_images_property ON property_images(property_id,sort_order)',
        'CREATE INDEX idx_enquiry_status ON enquiries(status,created_at)',
        'CREATE INDEX idx_audit_date ON audit_logs(created_at)',
        'CREATE INDEX idx_subscription_expiry ON subscriptions(agent_id,status,expires_at)',
    ];
    return [$tables,$indexes];
};
