<?php
declare(strict_types=1);

/**
 * Maintains the twelve concrete ECMWF map frames used by GaugeIQ:
 * six past and six forecast hours. Binary map data is deliberately kept out
 * of SQL; the public proxy stores requested HTTP range blocks on disk.
 */
final class WeatherMapCacheService
{
    private const METADATA_URL = 'https://openmeteo.s3.amazonaws.com/data_spatial/ecmwf_ifs/latest.json';
    private const FRAME_COUNT = 6;
    private const HOUR_MS = 3600000;
    private const SIX_HOURS_MS = 21600000;

    public function __construct(private readonly PDO $pdo, private readonly array $config = [])
    {
    }

    public function refreshScheduleIfDue(int $intervalSeconds = 3600): bool
    {
        $stmt = $this->pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute(['weather_map_schedule_last_success_at']);
        $last = $stmt->fetchColumn();
        $lastTimestamp = is_string($last) ? strtotime($last) : false;
        if ($lastTimestamp !== false && $lastTimestamp > time() - $intervalSeconds) {
            return false;
        }

        $metadata = $this->fetchMetadata();
        $validTimes = $metadata['valid_times'] ?? null;
        if (!is_array($validTimes) || count($validTimes) < self::FRAME_COUNT) {
            throw new RuntimeException('Latest ECMWF metadata does not contain enough valid times.');
        }

        $timestamps = [];
        foreach ($validTimes as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Latest ECMWF metadata contains an invalid valid time.');
            }
            $parsed = strtotime($value);
            if ($parsed === false) {
                throw new RuntimeException('Latest ECMWF metadata contains an unreadable valid time.');
            }
            $timestamps[] = $parsed * 1000;
        }

        $now = (int)floor(microtime(true) * 1000);
        $pastIndex = -1;
        $futureIndex = -1;
        foreach ($timestamps as $index => $timestamp) {
            if ($timestamp <= $now) {
                $pastIndex = $index;
            }
            if ($futureIndex === -1 && $timestamp >= $now) {
                $futureIndex = $index;
            }
        }
        if ($pastIndex < 0 || $futureIndex < 0 || $futureIndex + self::FRAME_COUNT > count($timestamps)) {
            throw new RuntimeException('The latest ECMWF run cannot provide six past and six future hourly frames.');
        }

        $runDate = new DateTimeImmutable((string)($metadata['reference_time'] ?? ''), new DateTimeZone('UTC'));
        $futureRunFolder = $runDate->format('Y/m/d/H') . '00Z';

        $frames = [];
        for ($offset = 0; $offset < self::FRAME_COUNT; $offset++) {
            $timestamp = $timestamps[$futureIndex + $offset];
            $validDate = (new DateTimeImmutable('@' . (int)floor($timestamp / 1000)))->setTimezone(new DateTimeZone('UTC'));
            $fileName = $validDate->format('Y-m-d\\TH') . '00.om';
            $frames[] = [
                'slot_id' => self::FRAME_COUNT + $offset,
                'frame_type' => 'future',
                'frame_time' => $validDate->format('Y-m-d\\TH:i:s\\Z'),
                'file_url' => 'https://openmeteo.s3.amazonaws.com/data_spatial/ecmwf_ifs/' . $futureRunFolder . '/' . $fileName,
                'model_run_time' => $runDate->format('Y-m-d\\TH:i:s\\Z'),
            ];
        }

        // Match the past-frame run selection used by public/js/weather-map.js.
        $currentPastTime = $timestamps[$pastIndex];
        $firstPastTime = $currentPastTime - (5 * self::HOUR_MS);
        $previousRunTime = (int)(floor(($firstPastTime - self::HOUR_MS) / self::SIX_HOURS_MS) * self::SIX_HOURS_MS - self::SIX_HOURS_MS);
        $pastRunDate = (new DateTimeImmutable('@' . (int)floor($previousRunTime / 1000)))->setTimezone(new DateTimeZone('UTC'));
        $pastRunFolder = $pastRunDate->format('Y/m/d/H') . '00Z';

        for ($offset = 0; $offset < self::FRAME_COUNT; $offset++) {
            $timestamp = $firstPastTime + ($offset * self::HOUR_MS);
            $validDate = (new DateTimeImmutable('@' . (int)floor($timestamp / 1000)))->setTimezone(new DateTimeZone('UTC'));
            $fileName = $validDate->format('Y-m-d\\THi') . '.om';
            $frames[] = [
                'slot_id' => $offset,
                'frame_type' => 'past',
                'frame_time' => $validDate->format('Y-m-d\\TH:i:s\\Z'),
                'file_url' => 'https://openmeteo.s3.amazonaws.com/data_spatial/ecmwf_ifs/' . $pastRunFolder . '/' . $fileName,
                'model_run_time' => $pastRunDate->format('Y-m-d\\TH:i:s\\Z'),
            ];
        }

        $previousUrlQuery = $this->pdo->query('SELECT file_url FROM gaugeiq_weather_map_frames');
        $previousUrls = [];
        foreach ($previousUrlQuery->fetchAll(PDO::FETCH_COLUMN) as $previousUrl) {
            $previousUrls[(string)$previousUrl] = true;
        }
        $pendingQuery = $this->pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
        $pendingQuery->execute(['weather_map_prefetch_pending_urls']);
        $pendingValue = $pendingQuery->fetchColumn();
        $pendingUrls = is_string($pendingValue) ? json_decode($pendingValue, true) : [];
        if (!is_array($pendingUrls)) $pendingUrls = [];
        $scheduledUrls = array_column($frames, 'file_url');
        $scheduledUrlSet = array_fill_keys($scheduledUrls, true);
        $newUrls = array_values(array_filter(
            $scheduledUrls,
            static fn(string $url): bool => !isset($previousUrls[$url])
        ));
        $pendingUrls = array_values(array_unique(array_filter(
            array_merge($pendingUrls, $newUrls),
            static fn($url): bool => is_string($url) && isset($scheduledUrlSet[$url])
        )));

        $refreshedAt = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM gaugeiq_weather_map_frames');
            $insert = $this->pdo->prepare(
                'INSERT INTO gaugeiq_weather_map_frames (slot_id, frame_type, frame_time, file_url, model_run_time, refreshed_at) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($frames as $frame) {
                $insert->execute([
                    $frame['slot_id'],
                    $frame['frame_type'],
                    $frame['frame_time'],
                    $frame['file_url'],
                    $frame['model_run_time'],
                    $refreshedAt,
                ]);
            }
            $this->saveSetting('weather_map_schedule_last_success_at', $refreshedAt);
            $this->saveSetting('weather_map_schedule_last_error', '');
            // Persist only newly introduced URLs; failed prefetches remain queued for retry.
            $this->saveSetting('weather_map_prefetch_pending_urls', json_encode($pendingUrls, JSON_UNESCAPED_SLASHES));
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return true;
    }

    /**
     * Prefetch regional .om chunks through the public range proxy so the
     * browser and scheduled job share the same on-disk byte-range cache.
     *
     * @return array{skipped:bool,frames:int,blocks?:int,output?:string}
     */
    public function prefetchRegionalFramesIfDue(int $intervalSeconds = 3600): array
    {
        $pendingQuery = $this->pdo->prepare(
            "SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1"
        );
        $pendingQuery->execute(['weather_map_prefetch_pending_urls']);
        $pendingValue = $pendingQuery->fetchColumn();
        $pendingUrls = is_string($pendingValue) ? json_decode($pendingValue, true) : [];
        if (!is_array($pendingUrls) || $pendingUrls === []) {
            return ['skipped' => true, 'frames' => 0, 'blocks' => 0, 'output' => 'No new ECMWF frames require prefetch.'];
        }

        try {
            $framesQuery = $this->pdo->query(
                'SELECT slot_id, frame_type, frame_time, file_url
                 FROM gaugeiq_weather_map_frames ORDER BY slot_id ASC'
            );
            $frames = [];
            $pendingSet = array_fill_keys(array_filter($pendingUrls, 'is_string'), true);
            foreach ($framesQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $url = (string)$row['file_url'];
                if (!isset($pendingSet[$url])) continue;
                $frames[] = [
                    'slot' => (int)$row['slot_id'],
                    'type' => (string)$row['frame_type'],
                    'time' => (string)$row['frame_time'],
                    'url' => $url,
                ];
            }
            if ($frames === []) {
                $this->saveSetting('weather_map_prefetch_pending_urls', '[]');
                return ['skipped' => true, 'frames' => 0, 'blocks' => 0, 'output' => 'No new scheduled ECMWF frames require prefetch.'];
            }

            $baseUrl = rtrim((string)($this->config['app']['base_url'] ?? ''), '/');
            if (!str_starts_with($baseUrl, 'https://')) {
                throw new RuntimeException('A public HTTPS app.base_url is required for regional weather-map prefetch.');
            }

            require_once __DIR__ . '/WeatherMapRegionalPrefetcher.php';
            $concurrency = max(1, min(16, (int)($this->config['weather_map_cache']['prefetch_concurrency'] ?? 6)));
            $prefetcher = new WeatherMapRegionalPrefetcher($baseUrl, $concurrency);
            $result = $prefetcher->prefetch($frames);

            $completedUrls = array_fill_keys(array_column($frames, 'url'), true);
            $remainingUrls = array_values(array_filter(
                $pendingUrls,
                static fn($url): bool => is_string($url) && !isset($completedUrls[$url])
            ));
            $this->saveSetting('weather_map_prefetch_pending_urls', json_encode($remainingUrls, JSON_UNESCAPED_SLASHES));
            $this->saveSetting('weather_map_prefetch_last_success_at', gmdate('c'));
            $this->saveSetting('weather_map_prefetch_last_error', '');
            $this->saveSetting('weather_map_prefetch_last_output', $result['output']);
            return [
                'skipped' => false,
                'frames' => $result['frames'],
                'blocks' => $result['blocks'],
                'output' => $result['output'],
            ];
        } catch (Throwable $error) {
            $this->saveSetting('weather_map_prefetch_last_error', $error->getMessage());
            throw $error;
        }
    }

    public function isScheduledFile(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'openmeteo.s3.amazonaws.com' ||
            !preg_match('#^/data_spatial/ecmwf_ifs/.+\\.om$#', (string)($parts['path'] ?? ''))) {
            return false;
        }

        $canonical = 'https://openmeteo.s3.amazonaws.com' . $parts['path'];
        $stmt = $this->pdo->prepare('SELECT 1 FROM gaugeiq_weather_map_frames WHERE file_url = ? LIMIT 1');
        $stmt->execute([$canonical]);
        return $stmt->fetchColumn() !== false;
    }

    private function fetchMetadata(): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for the weather-map schedule.');
        }

        $curl = curl_init(self::METADATA_URL);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise the ECMWF metadata request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'GaugeIQ-WeatherMap/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException('ECMWF metadata request failed' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ')') . '.');
        }
        try {
            $metadata = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('ECMWF metadata response was not valid JSON.', 0, $error);
        }
        if (!is_array($metadata) || empty($metadata['reference_time'])) {
            throw new RuntimeException('ECMWF metadata is missing its model run time.');
        }
        return $metadata;
    }

    private function saveSetting(string $key, string $value): void
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`'
            );
        }
        $stmt->execute([$key, $value]);
    }
}
