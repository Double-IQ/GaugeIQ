<?php
declare(strict_types=1);


/**
 * Add a column only when it is missing. This makes migrations safe to retry
 * after an interrupted install or when a database already contains newer
 * columns than its recorded schema version.
 */
function gaugeIqEnsureColumn(PDO $db, string $table, string $column, string $definition): void
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) ||
        !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }

    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $columns = $db->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $existing) {
            if (strcasecmp((string)$existing['name'], $column) === 0) {
                return;
            }
        }
    } elseif ($driver === 'mysql') {
        $stmt = $db->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
        $stmt->execute([$column]);
        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }
    } else {
        throw new RuntimeException('Unsupported database driver: ' . $driver);
    }

    $db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
}

function migrateDatabase(PDO $db): void
{
    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_schema (
    version INT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_pressure_readings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    temperature_c DOUBLE NULL,
    dew_point_c DOUBLE NULL,
    pressure_hpa DOUBLE NOT NULL,
    humidity_percent DOUBLE NULL,
    wind_speed_kmh DOUBLE NULL,
    wind_direction_degrees DOUBLE NULL,
    rainfall_mm DOUBLE NULL,
    cloud_cover_percent DOUBLE NULL,
    weather_code INT NULL,
    observed_at VARCHAR(64) NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    INDEX idx_gaugeiq_pressure_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gaugeiq_settings (
    `key` VARCHAR(191) NOT NULL PRIMARY KEY,
    `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gaugeiq_push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(2048) NOT NULL,
    subscription_json LONGTEXT NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL,
    UNIQUE KEY uq_gaugeiq_push_endpoint (endpoint(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
    } elseif ($driver === 'sqlite') {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_schema (
    version INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_pressure_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    temperature_c REAL NULL,
    dew_point_c REAL NULL,
    pressure_hpa REAL NOT NULL,
    humidity_percent REAL NULL,
    wind_speed_kmh REAL NULL,
    wind_direction_degrees REAL NULL,
    rainfall_mm REAL NULL,
    cloud_cover_percent REAL NULL,
    weather_code INTEGER NULL,
    observed_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint TEXT NOT NULL UNIQUE,
    subscription_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

        foreach ([
            'pressure_readings' => 'gaugeiq_pressure_readings',
            'settings' => 'gaugeiq_settings',
            'push_subscriptions' => 'gaugeiq_push_subscriptions',
        ] as $old => $new) {
            $oldExists = (bool)$db->query(
                "SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $db->quote($old)
            )->fetchColumn();

            $newCount = (int)$db->query("SELECT COUNT(*) FROM {$new}")->fetchColumn();

            if ($oldExists && $newCount === 0) {
                if ($old === 'pressure_readings') {
                    $db->exec("INSERT INTO {$new} (id, pressure_hpa, observed_at, created_at) SELECT id, pressure_hpa, observed_at, created_at FROM {$old}");
                } else {
                    $db->exec("INSERT INTO {$new} SELECT * FROM {$old}");
                }
            }
        }
    } else {
        throw new RuntimeException('Unsupported database driver: ' . $driver);
    }

    $version = (int)($db->query('SELECT version FROM gaugeiq_schema LIMIT 1')->fetchColumn() ?: 0);

    if ($version === 0) {
        $db->exec("INSERT INTO gaugeiq_schema (version) VALUES (2)");
        $version = 2;
    }

    if ($version === 1) {
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'humidity_percent', $driver === 'mysql' ? 'DOUBLE NULL' : 'REAL NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'wind_speed_kmh', $driver === 'mysql' ? 'DOUBLE NULL' : 'REAL NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'wind_direction_degrees', $driver === 'mysql' ? 'DOUBLE NULL' : 'REAL NULL');

        $db->exec("UPDATE gaugeiq_schema SET version = 2");
        $version = 2;
    }

    if ($version === 2) {
        if ($driver === 'mysql') {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_alert_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    metric VARCHAR(64) NOT NULL,
    condition_type VARCHAR(64) NOT NULL,
    configuration_json TEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    cooldown_minutes INT NOT NULL DEFAULT 60,
    last_triggered_at VARCHAR(64) NULL,
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL,
    INDEX idx_gaugeiq_alert_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gaugeiq_alert_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rule_id BIGINT UNSIGNED NOT NULL,
    observed_at VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    INDEX idx_gaugeiq_alert_events_rule (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
        } else {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    metric TEXT NOT NULL,
    condition_type TEXT NOT NULL,
    configuration_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    cooldown_minutes INTEGER NOT NULL DEFAULT 60,
    last_triggered_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_alert_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rule_id INTEGER NOT NULL,
    observed_at TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);
        }

        $db->exec("UPDATE gaugeiq_schema SET version = 3");
        $version = 3;
    }


    if ($version === 3) {
        if ($driver === 'mysql') {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
        } else {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);
        }
        $db->exec("UPDATE gaugeiq_schema SET version = 4");
        $version = 4;
    }

    if ($version === 4) {
        gaugeIqEnsureColumn($db, 'gaugeiq_push_subscriptions', 'user_agent', $driver === 'mysql' ? 'VARCHAR(512) NULL' : 'TEXT NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_push_subscriptions', 'last_seen_at', $driver === 'mysql' ? 'VARCHAR(64) NULL' : 'TEXT NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_push_subscriptions', 'last_push_at', $driver === 'mysql' ? 'VARCHAR(64) NULL' : 'TEXT NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_push_subscriptions', 'last_push_status', $driver === 'mysql' ? 'VARCHAR(32) NULL' : 'TEXT NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_push_subscriptions', 'last_push_error', 'TEXT NULL');
        $db->exec("UPDATE gaugeiq_schema SET version = 5");
        $version = 5;
    }


    if ($version === 5) {
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'temperature_c', $driver === 'mysql' ? 'DOUBLE NULL' : 'REAL NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'dew_point_c', $driver === 'mysql' ? 'DOUBLE NULL' : 'REAL NULL');
        $db->exec("UPDATE gaugeiq_schema SET version = 6");
        $version = 6;
    }

    if ($version === 6) {
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'rainfall_mm', $driver === 'mysql' ? 'DOUBLE NULL' : 'REAL NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'cloud_cover_percent', $driver === 'mysql' ? 'DOUBLE NULL' : 'REAL NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'weather_code', $driver === 'mysql' ? 'INT NULL' : 'INTEGER NULL');
        $db->exec("UPDATE gaugeiq_schema SET version = 7");
        $version = 7;
    }

    if ($version === 7) {
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'source', $driver === 'mysql' ? 'VARCHAR(64) NULL' : 'TEXT NULL');
        $db->exec("UPDATE gaugeiq_schema SET version = 8");
        $version = 8;
    }
}
