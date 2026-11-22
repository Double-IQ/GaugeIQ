<?php
declare(strict_types=1);

require_once __DIR__ . '/WeatherMapOmReader.php';

/** Prefetches only newly scheduled frames and their regional .om cache blocks. */
final class WeatherMapRegionalPrefetcher
{
    private const BLOCK_SIZE = 65536;
    private const USER_AGENT = 'GaugeIQ-WeatherMap/1.0';

    public function __construct(
        private readonly string $proxyBaseUrl,
        private readonly int $concurrency = 6,
        private readonly string $decodedCacheDirectory = ''
    )
    {
        if (!str_starts_with($this->proxyBaseUrl, 'https://')) {
            throw new InvalidArgumentException('The GaugeIQ weather-map proxy must use HTTPS.');
        }
        if ($this->concurrency < 1 || $this->concurrency > 16) {
            throw new InvalidArgumentException('Weather-map prefetch concurrency must be between 1 and 16.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for regional weather-map prefetch.');
        }
    }

    /** @return array{frames:int,blocks:int,output:string} */
    public function prefetch(array $frames): array
    {
        if (count($frames) < 1 || count($frames) > 12) {
            throw new RuntimeException('Regional weather-map prefetch requires between 1 and 12 newly scheduled ECMWF IFS 0.25° frames.');
        }
        // Forecast frames (current hour onward) first; then work backwards from the newest past frame.
        usort($frames, static function (array $a, array $b): int {
            $rankA = ($a['type'] ?? '') === 'future' ? 0 : 1;
            $rankB = ($b['type'] ?? '') === 'future' ? 0 : 1;
            if ($rankA !== $rankB) return $rankA <=> $rankB;
            $timeA = strtotime((string)($a['time'] ?? '')) ?: 0;
            $timeB = strtotime((string)($b['time'] ?? '')) ?: 0;
            return $rankA === 0 ? $timeA <=> $timeB : $timeB <=> $timeA;
        });

        $encodingSummaries = [];
        $blockCount = 0;
        $forecastFrames = array_values(array_filter(
            $frames,
            static fn(array $frame): bool => ($frame['type'] ?? '') === 'future'
        ));
        $pastFrames = array_values(array_filter(
            $frames,
            static fn(array $frame): bool => ($frame['type'] ?? '') === 'past'
        ));

        // Complete the entire forecast batch before starting any historical-frame
        // downloads. This is a strict phase boundary, not just a sort preference.
        foreach ([$forecastFrames, $pastFrames] as $frameGroup) {
            if ($frameGroup === []) continue;

            $requests = [];
            foreach ($frameGroup as $frame) {
                $upstreamUrl = (string)($frame['url'] ?? '');
                $this->validateFrameUrl($upstreamUrl);
                $fileSize = $this->fetchFileSize($upstreamUrl);
                $reader = new WeatherMapOmReader(
                    fn(int $offset, int $length): string => $this->fetchProxyRange($upstreamUrl, $offset, $length),
                    $fileSize
                );
                $crs = $reader->getString('crs_wkt');
                if (str_contains($crs, 'Gaussian Grid')) {
                    throw new RuntimeException('The ECMWF IFS 0.25° map frame unexpectedly uses a Gaussian grid.');
                }

                $cloud = $reader->getArrayInfo('cloud_cover');
                $precipitation = $reader->getArrayInfo('precipitation');
                foreach (['cloud_cover' => $cloud, 'precipitation' => $precipitation] as $fieldName => $fieldInfo) {
                    $encodingSummaries[$fieldName][implode('|', [
                        (string)$fieldInfo['data_type'],
                        (string)$fieldInfo['compression_type'],
                        sprintf('%.9g', $fieldInfo['scale_factor']),
                        sprintf('%.9g', $fieldInfo['add_offset']),
                    ])] = true;
                }
                if ($cloud['dimensions'] !== $precipitation['dimensions']) {
                    throw new RuntimeException('Cloud-cover and precipitation fields use different ECMWF grid dimensions.');
                }
                foreach ([$cloud, $precipitation] as $field) {
                    foreach (WeatherMapOmReader::selectRegularGridChunkRanges($field) as [$start, $end]) {
                        $this->addAlignedBlocks($requests, $upstreamUrl, $fileSize, $start, $end);
                    }
                }
            }

            $groupBlockCount = count($requests);
            $this->fetchBlocksConcurrently(array_values($requests));
            $blockCount += $groupBlockCount;

            // Decode the four visible past frames on the server after their
            // regional compressed blocks have been warmed. Future frames are
            // retained for Rain Radar but are not part of the map animation.
            if (($frameGroup[0]['type'] ?? '') === 'past') {
                foreach ($frameGroup as $frame) {
                    $this->decodeAndCachePastFrame($frame);
                }
            }
        }
        $encodingText = [];
        foreach ($encodingSummaries as $fieldName => $signatures) {
            foreach (array_keys($signatures) as $signature) {
                [$dataType, $compressionType, $scaleFactor, $addOffset] = explode('|', $signature);
                $encodingText[] = sprintf(
                    '%s(data_type=%s, compression=%s, scale=%s, offset=%s)',
                    $fieldName,
                    $dataType,
                    $compressionType,
                    $scaleFactor,
                    $addOffset
                );
            }
        }
        return [
            'frames' => count($frames),
            'blocks' => $blockCount,
            'output' => sprintf(
                'Regional ECMWF prefetch completed: %d frames, %d unique 64 KiB cache blocks. Encoding: %s.',
                count($frames),
                $blockCount,
                $encodingText === [] ? 'unavailable' : implode('; ', $encodingText)
            ),
        ];
    }


    /**
     * Decode the South Africa regional grid for a past frame and persist a
     * compact Float32 cache. The browser can consume these prepared values in
     * the server-tile stage without parsing OM metadata or PFor chunks.
     */
    private function decodeAndCachePastFrame(array $frame): void
    {
        $url = (string)($frame['url'] ?? '');
        $this->validateFrameUrl($url);
        $fileSize = $this->fetchFileSize($url);
        $reader = new WeatherMapOmReader(
            fn(int $offset, int $length): string => $this->fetchProxyRange($url, $offset, $length),
            $fileSize
        );

        $west = -25.0;
        $south = -45.0;
        $east = 60.0;
        $north = 5.0;
        $step = 0.25;
        $width = 1440;
        $height = 721;
        $xStart = max(0, (int)floor(($west + 180.0) / $step) - 2);
        $xEnd = min($width, (int)ceil(($east + 180.0) / $step) + 3);
        $yStart = max(0, (int)floor(($south + 90.0) / $step) - 2);
        $yEnd = min($height, (int)ceil(($north + 90.0) / $step) + 3);

        $indices = [];
        for ($y = $yStart; $y < $yEnd; $y++) {
            $rowStart = $y * $width;
            for ($x = $xStart; $x < $xEnd; $x++) {
                $indices[] = $rowStart + $x;
            }
        }
        if ($indices === []) {
            throw new RuntimeException('The server-side weather-map decode region is empty.');
        }

        $directory = $this->decodedCacheDirectory !== ''
            ? $this->decodedCacheDirectory
            : dirname(__DIR__) . '/storage/weather-map-cache/decoded';
        if (!str_starts_with($directory, '/')) {
            throw new RuntimeException('The decoded weather-map cache directory must be an absolute path.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the decoded weather-map cache directory.');
        }

        foreach (['cloud_cover', 'precipitation'] as $variable) {
            $field = $reader->getArrayInfo($variable);
            if (($field['dimensions'] ?? null) !== [$height, $width]) {
                throw new RuntimeException('The ' . $variable . ' field does not match the ECMWF 0.25-degree regular grid.');
            }
            $values = $reader->decodeFloatArrayGridIndices($field, $indices);
            if (count($values) !== count($indices)) {
                throw new RuntimeException('The server did not decode every requested ' . $variable . ' grid cell.');
            }

            $binary = '';
            foreach ($indices as $index) {
                $value = (float)$values[$index];
                $binary .= pack('g', $value);
            }
            $key = hash('sha256', $url . '|' . $variable . '|sa-grid-v1');
            $dataPath = rtrim($directory, '/') . '/' . $key . '.f32';
            $metaPath = rtrim($directory, '/') . '/' . $key . '.json';
            $metadata = [
                'version' => 1,
                'source_url_sha256' => hash('sha256', $url),
                'frame_time' => (string)($frame['time'] ?? ''),
                'variable' => $variable,
                'grid' => ['width' => $width, 'height' => $height, 'step_degrees' => $step],
                'region' => [
                    'west' => $west, 'south' => $south, 'east' => $east, 'north' => $north,
                    'x_start' => $xStart, 'x_end' => $xEnd,
                    'y_start' => $yStart, 'y_end' => $yEnd,
                    'columns' => $xEnd - $xStart, 'rows' => $yEnd - $yStart,
                ],
                'encoding' => 'float32-le',
                'cell_count' => count($indices),
                'prepared_at' => gmdate('c'),
            ];

            $suffix = '.' . bin2hex(random_bytes(6)) . '.tmp';
            $temporaryData = $dataPath . $suffix;
            $temporaryMeta = $metaPath . $suffix;
            if (file_put_contents($temporaryData, $binary, LOCK_EX) !== strlen($binary)) {
                @unlink($temporaryData);
                throw new RuntimeException('Unable to write the decoded ' . $variable . ' weather-map cache.');
            }
            if (file_put_contents(
                $temporaryMeta,
                json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                LOCK_EX
            ) === false) {
                @unlink($temporaryData);
                @unlink($temporaryMeta);
                throw new RuntimeException('Unable to write decoded weather-map metadata.');
            }
            if (!@rename($temporaryData, $dataPath) || !@rename($temporaryMeta, $metaPath)) {
                @unlink($temporaryData);
                @unlink($temporaryMeta);
                throw new RuntimeException('Unable to publish the decoded weather-map cache atomically.');
            }
        }
    }

    private function validateFrameUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'openmeteo.s3.amazonaws.com' ||
            !preg_match('#^/data_spatial/ecmwf_ifs025/.+\.om$#', (string)($parts['path'] ?? '')) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('The scheduled ECMWF IFS 0.25° frame URL is invalid.');
        }
    }

    private function proxyUrl(string $upstreamUrl, bool $metadata = false): string
    {
        $query = ['url' => $upstreamUrl];
        if ($metadata) $query['metadata'] = '1';
        return rtrim($this->proxyBaseUrl, '/') . '/api/weather-map-proxy.php?' .
            http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function fetchFileSize(string $upstreamUrl): int
    {
        $curl = curl_init($this->proxyUrl($upstreamUrl, true));
        if ($curl === false) throw new RuntimeException('Unable to initialise weather-map metadata request.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException('Weather-map proxy metadata request failed' .
                ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ').'));
        }
        try {
            $metadata = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Weather-map proxy returned invalid metadata JSON.', 0, $exception);
        }
        $size = is_array($metadata) ? (int)($metadata['size'] ?? 0) : 0;
        if ($size < 27) throw new RuntimeException('Weather-map proxy returned an invalid ECMWF file size.');
        return $size;
    }

    private function fetchProxyRange(string $upstreamUrl, int $offset, int $length): string
    {
        if ($length < 1 || $length > self::BLOCK_SIZE) {
            throw new InvalidArgumentException('Weather-map proxy ranges must be between 1 and 64 KiB.');
        }
        $curl = curl_init($this->proxyUrl($upstreamUrl));
        if ($curl === false) throw new RuntimeException('Unable to initialise weather-map range request.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_RANGE => $offset . '-' . ($offset + $length - 1),
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status !== 206 || strlen($body) !== $length) {
            throw new RuntimeException('Weather-map proxy range request failed' .
                ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ', ' .
                    (is_string($body) ? strlen($body) : 0) . ' bytes).'));
        }
        return $body;
    }

    private function addAlignedBlocks(array &$requests, string $url, int $fileSize, int $start, int $end): void
    {
        if ($start < 0 || $end < $start || $end > $fileSize) {
            throw new RuntimeException('The regional ECMWF chunk range is outside the file.');
        }
        if ($start === $end) return;
        $firstBlock = intdiv($start, self::BLOCK_SIZE) * self::BLOCK_SIZE;
        $lastBlock = intdiv($end - 1, self::BLOCK_SIZE) * self::BLOCK_SIZE;
        for ($offset = $firstBlock; $offset <= $lastBlock; $offset += self::BLOCK_SIZE) {
            $length = min(self::BLOCK_SIZE, $fileSize - $offset);
            $key = hash('sha256', $url . '|' . $offset . '|' . $length);
            $requests[$key] = ['url' => $url, 'offset' => $offset, 'length' => $length];
        }
    }

    private function fetchBlocksConcurrently(array $requests): void
    {
        if ($requests === []) return;
        if (!function_exists('curl_multi_init')) {
            foreach ($requests as $request) {
                $this->fetchProxyRange($request['url'], $request['offset'], $request['length']);
            }
            return;
        }
        $multi = curl_multi_init();
        if ($multi === false) throw new RuntimeException('Unable to initialise concurrent weather-map requests.');
        $next = 0;
        $active = [];
        $failure = null;
        try {
            do {
                while ($next < count($requests) && count($active) < $this->concurrency) {
                    $request = $requests[$next++];
                    $curl = curl_init($this->proxyUrl($request['url']));
                    if ($curl === false) throw new RuntimeException('Unable to initialise a regional weather-map block request.');
                    curl_setopt_array($curl, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TIMEOUT => 60,
                        CURLOPT_USERAGENT => self::USER_AGENT,
                        CURLOPT_RANGE => $request['offset'] . '-' . ($request['offset'] + $request['length'] - 1),
                        CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
                        CURLOPT_PRIVATE => (string)$next,
                    ]);
                    if (curl_multi_add_handle($multi, $curl) !== CURLM_OK) {
                        curl_close($curl);
                        throw new RuntimeException('Unable to queue a regional weather-map block request.');
                    }
                    $active[(string)$next] = ['handle' => $curl, 'request' => $request];
                }

                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);
                if ($multiStatus !== CURLM_OK) throw new RuntimeException('Concurrent weather-map requests failed to execute.');

                while ($info = curl_multi_info_read($multi)) {
                    $curl = $info['handle'];
                    $private = (string)curl_getinfo($curl, CURLINFO_PRIVATE);
                    $entry = $active[$private] ?? null;
                    $body = curl_multi_getcontent($curl);
                    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                    $error = curl_error($curl);
                    curl_multi_remove_handle($multi, $curl);
                    curl_close($curl);
                    unset($active[$private]);
                    if ($entry === null || $info['result'] !== CURLE_OK || !is_string($body) ||
                        $status !== 206 || strlen($body) !== $entry['request']['length']) {
                        $failure = new RuntimeException('Regional weather-map block download failed' .
                            ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ').'));
                        break 2;
                    }
                }
                if ($running > 0 && curl_multi_select($multi, 1.0) === -1) usleep(10000);
            } while ($running > 0 || $next < count($requests));
        } finally {
            foreach ($active as $entry) {
                curl_multi_remove_handle($multi, $entry['handle']);
                curl_close($entry['handle']);
            }
            curl_multi_close($multi);
        }
        if ($failure instanceof Throwable) throw $failure;
    }
}
