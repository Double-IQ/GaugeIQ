<?php
declare(strict_types=1);

/**
 * Operations for removing an independently monitored secondary location.
 */
final class LocationManagementService
{
    /**
     * Remove Location 2 and all data owned by it without touching Location 1
     * or device-wide push subscriptions.
     */
    public static function deleteSecondLocation(PDO $pdo): void
    {
        $pdo->beginTransaction();

        try {
            // Alert events do not have a location_id of their own, so remove
            // them through the Location 2 rules before deleting those rules.
            $pdo->exec(
                'DELETE FROM gaugeiq_alert_events
                 WHERE rule_id IN (
                     SELECT id FROM gaugeiq_alert_rules WHERE location_id = 2
                 )'
            );
            $pdo->exec('DELETE FROM gaugeiq_alert_rules WHERE location_id = 2');
            $pdo->exec('DELETE FROM gaugeiq_pressure_readings WHERE location_id = 2');
            $pdo->exec('DELETE FROM gaugeiq_rain_forecasts WHERE location_id = 2');

            // Clear every setting explicitly scoped to Location 2, including
            // monitoring, timezone, alert and rainfall-nowcast state.
            $settings = $pdo->query('SELECT `key` FROM gaugeiq_settings')->fetchAll(PDO::FETCH_COLUMN);
            $deleteSetting = $pdo->prepare('DELETE FROM gaugeiq_settings WHERE `key` = ?');
            foreach ($settings as $key) {
                $key = (string)$key;
                if (str_starts_with($key, 'second_location_') || str_ends_with($key, '_location_2')) {
                    $deleteSetting->execute([$key]);
                }
            }

            // Keep the feature flag explicitly disabled after clearing its
            // other settings so cron and dashboard requests stop monitoring it.
            if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->prepare(
                    "INSERT INTO gaugeiq_settings (`key`, `value`) VALUES ('second_location_enabled', '0')
                     ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`"
                );
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO gaugeiq_settings (`key`, `value`) VALUES ('second_location_enabled', '0')
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
                );
            }
            $stmt->execute();

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
