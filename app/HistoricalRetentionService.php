<?php
declare(strict_types=1);

/**
 * Keeps GaugeIQ's timestamped historical weather datasets within a rolling
 * retention window. The cutoff is calculated in each location's own timezone
 * because Open-Meteo timestamps are stored as local wall-clock values.
 */
final class HistoricalRetentionService
{
    public function __construct(
        private PDO $db,
        private string $fallbackTimezone = 'UTC',
        private int $retentionDays = 35
    ) {
        if ($this->retentionDays < 1) {
            throw new InvalidArgumentException('Historical retention must be at least one day.');
        }
    }

    public function pruneLocation(int $locationId, ?string $timezoneName = null): array
    {
        if (!in_array($locationId, [1, 2], true)) {
            throw new InvalidArgumentException('Unsupported GaugeIQ location.');
        }

        try {
            $timezone = new DateTimeZone($timezoneName ?: $this->fallbackTimezone);
        } catch (Throwable) {
            $timezone = new DateTimeZone('UTC');
        }

        $cutoff = (new DateTimeImmutable('now', $timezone))
            ->modify('-' . $this->retentionDays . ' days')
            ->format('Y-m-d\TH:i:s');

        $this->db->beginTransaction();
        try {
            $readings = $this->db->prepare(
                'DELETE FROM gaugeiq_pressure_readings WHERE location_id = ? AND observed_at < ?'
            );
            $readings->execute([$locationId, $cutoff]);
            $readingsDeleted = $readings->rowCount();

            $forecasts = $this->db->prepare(
                'DELETE FROM gaugeiq_rain_forecasts WHERE location_id = ? AND forecast_at < ?'
            );
            $forecasts->execute([$locationId, $cutoff]);
            $forecastsDeleted = $forecasts->rowCount();

            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return [
            'cutoff' => $cutoff,
            'readings_deleted' => $readingsDeleted,
            'forecasts_deleted' => $forecastsDeleted,
        ];
    }
}
