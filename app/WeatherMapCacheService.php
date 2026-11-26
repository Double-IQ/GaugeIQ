<?php
declare(strict_types=1);

/**
 * Maintains four past ECMWF frames for the visible map and two separate future
 * frames for the Rain Radar forecast decoder. Binary map data is deliberately
 * kept out of SQL; the public proxy stores requested HTTP range blocks on disk.
 */
final class WeatherMapCacheService
{
    private const MODEL_PATH = 'ecmwf_ifs025';
    private const METADATA_URL = 'https://openmeteo.s3.amazonaws.com/data_spatial/' . self::MODEL_PATH . '/latest.json';
    private const PAST_FRAME_COUNT = 4;
    private const FUTURE_FRAME_COUNT = 2;
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
        $existingCounts = ['past' => 0, 'future' => 0];
        $existingModelFrameCount = 0;
        try {
            $countQuery = $this->pdo->query('SELECT frame_type, COUNT(*) AS total FROM gaugeiq_weather_map_frames GROUP BY frame_type');
            foreach ($countQuery->fetchAll(PDO::FETCH_ASSOC) as $countRow) {
                $type = (string)($countRow['frame_type'] ?? '');
                if (isset($existingCounts[$type])) $existingCounts[$type] = (int)$countRow['total'];
            }
            $modelCountQuery = $this->pdo->prepare('SELECT COUNT(*) FROM gaugeiq_weather_map_frames WHERE file_url LIKE ?');
            $modelCountQuery->execute(['https://openmeteo.s3.amazonaws.com/data_spatial/' . self::MODEL_PATH . '/%']);
            $existingModelFrameCount = (int)$modelCountQuery->fetchColumn();
        } catch (Throwable) {
            $existingCounts = ['past' => 0, 'future' => 0];
            $existingModelFrameCount = 0;
        }
        if ($lastTimestamp !== false && $lastTimestamp > time() - $intervalSeconds &&
            $existingCounts['past'] === self::PAST_FRAME_COUNT &&
            $existingCounts['future'] === self::FUTURE_FRAME_COUNT &&
            $existingModelFrameCount === self::PAST_FRAME_COUNT + self::FUTURE_FRAME_COUNT) {
            return false;
        }

        // latest.json is published only after a run is complete. During the
        // next model cycle it can therefore be stale and contain no timestamps
        // ahead of the current time. in-progress.json is updated as forecast
        // steps arrive, so consider both and select the newest usable run.
        $now = (int)floor(microtime(true) * 1000);
        $candidates = [];
        $metadataErrors = [];
        foreach (['latest.json', 'in-progress.json'] as $metadataFile) {
            try {
                $metadata = $this->fetchMetadata($metadataFile);
                $validTimes = $metadata['valid_times'] ?? null;
                $referenceTime = $metadata['reference_time'] ?? null;
                if (!is_array($validTimes) || !is_string($referenceTime)) {
                    $metadataErrors[] = $metadataFile . ' is missing reference_time or valid_times';
                    continue;
                }

                $timestamps = [];
                foreach ($validTimes as $value) {
                    if (!is_string($value) || ($parsed = strtotime($value)) === false) {
                        continue;
                    }
                    $timestamps[] = $parsed * 1000;
                }
                sort($timestamps, SORT_NUMERIC);
                $timestamps = array_values(array_unique($timestamps));

                $pastIndex = -1;
                foreach ($timestamps as $index => $timestamp) {
                    if ($timestamp <= $now) $pastIndex = $index;
                }
                if ($pastIndex < 0) {
                    $metadataErrors[] = $metadataFile . ' has no elapsed timestamps for past frames';
                    continue;
                }

                $futureTimestampCount = count(array_filter(
                    $timestamps,
                    static fn(int $timestamp): bool => $timestamp > $now
                ));
                if ($futureTimestampCount < self::FUTURE_FRAME_COUNT) {
                    $metadataErrors[] = $metadataFile . ' does not yet contain two future forecast timestamps';
                    continue;
                }

                $referenceTimestamp = strtotime($referenceTime);
                if ($referenceTimestamp === false) {
                    $metadataErrors[] = $metadataFile . ' has an unreadable reference_time';
                    continue;
                }
                $candidates[] = [
                    'metadata' => array_merge($metadata, ['_source' => $metadataFile]),
                    'timestamps' => $timestamps,
                    'past_index' => $pastIndex,
                    'reference_timestamp' => $referenceTimestamp,
                ];
            } catch (Throwable $error) {
                $metadataErrors[] = $metadataFile . ': ' . $error->getMessage();
            }
        }

        if ($candidates === []) {
            throw new RuntimeException(
                'Neither latest nor in-progress ECMWF IFS 0.25° metadata contains elapsed timestamps for past frames. ' .
                implode('; ', $metadataErrors)
            );
        }

        // Use the newest available model run. The visible weather-map API
        // exposes only past frames; two future frames remain scheduled separately
        // for the Rain Radar decoder and are never returned to the animation.
        usort($candidates, static fn(array $a, array $b): int =>
            $b['reference_timestamp'] <=> $a['reference_timestamp']
        );
        $selected = $candidates[0];
        $metadata = $selected['metadata'];
        $timestamps = $selected['timestamps'];
        $pastIndex = $selected['past_index'];

        // Keep actual model timestamps. If the current run has fewer than
        // three elapsed steps, fill the remaining historical display slots
        // by the native 3-hour cadence. For each timestamp, walk backwards
        // through six-hourly source runs until S3 confirms the object exists.
        $pastTimes = $pastIndex >= 0
            ? array_slice($timestamps, max(0, $pastIndex - self::PAST_FRAME_COUNT + 1), $pastIndex + 1)
            : [];
        while (count($pastTimes) < self::PAST_FRAME_COUNT) {
            $anchor = $pastTimes[0] ?? ($timestamps[0] ?? $now);
            array_unshift($pastTimes, $anchor - (3 * self::HOUR_MS));
        }

        $pastFrames = [];
        foreach ($pastTimes as $timestamp) {
            $validDate = (new DateTimeImmutable('@' . (int)floor($timestamp / 1000)))
                ->setTimezone(new DateTimeZone('UTC'));
            $validTimestamp = (int)floor($timestamp / 1000);
            $runIntervalSeconds = (int)(self::SIX_HOURS_MS / 1000);
            $sourceRunTimestamp = (int)(floor(($validTimestamp - 10800) / $runIntervalSeconds) * $runIntervalSeconds);
            $availablePastFrame = null;

            // Try the latest eligible run first, then older archived runs.
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $sourceRunDate = (new DateTimeImmutable('@' . $sourceRunTimestamp))
                    ->setTimezone(new DateTimeZone('UTC'));
                $sourceRunFolder = $sourceRunDate->format('Y/m/d/H') . '00Z';
                $fileName = $validDate->format('Y-m-d\\THi') . '.om';
                $fileUrl = 'https://openmeteo.s3.amazonaws.com/data_spatial/' . self::MODEL_PATH .
                    '/' . $sourceRunFolder . '/' . $fileName;

                if ($this->upstreamObjectHasRange($fileUrl)) {
                    $availablePastFrame = [
                        'frame_time' => $validDate->format('Y-m-d\\TH:i:s\\Z'),
                        'file_url' => $fileUrl,
                        'model_run_time' => $sourceRunDate->format('Y-m-d\\TH:i:s\\Z'),
                    ];
                    break;
                }
                $sourceRunTimestamp -= $runIntervalSeconds;
            }

            if ($availablePastFrame === null) {
                throw new RuntimeException(
                    'No published ECMWF IFS 0.25° source object was found for past frame ' .
                    $validDate->format('Y-m-d\\TH:i:s\\Z') . '.'
                );
            }
            $pastFrames[] = $availablePastFrame;
        }

        $frames = [];
        foreach ($pastFrames as $offset => $frame) {
            $frames[] = [
                'slot_id' => $offset,
                'frame_type' => 'past',
                'frame_time' => $frame['frame_time'],
                'file_url' => $frame['file_url'],
                'model_run_time' => $frame['model_run_time'],
            ];
        }

        // Keep two published future source objects in the shared schedule.
        // The public weather-map endpoint filters these out; RainfallNowcastService
        // consumes them to build its independent forecast payload.
        $futureTimes = array_slice(
            array_values(array_filter(
                $timestamps,
                static fn(int $timestamp): bool => $timestamp > $now
            )),
            0,
            self::FUTURE_FRAME_COUNT
        );
        if (count($futureTimes) !== self::FUTURE_FRAME_COUNT) {
            throw new RuntimeException(
                'The selected ECMWF IFS model run does not yet publish two future forecast timestamps.'
            );
        }

        $futureFrames = [];
        $runIntervalSeconds = (int)(self::SIX_HOURS_MS / 1000);
        $initialFutureRunTimestamp = (int)(
            floor($selected['reference_timestamp'] / $runIntervalSeconds) * $runIntervalSeconds
        );
        foreach ($futureTimes as $timestamp) {
            $validDate = (new DateTimeImmutable('@' . (int)floor($timestamp / 1000)))
                ->setTimezone(new DateTimeZone('UTC'));
            $availableFutureFrame = null;
            $sourceRunTimestamp = $initialFutureRunTimestamp;

            // Prefer the exact run whose metadata supplied these future steps.
            // Fall back to older six-hour runs only if its object is not published.
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $sourceRunDate = (new DateTimeImmutable('@' . $sourceRunTimestamp))
                    ->setTimezone(new DateTimeZone('UTC'));
                $sourceRunFolder = $sourceRunDate->format('Y/m/d/H') . '00Z';
                $fileName = $validDate->format('Y-m-d\\THi') . '.om';
                $fileUrl = 'https://openmeteo.s3.amazonaws.com/data_spatial/' . self::MODEL_PATH .
                    '/' . $sourceRunFolder . '/' . $fileName;

                if ($this->upstreamObjectHasRange($fileUrl)) {
                    $availableFutureFrame = [
                        'frame_time' => $validDate->format('Y-m-d\\TH:i:s\\Z'),
                        'file_url' => $fileUrl,
                        'model_run_time' => $sourceRunDate->format('Y-m-d\\TH:i:s\\Z'),
                    ];
                    break;
                }
                $sourceRunTimestamp -= $runIntervalSeconds;
            }

            if ($availableFutureFrame === null) {
                throw new RuntimeException(
                    'No published ECMWF IFS 0.25° source object was found for future frame ' .
                    $validDate->format('Y-m-d\\TH:i:s\\Z') . '.'
                );
            }
            $futureFrames[] = $availableFutureFrame;
        }

        foreach ($futureFrames as $offset => $frame) {
            $frames[] = [
                'slot_id' => self::PAST_FRAME_COUNT + $offset,
                'frame_type' => 'future',
                'frame_time' => $frame['frame_time'],
                'file_url' => $frame['file_url'],
                'model_run_time' => $frame['model_run_time'],
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
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $insertSql = 'INSERT INTO gaugeiq_weather_map_frames (slot_id, frame_type, frame_time, file_url, model_run_time, refreshed_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE frame_type = VALUES(frame_type), frame_time = VALUES(frame_time), file_url = VALUES(file_url), model_run_time = VALUES(model_run_time), refreshed_at = VALUES(refreshed_at)';
            } else {
                $insertSql = 'INSERT INTO gaugeiq_weather_map_frames (slot_id, frame_type, frame_time, file_url, model_run_time, refreshed_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(slot_id) DO UPDATE SET frame_type = excluded.frame_type, frame_time = excluded.frame_time, file_url = excluded.file_url, model_run_time = excluded.model_run_time, refreshed_at = excluded.refreshed_at';
            }
            $insert = $this->pdo->prepare($insertSql);
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
    public function prefetchRegionalFramesIfDue(int $intervalSeconds = 3600, ?int $onlyLocationId = null): array
    {
        if ($onlyLocationId !== null && !in_array($onlyLocationId, [1, 2], true)) {
            throw new InvalidArgumentException('Invalid GaugeIQ location for regional weather-map prefetch.');
        }
        $locationIds = $onlyLocationId === null ? [1, 2] : [$onlyLocationId];
        require_once __DIR__ . '/WeatherMapRegionSettings.php';
        $pendingQuery=$this->pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
        $pendingQuery->execute(['weather_map_prefetch_pending_urls']);
        $pendingValue=$pendingQuery->fetchColumn();
        $pendingUrls=is_string($pendingValue)?json_decode($pendingValue,true):[];
        if(!is_array($pendingUrls)) $pendingUrls=[];

        try {
            $framesQuery=$this->pdo->query('SELECT slot_id, frame_type, frame_time, file_url FROM gaugeiq_weather_map_frames ORDER BY slot_id ASC');
            $allFrames=$framesQuery->fetchAll(PDO::FETCH_ASSOC);
            $cacheRoot=(string)($this->config['weather_map_cache']['directory'] ?? (dirname(__DIR__).'/storage/weather-map-cache'));
            if($cacheRoot==='' || $cacheRoot[0]!=='/') throw new RuntimeException('The weather-map cache directory must be an absolute server path.');
            $decodedDirectory=rtrim($cacheRoot,'/').'/decoded';
            $baseUrl=rtrim((string)($this->config['app']['base_url'] ?? ''),'/');
            if(!str_starts_with($baseUrl,'https://')) throw new RuntimeException('A public HTTPS app.base_url is required for regional weather-map prefetch.');
            require_once __DIR__.'/WeatherMapRegionalPrefetcher.php';
            require_once __DIR__.'/WeatherMapRegionSettings.php';
            $concurrency=max(1,min(16,(int)($this->config['weather_map_cache']['prefetch_concurrency'] ?? 6)));
            $pendingSet=array_fill_keys(array_filter($pendingUrls,'is_string'),true);
            $totalFrames=0; $totalBlocks=0; $outputs=[]; $completedUrls=[];
            foreach($locationIds as $locationId) {
                $bounds=WeatherMapRegionSettings::get($this->pdo,$locationId);
                $frames=[];
                foreach($allFrames as $row) {
                    $url=(string)$row['file_url']; $type=(string)$row['frame_type'];
                    $needsDecode=false;
                    if($type==='past') foreach(['cloud_cover','precipitation'] as $variable) {
                        $key=WeatherMapRegionSettings::cacheKey($url,$variable,$bounds);
                        if(!is_file($decodedDirectory.'/'.$key.'.f32') || !is_file($decodedDirectory.'/'.$key.'.json')) { $needsDecode=true; break; }
                    }
                    if(!isset($pendingSet[$url]) && !$needsDecode) continue;
                    $frames[]=['slot'=>(int)$row['slot_id'],'type'=>$type,'time'=>(string)$row['frame_time'],'url'=>$url];
                }
                if($frames===[]) continue;
                $prefetcher=new WeatherMapRegionalPrefetcher($baseUrl,$concurrency,$decodedDirectory,$bounds);
                $result=$prefetcher->prefetch($frames);
                $totalFrames=max($totalFrames,$result['frames']);
                $totalBlocks+=$result['blocks'];
                $outputs[]='Location '.$locationId.': '.$result['output'];
                foreach($frames as $frame) $completedUrls[$frame['url']]=true;
            }
            $remaining=array_values(array_filter($pendingUrls,static fn($url):bool=>is_string($url)&&!isset($completedUrls[$url])));
            $this->saveSetting('weather_map_prefetch_pending_urls',json_encode($remaining,JSON_UNESCAPED_SLASHES));
            $this->saveSetting('weather_map_prefetch_last_success_at',gmdate('c'));
            $this->saveSetting('weather_map_prefetch_last_error','');
            $output=$outputs===[]?'Both location-specific decoded caches are already current.':implode(' ',$outputs);
            $this->saveSetting('weather_map_prefetch_last_output',$output);
            return ['skipped'=>$outputs===[],'frames'=>$totalFrames,'blocks'=>$totalBlocks,'output'=>$output];
        } catch(Throwable $error) {
            $this->saveSetting('weather_map_prefetch_last_error',$error->getMessage());
            throw $error;
        }
    }

    public function isScheduledFile(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'openmeteo.s3.amazonaws.com' ||
            !preg_match('#^/data_spatial/ecmwf_ifs025/.+\\.om$#', (string)($parts['path'] ?? ''))) {
            return false;
        }

        $canonical = 'https://openmeteo.s3.amazonaws.com' . $parts['path'];
        $stmt = $this->pdo->prepare('SELECT 1 FROM gaugeiq_weather_map_frames WHERE file_url = ? LIMIT 1');
        $stmt->execute([$canonical]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Confirm that the upstream object is published and supports the exact
     * one-byte range request used by the browser proxy's metadata probe.
     */
    private function upstreamObjectHasRange(string $url): bool
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required to validate ECMWF map-frame objects.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return false;
        }
        $contentRange = '';
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'GaugeIQ-WeatherMap/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
            CURLOPT_RANGE => '0-0',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$contentRange): int {
                if (stripos($header, 'Content-Range:') === 0) {
                    $contentRange = trim(substr($header, strlen('Content-Range:')));
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return $status === 206 && is_string($body) && strlen($body) === 1 &&
            preg_match('/^bytes\\s+0-0\\/\\d+$/i', $contentRange) === 1;
    }

    private function fetchMetadata(string $metadataFile = 'latest.json'): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for the weather-map schedule.');
        }

        $metadataUrl = 'https://openmeteo.s3.amazonaws.com/data_spatial/' . self::MODEL_PATH . '/' . $metadataFile;
        $curl = curl_init($metadataUrl);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise the ECMWF IFS 0.25° metadata request.');
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
            throw new RuntimeException('ECMWF IFS 0.25° metadata request failed' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ')') . '.');
        }
        try {
            $metadata = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('ECMWF IFS 0.25° metadata response was not valid JSON.', 0, $error);
        }
        if (!is_array($metadata) || empty($metadata['reference_time'])) {
            throw new RuntimeException('ECMWF IFS 0.25° metadata is missing its model run time.');
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
