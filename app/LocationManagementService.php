<?php
declare(strict_types=1);

/**
 * Safe deletion and promotion for GaugeIQ's two independently monitored locations.
 */
final class LocationManagementService
{
    /**
     * Delete one location. If the primary location is deleted, the secondary
     * location is promoted in-place and its complete dataset becomes Location 1.
     */
    public static function deleteLocation(PDO $pdo, int $locationId): bool
    {
        if (!in_array($locationId, [1, 2], true)) {
            throw new InvalidArgumentException('Unsupported GaugeIQ location.');
        }

        $pdo->beginTransaction();
        try {
            if ($locationId === 1) {
                if (self::setting($pdo, 'second_location_enabled') !== '1') {
                    throw new RuntimeException('The primary location cannot be deleted until a second location has been configured.');
                }

                // Remove Location 1's alert events/rules and readings first.
                $pdo->exec(
                    'DELETE FROM gaugeiq_alert_events
                     WHERE rule_id IN (SELECT id FROM gaugeiq_alert_rules WHERE location_id = 1)'
                );
                $pdo->exec('DELETE FROM gaugeiq_alert_rules WHERE location_id = 1');
                $pdo->exec('DELETE FROM gaugeiq_pressure_readings WHERE location_id = 1');
                $pdo->exec('DELETE FROM gaugeiq_rain_forecasts WHERE location_id = 1');

                // The secondary dataset (including imported historical records)
                // now takes over the primary ID used by the rest of the app.
                $pdo->exec('UPDATE gaugeiq_pressure_readings SET location_id = 1 WHERE location_id = 2');
                $pdo->exec('UPDATE gaugeiq_rain_forecasts SET location_id = 1 WHERE location_id = 2');
                $pdo->exec('UPDATE gaugeiq_alert_rules SET location_id = 1 WHERE location_id = 2');

                self::promoteSecondarySettings($pdo);
            } else {
                $pdo->exec(
                    'DELETE FROM gaugeiq_alert_events
                     WHERE rule_id IN (SELECT id FROM gaugeiq_alert_rules WHERE location_id = 2)'
                );
                $pdo->exec('DELETE FROM gaugeiq_alert_rules WHERE location_id = 2');
                $pdo->exec('DELETE FROM gaugeiq_pressure_readings WHERE location_id = 2');
                $pdo->exec('DELETE FROM gaugeiq_rain_forecasts WHERE location_id = 2');
                self::clearSecondarySettings($pdo);
            }

            $pdo->commit();
            return $locationId === 1;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function setting(PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare('SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    private static function saveSetting(PDO $pdo, string $key, string $value): void
    {
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare(
                'INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)
                 ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
        }
        $stmt->execute([$key, $value]);
    }

    private static function promoteSecondarySettings(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT `key`, `value` FROM gaugeiq_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $promoted = [];
        foreach ($rows as $key => $value) {
            $key = (string)$key;
            if ($key === 'second_location_name') {
                $promoted['location_name'] = (string)$value;
            } elseif ($key === 'second_location_latitude') {
                $promoted['location_latitude'] = (string)$value;
            } elseif ($key === 'second_location_longitude') {
                $promoted['location_longitude'] = (string)$value;
            } elseif ($key === 'second_location_changed_at') {
                $promoted['active_location_changed_at'] = (string)$value;
            } elseif (str_starts_with($key, 'second_location_') && $key !== 'second_location_enabled') {
                $promoted['location_' . substr($key, strlen('second_location_'))] = (string)$value;
            } elseif (str_ends_with($key, '_location_2')) {
                $promoted[substr($key, 0, -strlen('_location_2'))] = (string)$value;
            }
        }

        // Clear primary location-scoped state so no timestamp, error or cached
        // nowcast from the deleted location is mistaken for the promoted one.
        $primaryKeys = [
            'location_name', 'location_latitude', 'location_longitude', 'active_location_changed_at',
            'location_timezone', 'recent_backfill_last_success_at', 'monitor_last_success_at',
            'monitor_last_error', 'daily_forecast_alert_last_evaluated_date',
            'daily_forecast_alert_last_status', 'daily_forecast_alert_last_error',
            'recent_backfill_last_error', 'weather_map_rainfall_nowcast_error',
            'ecmwf_rainfall_alert_last_status', 'ecmwf_rainfall_alert_last_error',
            'weather_map_rainfall_nowcast', 'weather_map_rainfall_nowcast_updated_at',
            'ecmwf_rainfall_alert_last_sent_at',
        ];
        $delete = $pdo->prepare('DELETE FROM gaugeiq_settings WHERE `key` = ?');
        foreach ($primaryKeys as $key) {
            $delete->execute([$key]);
        }
        // Also clear any additional location-scoped key discovered from the
        // secondary settings, not just keys known when this service was written.
        foreach (array_keys($promoted) as $key) {
            $delete->execute([$key]);
        }
        foreach ($promoted as $key => $value) {
            self::saveSetting($pdo, $key, $value);
        }

        self::clearSecondarySettings($pdo);
    }

    private static function clearSecondarySettings(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT `key` FROM gaugeiq_settings')->fetchAll(PDO::FETCH_COLUMN);
        $delete = $pdo->prepare('DELETE FROM gaugeiq_settings WHERE `key` = ?');
        foreach ($rows as $key) {
            $key = (string)$key;
            if (str_starts_with($key, 'second_location_') || str_ends_with($key, '_location_2')) {
                $delete->execute([$key]);
            }
        }
        self::saveSetting($pdo, 'second_location_enabled', '0');
    }
}
