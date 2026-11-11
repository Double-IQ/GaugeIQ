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

    try {
        $db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    } catch (PDOException $e) {
        // SQLite can report a duplicate column if the schema changed between
        // PRAGMA inspection and ALTER TABLE, or if a partial install already
        // added it. Treat that specific condition as an already-completed
        // migration; rethrow every other database error.
        if ($driver === 'sqlite' && stripos($e->getMessage(), 'duplicate column name: ' . $column) !== false) {
            return;
        }
        throw $e;
    }
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
    pressure_hpa DOUBLE NOT NULL,
    humidity_percent DOUBLE NULL,
    wind_speed_kmh DOUBLE NULL,
    wind_direction_degrees DOUBLE NULL,
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
    pressure_hpa REAL NOT NULL,
    humidity_percent REAL NULL,
    wind_speed_kmh REAL NULL,
    wind_direction_degrees REAL NULL,
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

    if ($version === 8) {
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'feels_like_c', $driver === 'mysql' ? 'DOUBLE NULL' : 'REAL NULL');
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'forecast_json', $driver === 'mysql' ? 'LONGTEXT NULL' : 'TEXT NULL');
        $db->exec("UPDATE gaugeiq_schema SET version = 9");
        $version = 9;
    }

    if ($version === 9) {
        if ($driver === 'mysql') {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_rain_forecasts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forecast_at VARCHAR(64) NOT NULL,
    precipitation_probability_percent DOUBLE NOT NULL,
    precipitation_mm DOUBLE NULL,
    rain_mm DOUBLE NULL,
    temperature_c DOUBLE NULL,
    source VARCHAR(64) NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    UNIQUE KEY uq_gaugeiq_rain_forecast_time (forecast_at),
    INDEX idx_gaugeiq_rain_forecast_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
        } else {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_rain_forecasts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    forecast_at TEXT NOT NULL UNIQUE,
    precipitation_probability_percent REAL NOT NULL,
    precipitation_mm REAL NULL,
    rain_mm REAL NULL,
    temperature_c REAL NULL,
    source TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_gaugeiq_rain_forecast_source ON gaugeiq_rain_forecasts(source);
SQL);
        }
        $db->exec("UPDATE gaugeiq_schema SET version = 10");
        $version = 10;
    }


    if ($version === 10) {
        if ($driver === 'mysql') {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_weather_map_frames (
    slot_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    frame_type VARCHAR(8) NOT NULL,
    frame_time VARCHAR(32) NOT NULL,
    file_url VARCHAR(1024) NOT NULL,
    model_run_time VARCHAR(32) NOT NULL,
    refreshed_at VARCHAR(64) NOT NULL,
    INDEX idx_gaugeiq_weather_map_time (frame_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
        } else {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_weather_map_frames (
    slot_id INTEGER PRIMARY KEY,
    frame_type TEXT NOT NULL,
    frame_time TEXT NOT NULL,
    file_url TEXT NOT NULL,
    model_run_time TEXT NOT NULL,
    refreshed_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_gaugeiq_weather_map_time ON gaugeiq_weather_map_frames(frame_time);
SQL);
        }
        $db->exec("UPDATE gaugeiq_schema SET version = 11");
        $version = 11;
    }

    if ($version === 11) {
        // Location 1 is the legacy/default dataset. Adding location_id with a
        // default of 1 preserves every existing reading and alert rule.
        $integerType = $driver === 'mysql' ? 'INT NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1';
        gaugeIqEnsureColumn($db, 'gaugeiq_pressure_readings', 'location_id', $integerType);
        gaugeIqEnsureColumn($db, 'gaugeiq_alert_rules', 'location_id', $integerType);

        if ($driver === 'mysql') {
            gaugeIqEnsureColumn($db, 'gaugeiq_rain_forecasts', 'location_id', $integerType);
            $indexExists = static function (string $table, string $index) use ($db): bool {
                $stmt = $db->prepare(
                    'SELECT 1 FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
                );
                $stmt->execute([$table, $index]);
                return $stmt->fetchColumn() !== false;
            };
            if ($indexExists('gaugeiq_rain_forecasts', 'uq_gaugeiq_rain_forecast_time')) {
                $db->exec('ALTER TABLE gaugeiq_rain_forecasts DROP INDEX uq_gaugeiq_rain_forecast_time');
            }
            if (!$indexExists('gaugeiq_rain_forecasts', 'uq_gaugeiq_rain_location_time')) {
                $db->exec('ALTER TABLE gaugeiq_rain_forecasts ADD UNIQUE KEY uq_gaugeiq_rain_location_time (location_id, forecast_at)');
            }
            if (!$indexExists('gaugeiq_pressure_readings', 'idx_gaugeiq_pressure_location_observed')) {
                $db->exec('CREATE INDEX idx_gaugeiq_pressure_location_observed ON gaugeiq_pressure_readings (location_id, observed_at)');
            }
            if (!$indexExists('gaugeiq_alert_rules', 'idx_gaugeiq_alert_rules_location')) {
                $db->exec('CREATE INDEX idx_gaugeiq_alert_rules_location ON gaugeiq_alert_rules (location_id, enabled)');
            }
            if (!$indexExists('gaugeiq_rain_forecasts', 'idx_gaugeiq_rain_location_time')) {
                $db->exec('CREATE INDEX idx_gaugeiq_rain_location_time ON gaugeiq_rain_forecasts (location_id, forecast_at)');
            }
        } else {
            // SQLite cannot drop the table-level UNIQUE(forecast_at) constraint.
            // Rebuild this small forecast table so the same forecast hour can
            // safely exist once for each location. The old rows remain Location 1.
            $db->beginTransaction();
            try {
                $db->exec('DROP TABLE IF EXISTS gaugeiq_rain_forecasts_location_migration');
                $db->exec(<<<'SQL'
CREATE TABLE gaugeiq_rain_forecasts_location_migration (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id INTEGER NOT NULL DEFAULT 1,
    forecast_at TEXT NOT NULL,
    precipitation_probability_percent REAL NOT NULL,
    precipitation_mm REAL NULL,
    rain_mm REAL NULL,
    temperature_c REAL NULL,
    source TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (location_id, forecast_at)
);
SQL);
                $db->exec(
                    'INSERT INTO gaugeiq_rain_forecasts_location_migration
                     (id, location_id, forecast_at, precipitation_probability_percent,
                      precipitation_mm, rain_mm, temperature_c, source, created_at)
                     SELECT id, 1, forecast_at, precipitation_probability_percent,
                            precipitation_mm, rain_mm, temperature_c, source, created_at
                     FROM gaugeiq_rain_forecasts'
                );
                $db->exec('DROP TABLE gaugeiq_rain_forecasts');
                $db->exec('ALTER TABLE gaugeiq_rain_forecasts_location_migration RENAME TO gaugeiq_rain_forecasts');
                $db->exec('CREATE INDEX idx_gaugeiq_rain_forecast_source ON gaugeiq_rain_forecasts(source)');
                $db->exec('CREATE INDEX idx_gaugeiq_rain_location_time ON gaugeiq_rain_forecasts(location_id, forecast_at)');
                $db->exec('CREATE INDEX idx_gaugeiq_pressure_location_observed ON gaugeiq_pressure_readings(location_id, observed_at)');
                $db->exec('CREATE INDEX idx_gaugeiq_alert_rules_location ON gaugeiq_alert_rules(location_id, enabled)');
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }

        $db->exec("UPDATE gaugeiq_schema SET version = 12");
        $version = 12;
    }

    // The old display called this a change-score threshold, while the card now
    // shows the inverse Weather Stability score. Convert the saved threshold
    // once so it continues to represent the same alert point after inversion.
    $stabilitySemantics = $db->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $stabilitySemantics->execute(['stability_alert_threshold_semantics']);
    $stabilitySemanticsValue = $stabilitySemantics->fetchColumn();

    if ($stabilitySemanticsValue !== 'weather-stability-v1') {
        $savedThresholdQuery = $db->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
        $savedThresholdQuery->execute(['stability_alert_threshold']);
        $savedThreshold = $savedThresholdQuery->fetchColumn();

        if ($savedThreshold !== false && $savedThreshold !== '' && is_numeric($savedThreshold)) {
            $oldChangeThreshold = max(0, min(10, (int)round((float)$savedThreshold)));
            $newStabilityThreshold = 10 - $oldChangeThreshold;
            if ($driver === 'mysql') {
                $saveThreshold = $db->prepare(
                    "INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
                );
            } else {
                $saveThreshold = $db->prepare(
                    "INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)
                     ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`"
                );
            }
            $saveThreshold->execute(['stability_alert_threshold', (string)$newStabilityThreshold]);
        }

        if ($driver === 'mysql') {
            $saveSemantics = $db->prepare(
                "INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
            );
        } else {
            $saveSemantics = $db->prepare(
                "INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)
                 ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`"
            );
        }
        $saveSemantics->execute(['stability_alert_threshold_semantics', 'weather-stability-v1']);
    }
}
