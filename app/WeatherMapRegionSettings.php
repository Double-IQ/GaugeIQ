<?php
declare(strict_types=1);

/** Server-side coverage settings shared by the map APIs and scheduled decoder. */
final class WeatherMapRegionSettings
{
    public const DEFAULT_BOUNDS = [-25.0, -45.0, 60.0, 5.0];

    public static function validate(array $bounds): array
    {
        if (array_is_list($bounds)) {
            if (count($bounds) !== 4) throw new InvalidArgumentException('Weather-map bounds must contain west, south, east and north.');
            [$west, $south, $east, $north] = $bounds;
        } else {
            [$west, $south, $east, $north] = [
                $bounds['west'] ?? null, $bounds['south'] ?? null,
                $bounds['east'] ?? null, $bounds['north'] ?? null
            ];
        }
        foreach ([$west, $south, $east, $north] as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) {
                throw new InvalidArgumentException('Weather-map bounds must be finite numbers.');
            }
        }
        $west=(float)$west; $south=(float)$south; $east=(float)$east; $north=(float)$north;
        if ($west < -180 || $east > 180 || $south < -85 || $north > 85 ||
            $west >= $east || $south >= $north) {
            throw new InvalidArgumentException('Weather-map bounds are outside the supported range.');
        }
        return [$west, $south, $east, $north];
    }

    public static function get(PDO $pdo, int $locationId): array
    {
        if (!in_array($locationId, [1, 2], true)) $locationId = 1;
        $stmt=$pdo->prepare('SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute(['weather_map_region_bounds_location_'.$locationId]);
        $value=$stmt->fetchColumn();
        if (is_string($value)) {
            $decoded=json_decode($value, true);
            if (is_array($decoded)) {
                try { return self::validate($decoded); } catch (Throwable) {}
            }
        }
        return self::DEFAULT_BOUNDS;
    }

    public static function save(PDO $pdo, int $locationId, array $bounds): void
    {
        if (!in_array($locationId, [1, 2], true)) throw new InvalidArgumentException('Invalid GaugeIQ location.');
        $bounds=self::validate($bounds);
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql=$driver==='mysql'
            ? 'INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            : 'INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`';
        $stmt=$pdo->prepare($sql);
        $stmt->execute(['weather_map_region_bounds_location_'.$locationId, json_encode($bounds, JSON_THROW_ON_ERROR)]);
    }

    public static function cacheKey(string $url, string $variable, array $bounds): string
    {
        $bounds=self::validate($bounds);
        $signature=implode(',', array_map(static fn(float $v): string => sprintf('%.6F',$v), $bounds));
        return hash('sha256', $url.'|'.$variable.'|region-grid-v4|'.$signature);
    }
}
