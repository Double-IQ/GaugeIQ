<?php
declare(strict_types=1);

/**
 * Bounds-checked reader for Open-Meteo .om v3 metadata and 64-bit PFor-delta
 * lookup tables. It exposes array encoding metadata for field decoders.
 */
final class WeatherMapOmReader
{
    private const LUT_CHUNK_COUNT = 64;
    private const IO_BLOCK_SIZE = 65536;

    private Closure $rangeReader;
    private int $fileSize;
    private ?array $rootNode = null;
    private array $readBlockCache = [];

    public function __construct(callable $rangeReader, int $fileSize)
    {
        if ($fileSize < 27) {
            throw new InvalidArgumentException('The .om file is too small to contain a valid header and trailer.');
        }
        $this->rangeReader = Closure::fromCallable($rangeReader);
        $this->fileSize = $fileSize;
    }

    public function getString(string $name): string
    {
        $node = $this->findNode($name);
        if ($node === null || $node['data_type'] !== 11 || !is_string($node['string_value'])) {
            throw new RuntimeException('The .om metadata is missing string variable ' . $name . '.');
        }
        return $node['string_value'];
    }

    public function getArrayInfo(string $name): array
    {
        $node = $this->findNode($name);
        if ($node === null || !$node['is_array']) {
            throw new RuntimeException('The .om metadata is missing array variable ' . $name . '.');
        }
        $dimensions = $node['dimensions'];
        $chunks = $node['chunks'];
        if (count($dimensions) !== 2 || count($chunks) !== 2) {
            throw new RuntimeException('ECMWF regional prefetch requires a two-dimensional .om array.');
        }
        $chunkCount = 1;
        for ($i = 0; $i < 2; $i++) {
            if ($dimensions[$i] < 1 || $chunks[$i] < 1 || $chunks[$i] > $dimensions[$i]) {
                throw new RuntimeException('The .om array has invalid dimensions or chunk dimensions.');
            }
            $count = intdiv($dimensions[$i] + $chunks[$i] - 1, $chunks[$i]);
            if ($chunkCount > intdiv(PHP_INT_MAX, $count)) {
                throw new RuntimeException('The .om array contains too many data chunks.');
            }
            $chunkCount *= $count;
        }
        $lutOffset = $node['lut_offset'];
        $lutSize = $node['lut_size'];
        if ($lutSize < 1 || $lutSize > 67108864 || $lutOffset < 3 ||
            $lutOffset > $this->fileSize - $lutSize) {
            throw new RuntimeException('The .om array lookup table points outside the file or exceeds the size limit.');
        }
        $lut = self::decodeV3LookupTable($this->readAt($lutOffset, $lutSize), $chunkCount);
        if ($lut[0] < 3 || $lut[count($lut) - 1] > $this->fileSize) {
            throw new RuntimeException('The .om array lookup table contains offsets outside the file.');
        }
        return [
            'name' => $name,
            'data_type' => $node['data_type'],
            'compression_type' => $node['compression_type'],
            'scale_factor' => $node['scale_factor'],
            'add_offset' => $node['add_offset'],
            'dimensions' => $dimensions,
            'chunks' => $chunks,
            'chunk_count' => $chunkCount,
            'lut' => $lut,
        ];
    }

    /** Exact port of Open-Meteo's O1280/O320 Gaussian-grid edge sampling. */
    public static function regionalGaussianIndexRange(int $pointCount): array
    {
        $lines = match ($pointCount) {
            6599680 => 1280,
            421120 => 320,
            default => throw new RuntimeException('Unsupported ECMWF Gaussian grid point count: ' . $pointCount),
        };
        $lineCounts = [];
        $prefix = [0];
        for ($y = 0; $y < 2 * $lines; $y++) {
            $nx = $y < $lines ? 20 + 4 * $y : (2 * $lines - $y - 1) * 4 + 20;
            $lineCounts[$y] = $nx;
            $prefix[$y + 1] = $prefix[$y] + $nx;
        }
        $points = [];
        for ($lon = -25.0; $lon <= 60.000001; $lon += 0.5) {
            $points[] = self::gaussianPointIndex($lines, $lineCounts, $prefix, -45.0, $lon);
            $points[] = self::gaussianPointIndex($lines, $lineCounts, $prefix, 5.0, $lon);
        }
        for ($lat = -45.0; $lat <= 5.000001; $lat += 0.5) {
            $points[] = self::gaussianPointIndex($lines, $lineCounts, $prefix, $lat, -25.0);
            $points[] = self::gaussianPointIndex($lines, $lineCounts, $prefix, $lat, 60.0);
        }
        if (count($points) < 272) {
            throw new RuntimeException('The agreed weather-map region could not be mapped to the Gaussian grid.');
        }
        return [max(0, min($points) - 2), min($pointCount, max($points) + 3)];
    }

    public static function selectChunkRanges(array $arrayInfo, int $startIndex, int $endIndex): array
    {
        [$height, $width] = $arrayInfo['dimensions'];
        [, $chunkWidth] = $arrayInfo['chunks'];
        if ($height !== 1 || $startIndex < 0 || $endIndex <= $startIndex || $endIndex > $width) {
            throw new RuntimeException('The ECMWF array is not in the expected flattened Gaussian-grid layout.');
        }
        $firstX = intdiv($startIndex, $chunkWidth);
        $lastX = intdiv($endIndex - 1, $chunkWidth);
        $ranges = [];
        for ($x = $firstX; $x <= $lastX; $x++) {
            $chunkIndex = $x;
            if (!isset($arrayInfo['lut'][$chunkIndex + 1])) {
                throw new RuntimeException('The .om lookup table is missing a regional data chunk.');
            }
            $start = $arrayInfo['lut'][$chunkIndex];
            $end = $arrayInfo['lut'][$chunkIndex + 1];
            if ($end < $start) {
                throw new RuntimeException('The .om data chunk has an invalid byte range.');
            }
            if ($end > $start) $ranges[] = [$start, $end];
        }
        return $ranges;
    }

    /**
     * Select compressed chunks intersecting the GaugeIQ South Africa region
     * on the native ECMWF IFS 0.25° regular latitude/longitude grid.
     * Spatial arrays use [ny, nx] = [721, 1440] and [lat, lon] ordering.
     */
    public static function selectRegularGridChunkRanges(
        array $arrayInfo,
        float $west = -25.0,
        float $south = -45.0,
        float $east = 60.0,
        float $north = 5.0
    ): array {
        [$height, $width] = $arrayInfo['dimensions'];
        [$chunkHeight, $chunkWidth] = $arrayInfo['chunks'];
        if ($height !== 721 || $width !== 1440 || $chunkHeight < 1 || $chunkWidth < 1 ||
            $west < -180 || $east > 180 || $south < -90 || $north > 90 ||
            $west >= $east || $south >= $north) {
            throw new RuntimeException('The ECMWF IFS 0.25° field does not match the expected 1440 × 721 regular grid.');
        }

        // Include a two-cell margin around the map viewport for cubic
        // interpolation and edge sampling by the browser renderer.
        $xStart = max(0, (int)floor(($west + 180.0) / 0.25) - 2);
        $xEnd = min($width, (int)ceil(($east + 180.0) / 0.25) + 3);
        $yStart = max(0, (int)floor(($south + 90.0) / 0.25) - 2);
        $yEnd = min($height, (int)ceil(($north + 90.0) / 0.25) + 3);

        $chunksAcross = intdiv($width + $chunkWidth - 1, $chunkWidth);
        $firstChunkX = intdiv($xStart, $chunkWidth);
        $lastChunkX = intdiv($xEnd - 1, $chunkWidth);
        $firstChunkY = intdiv($yStart, $chunkHeight);
        $lastChunkY = intdiv($yEnd - 1, $chunkHeight);
        $ranges = [];

        // OM v3 stores multidimensional chunks in row-major order.
        for ($chunkY = $firstChunkY; $chunkY <= $lastChunkY; $chunkY++) {
            for ($chunkX = $firstChunkX; $chunkX <= $lastChunkX; $chunkX++) {
                $chunkIndex = ($chunkY * $chunksAcross) + $chunkX;
                if (!isset($arrayInfo['lut'][$chunkIndex + 1])) {
                    throw new RuntimeException('The .om lookup table is missing a regular-grid regional chunk.');
                }
                $start = $arrayInfo['lut'][$chunkIndex];
                $end = $arrayInfo['lut'][$chunkIndex + 1];
                if ($end < $start) {
                    throw new RuntimeException('The .om regular-grid chunk has an invalid byte range.');
                }
                if ($end > $start) $ranges[] = [$start, $end];
            }
        }
        return $ranges;
    }

    /** Reads aligned cache blocks, matching the map reader's 64 KiB block size. */
    public function readAt(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset > $this->fileSize ||
            $length > $this->fileSize - $offset) {
            throw new RuntimeException('The .om file read is outside the object bounds.');
        }
        if ($length === 0) return '';
        $firstBlock = intdiv($offset, self::IO_BLOCK_SIZE) * self::IO_BLOCK_SIZE;
        $lastBlock = intdiv($offset + $length - 1, self::IO_BLOCK_SIZE) * self::IO_BLOCK_SIZE;
        $buffer = '';
        for ($block = $firstBlock; $block <= $lastBlock; $block += self::IO_BLOCK_SIZE) {
            $blockLength = min(self::IO_BLOCK_SIZE, $this->fileSize - $block);
            if (!array_key_exists($block, $this->readBlockCache)) {
                $data = ($this->rangeReader)($block, $blockLength);
                if (!is_string($data) || strlen($data) !== $blockLength) {
                    throw new RuntimeException('The weather-map proxy returned an incomplete .om byte range.');
                }
                $this->readBlockCache[$block] = $data;
            }
            $buffer .= $this->readBlockCache[$block];
        }
        return substr($buffer, $offset - $firstBlock, $length);
    }

    /** Decode one Open-Meteo 64-bit PFor-delta stream (at most 128 values per PFor block). */
    public static function decodePforDelta64(string $input, int $count): array
    {
        if ($count < 1) throw new InvalidArgumentException('The PFor stream must contain at least one value.');
        $position = 0;
        $previous = self::readSeed64($input, $position);
        $values = [$previous];

        while (count($values) < $count) {
            self::need($input, $position, 1);
            $header = ord($input[$position++]);
            $width = $header & 0x3f;
            $remaining = min(128, $count - count($values));
            $deltas = [];

            if (($header & 0xc0) === 0xc0) {
                if ($width === 63) $width = 64;
                $constant = self::readLittleUnsigned($input, $position, intdiv($width + 7, 8));
                $deltas = array_fill(0, $remaining, $constant);
            } elseif (($header & 0xc0) === 0) {
                $deltas = self::unpackBits($input, $position, $remaining, $width);
            } elseif (($header & 0x80) !== 0) {
                self::need($input, $position, 1);
                $exceptionWidth = ord($input[$position++]);
                if ($width >= 64 || $exceptionWidth < 1 || $exceptionWidth > 63 - $width) {
                    throw new RuntimeException('Invalid PFor bitmap exception widths.');
                }
                $bitmapLength = intdiv($remaining + 7, 8);
                self::need($input, $position, $bitmapLength);
                $bitmap = substr($input, $position, $bitmapLength);
                $position += $bitmapLength;
                $exceptionPositions = [];
                for ($i = 0; $i < $remaining; $i++) {
                    if ((ord($bitmap[intdiv($i, 8)]) & (1 << ($i % 8))) !== 0) {
                        $exceptionPositions[] = $i;
                    }
                }
                if (($remaining % 8) !== 0 && $bitmapLength > 0) {
                    $unusedMask = 0xff << ($remaining % 8);
                    if ((ord($bitmap[$bitmapLength - 1]) & $unusedMask) !== 0) {
                        throw new RuntimeException('Invalid PFor bitmap padding bits.');
                    }
                }
                $exceptions = self::unpackBits($input, $position, count($exceptionPositions), $exceptionWidth);
                $base = self::unpackBits($input, $position, $remaining, $width);
                foreach ($exceptionPositions as $index => $target) {
                    if ($exceptions[$index] > (PHP_INT_MAX >> $width)) {
                        throw new RuntimeException('PFor exception value exceeds the supported offset range.');
                    }
                    $extra = $exceptions[$index] << $width;
                    if ($extra > PHP_INT_MAX - $base[$target]) {
                        throw new RuntimeException('PFor exception value exceeds the supported offset range.');
                    }
                    $base[$target] += $extra;
                }
                $deltas = $base;
            } else {
                self::need($input, $position, 1);
                $exceptionCount = ord($input[$position++]);
                if ($width >= 63 || $exceptionCount < 1 || $exceptionCount > $remaining) {
                    throw new RuntimeException('Invalid PFor variable-byte exception header.');
                }
                $base = self::unpackBits($input, $position, $remaining, $width);
                $exceptions = self::readVariableByte64Values($input, $position, $exceptionCount);
                self::need($input, $position, $exceptionCount);
                $positions = array_values(unpack('C*', substr($input, $position, $exceptionCount)) ?: []);
                $position += $exceptionCount;
                foreach ($positions as $index => $target) {
                    if ($target >= $remaining) {
                        throw new RuntimeException('PFor exception position is outside the block.');
                    }
                    if ($exceptions[$index] > (PHP_INT_MAX >> $width)) {
                        throw new RuntimeException('PFor exception value exceeds the supported offset range.');
                    }
                    $extra = $exceptions[$index] << $width;
                    if ($extra > PHP_INT_MAX - $base[$target]) {
                        throw new RuntimeException('PFor exception value exceeds the supported offset range.');
                    }
                    $base[$target] += $extra;
                }
                $deltas = $base;
            }

            foreach ($deltas as $delta) {
                if ($delta < 0 || $delta > PHP_INT_MAX - $previous) {
                    throw new RuntimeException('PFor delta overflows the supported file-offset range.');
                }
                $previous += $delta;
                $values[] = $previous;
            }
        }
        return $values;
    }

    /** Apply the OM Float32/PFor-int16 scale convention, including its NaN sentinel. */
    public static function decodeScaledPfor16Value(int $raw, float $scaleFactor, float $addOffset): float
    {
        if ($raw === 32767) return NAN;
        if (!is_finite($scaleFactor) || $scaleFactor == 0.0 || !is_finite($addOffset)) {
            throw new RuntimeException('The ECMWF field has invalid value scaling metadata.');
        }
        return ($raw / $scaleFactor) - $addOffset;
    }

    /** Decode a selected range from a flattened 16-bit ECMWF float array. */
    public function decodeFloatArrayRange(array $arrayInfo, int $startIndex, int $endIndex): array
    {
        if (($arrayInfo['data_type'] ?? null) !== 20 || ($arrayInfo['compression_type'] ?? null) !== 0) {
            throw new RuntimeException('The ECMWF field does not use the supported Float32/PFor-int16 encoding.');
        }
        [$height, $width] = $arrayInfo['dimensions'];
        [, $chunkWidth] = $arrayInfo['chunks'];
        if ($height !== 1 || $startIndex < 0 || $endIndex <= $startIndex || $endIndex > $width) {
            throw new RuntimeException('The ECMWF field range is outside the expected flattened Gaussian grid.');
        }
        $firstChunk = intdiv($startIndex, $chunkWidth);
        $lastChunk = intdiv($endIndex - 1, $chunkWidth);
        $decoded = [];
        for ($chunkIndex = $firstChunk; $chunkIndex <= $lastChunk; $chunkIndex++) {
            if (!isset($arrayInfo['lut'][$chunkIndex + 1])) {
                throw new RuntimeException('The ECMWF field lookup table is missing a requested data chunk.');
            }
            $chunkStart = $chunkIndex * $chunkWidth;
            $chunkCount = min($chunkWidth, $width - $chunkStart);
            $byteStart = $arrayInfo['lut'][$chunkIndex];
            $byteEnd = $arrayInfo['lut'][$chunkIndex + 1];
            if ($byteEnd <= $byteStart) {
                throw new RuntimeException('An ECMWF data chunk has no compressed payload.');
            }
            $compressed = $this->readAt($byteStart, $byteEnd - $byteStart);
            $integers = self::decodePforDelta16($compressed, $chunkCount);
            if (count($integers) !== $chunkCount) {
                throw new RuntimeException('The ECMWF data chunk decoded to an unexpected number of values.');
            }
            $from = max($startIndex, $chunkStart);
            $to = min($endIndex, $chunkStart + $chunkCount);
            for ($index = $from; $index < $to; $index++) {
                $raw = $integers[$index - $chunkStart];
                $decoded[$index] = self::decodeScaledPfor16Value($raw, (float)$arrayInfo['scale_factor'], (float)$arrayInfo['add_offset']);
            }
        }
        return $decoded;
    }

    /** Decode only requested grid indices, fetching each compressed chunk once. */
    public function decodeFloatArrayIndices(array $arrayInfo, array $indices): array
    {
        if (($arrayInfo['data_type'] ?? null) !== 20 || ($arrayInfo['compression_type'] ?? null) !== 0) {
            throw new RuntimeException('The ECMWF field does not use the supported Float32/PFor-int16 encoding.');
        }
        [$height, $width] = $arrayInfo['dimensions'];
        [, $chunkWidth] = $arrayInfo['chunks'];
        if ($height !== 1 || $chunkWidth < 1) {
            throw new RuntimeException('The ECMWF field is not in the expected flattened Gaussian-grid layout.');
        }
        $requested = [];
        foreach ($indices as $index) {
            $index = (int)$index;
            if ($index < 0 || $index >= $width) throw new RuntimeException('A requested ECMWF grid index is outside the array.');
            $requested[$index] = true;
        }
        if ($requested === []) return [];
        $byChunk = [];
        foreach (array_keys($requested) as $index) {
            $byChunk[intdiv($index, $chunkWidth)][] = $index;
        }
        ksort($byChunk, SORT_NUMERIC);
        $decoded = [];
        foreach ($byChunk as $chunkIndex => $chunkIndices) {
            if (!isset($arrayInfo['lut'][$chunkIndex + 1])) {
                throw new RuntimeException('The ECMWF field lookup table is missing a requested data chunk.');
            }
            $chunkStart = $chunkIndex * $chunkWidth;
            $chunkCount = min($chunkWidth, $width - $chunkStart);
            $byteStart = $arrayInfo['lut'][$chunkIndex];
            $byteEnd = $arrayInfo['lut'][$chunkIndex + 1];
            if ($byteEnd <= $byteStart) throw new RuntimeException('An ECMWF data chunk has no compressed payload.');
            $integers = self::decodePforDelta16($this->readAt($byteStart, $byteEnd - $byteStart), $chunkCount);
            foreach ($chunkIndices as $index) {
                $raw = $integers[$index - $chunkStart];
                $decoded[$index] = self::decodeScaledPfor16Value($raw, (float)$arrayInfo['scale_factor'], (float)$arrayInfo['add_offset']);
            }
        }
        return $decoded;
    }

    /**
     * Decode selected flattened indices from a two-dimensional row-major grid.
     * OM chunks are stored row-major; indices exposed to callers are y * width + x.
     */
    public function decodeFloatArrayGridIndices(array $arrayInfo, array $indices): array
    {
        if (($arrayInfo['data_type'] ?? null) !== 20 || ($arrayInfo['compression_type'] ?? null) !== 0) {
            throw new RuntimeException('The ECMWF field does not use the supported Float32/PFor-int16 encoding.');
        }
        [$height, $width] = $arrayInfo['dimensions'];
        [$chunkHeight, $chunkWidth] = $arrayInfo['chunks'];
        if ($height < 1 || $width < 1 || $chunkHeight < 1 || $chunkWidth < 1 ||
            $chunkHeight > $height || $chunkWidth > $width) {
            throw new RuntimeException('The ECMWF field has invalid regular-grid dimensions or chunk dimensions.');
        }

        $requested = [];
        foreach ($indices as $index) {
            $index = (int)$index;
            if ($index < 0 || $index >= $height * $width) {
                throw new RuntimeException('A requested ECMWF regular-grid index is outside the array.');
            }
            $requested[$index] = true;
        }
        if ($requested === []) return [];

        $chunksAcross = intdiv($width + $chunkWidth - 1, $chunkWidth);
        $byChunk = [];
        foreach (array_keys($requested) as $index) {
            $y = intdiv($index, $width);
            $x = $index % $width;
            $chunkY = intdiv($y, $chunkHeight);
            $chunkX = intdiv($x, $chunkWidth);
            $chunkIndex = $chunkY * $chunksAcross + $chunkX;
            $byChunk[$chunkIndex][] = [$index, $y, $x];
        }
        ksort($byChunk, SORT_NUMERIC);

        $decoded = [];
        foreach ($byChunk as $chunkIndex => $chunkCells) {
            if (!isset($arrayInfo['lut'][$chunkIndex + 1])) {
                throw new RuntimeException('The ECMWF field lookup table is missing a requested regular-grid chunk.');
            }
            $chunkY = intdiv($chunkIndex, $chunksAcross);
            $chunkX = $chunkIndex % $chunksAcross;
            $chunkStartY = $chunkY * $chunkHeight;
            $chunkStartX = $chunkX * $chunkWidth;
            $actualChunkHeight = min($chunkHeight, $height - $chunkStartY);
            $actualChunkWidth = min($chunkWidth, $width - $chunkStartX);
            $chunkCount = $actualChunkHeight * $actualChunkWidth;
            $byteStart = $arrayInfo['lut'][$chunkIndex];
            $byteEnd = $arrayInfo['lut'][$chunkIndex + 1];
            if ($byteEnd < $byteStart) {
                throw new RuntimeException('An ECMWF regular-grid data chunk has an invalid byte range.');
            }
            // OM v3 represents an all-NaN floating-point chunk with equal
            // adjacent lookup-table offsets. Match the official reader rather
            // than treating this valid sparse chunk as corrupt.
            if ($byteEnd === $byteStart) {
                foreach ($chunkCells as [$index]) $decoded[$index] = NAN;
                continue;
            }
            $integers = self::decodePforDelta16(
                $this->readAt($byteStart, $byteEnd - $byteStart),
                $chunkCount
            );
            if (count($integers) !== $chunkCount) {
                throw new RuntimeException('An ECMWF regular-grid chunk decoded to an unexpected number of values.');
            }
            foreach ($chunkCells as [$index, $y, $x]) {
                $localIndex = ($y - $chunkStartY) * $actualChunkWidth + ($x - $chunkStartX);
                $decoded[$index] = self::decodeScaledPfor16Value(
                    $integers[$localIndex],
                    (float)$arrayInfo['scale_factor'],
                    (float)$arrayInfo['add_offset']
                );
            }
        }
        return $decoded;
    }

    /**
     * Return regular 0.25-degree latitude/longitude grid cells within a
     * great-circle radius. The grid is [721, 1440], south-to-north and
     * west-to-east, with longitude wrapping at the antimeridian.
     */
    public static function regularGridNeighborhood(
        float $latitude,
        float $longitude,
        float $radiusKm,
        int $height = 721,
        int $width = 1440,
        float $stepDegrees = 0.25
    ): array {
        if ($height !== 721 || $width !== 1440 || $stepDegrees !== 0.25 ||
            $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ||
            !is_finite($radiusKm) || $radiusKm <= 0 || $radiusKm > 1000) {
            throw new InvalidArgumentException('Invalid ECMWF regular-grid dimensions, coordinates or radius.');
        }

        $latMargin = $radiusKm / 110.5 + $stepDegrees;
        $cosLat = abs(cos(deg2rad($latitude)));
        $lonMargin = $cosLat < 0.01 ? 180.0 : min(180.0, $radiusKm / (111.0 * $cosLat) + $stepDegrees);
        $firstY = max(0, (int)floor((($latitude - $latMargin) + 90.0) / $stepDegrees));
        $lastY = min($height - 1, (int)ceil((($latitude + $latMargin) + 90.0) / $stepDegrees));
        $cells = [];
        $lat1 = deg2rad($latitude);
        $normalisedLongitude = fmod($longitude + 180.0, 360.0);
        if ($normalisedLongitude < 0) $normalisedLongitude += 360.0;
        $centreX = (int)round($normalisedLongitude / $stepDegrees, 0, PHP_ROUND_HALF_EVEN) % $width;
        $columnRadius = min((int)ceil($lonMargin / $stepDegrees) + 1, intdiv($width, 2));

        for ($y = $firstY; $y <= $lastY; $y++) {
            $cellLatitude = -90.0 + ($y * $stepDegrees);
            $lat2 = deg2rad($cellLatitude);
            for ($dx = -$columnRadius; $dx <= $columnRadius; $dx++) {
                $x = (($centreX + $dx) % $width + $width) % $width;
                $cellLongitude = -180.0 + ($x * $stepDegrees);
                $deltaLat = $lat2 - $lat1;
                $deltaLon = deg2rad($cellLongitude - $longitude);
                $a = sin($deltaLat / 2) ** 2
                    + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
                $distance = 6371.0 * 2.0 * asin(min(1.0, sqrt($a)));
                if ($distance <= $radiusKm) {
                    $index = ($y * $width) + $x;
                    $cells[$index] = [
                        'index' => $index,
                        'latitude' => $cellLatitude,
                        'longitude' => $cellLongitude,
                        'distance_km' => $distance,
                    ];
                }
            }
        }
        ksort($cells, SORT_NUMERIC);
        return array_values($cells);
    }

    /** Return real Gaussian-grid cells within a great-circle radius of a point. */
    public static function gaussianNeighborhood(int $pointCount, float $latitude, float $longitude, float $radiusKm): array
    {
        $lines = match ($pointCount) {
            6599680 => 1280,
            421120 => 320,
            default => throw new RuntimeException('Unsupported ECMWF Gaussian grid point count: ' . $pointCount),
        };
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ||
            $radiusKm <= 0 || $radiusKm > 1000) {
            throw new InvalidArgumentException('Invalid ECMWF Gaussian-grid neighbourhood coordinates or radius.');
        }
        $dy = 180.0 / (2.0 * $lines + 0.5);
        $latMargin = $radiusKm / 110.5 + $dy;
        $cosLat = max(0.05, cos(deg2rad($latitude)));
        $lonMargin = min(180.0, $radiusKm / (111.0 * $cosLat) + 0.2);
        $prefix = 0;
        $cells = [];
        for ($y = 0; $y < 2 * $lines; $y++) {
            $nx = $y < $lines ? 20 + 4 * $y : (2 * $lines - $y - 1) * 4 + 20;
            $cellLatitude = ($lines - $y - 1) * $dy + $dy / 2.0;
            if (abs($cellLatitude - $latitude) <= $latMargin) {
                $step = 360.0 / $nx;
                $centreX = (int)round($longitude / $step, 0, PHP_ROUND_HALF_EVEN);
                $columnRadius = (int)ceil($lonMargin / $step) + 1;
                for ($dx = -$columnRadius; $dx <= $columnRadius; $dx++) {
                    $x = (($centreX + $dx) % $nx + $nx) % $nx;
                    $cellLongitude = $x * $step;
                    if ($cellLongitude > 180.0) $cellLongitude -= 360.0;
                    $lat1 = deg2rad($latitude);
                    $lat2 = deg2rad($cellLatitude);
                    $deltaLat = $lat2 - $lat1;
                    $deltaLon = deg2rad($cellLongitude - $longitude);
                    $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
                    $distance = 6371.0 * 2.0 * asin(min(1.0, sqrt($a)));
                    if ($distance <= $radiusKm) {
                        $index = $prefix + $x;
                        $cells[$index] = [
                            'index' => $index,
                            'latitude' => $cellLatitude,
                            'longitude' => $cellLongitude,
                            'distance_km' => $distance,
                        ];
                    }
                }
            }
            $prefix += $nx;
        }
        ksort($cells, SORT_NUMERIC);
        return array_values($cells);
    }

    /** Decode Open-Meteo's 16-bit PFor-zigzag stream used by ECMWF float arrays. */
    public static function decodePforDelta16(string $input, int $count): array
    {
        if ($count < 1) throw new InvalidArgumentException('The ECMWF PFor stream must contain at least one value.');
        $position = 0;
        $seed = self::readSeed64($input, $position);
        if ($seed > 65535) throw new RuntimeException('The ECMWF PFor seed exceeds 16 bits.');
        $previous = ($seed & 0x8000) !== 0 ? $seed - 65536 : $seed;
        $values = [$previous];

        while (count($values) < $count) {
            self::need($input, $position, 1);
            $header = ord($input[$position++]);
            $width = $header & 0x3f;
            $remaining = min(128, $count - count($values));
            if (($header & 0xc0) === 0xc0) {
                if ($width > 16) throw new RuntimeException('Invalid constant-block width in ECMWF PFor data.');
                $constant = self::readLittleUnsigned($input, $position, intdiv($width + 7, 8));
                $deltas = array_fill(0, $remaining, $constant);
            } elseif (($header & 0x40) === 0) {
                $exceptionWidth = 0;
                if (($header & 0x80) !== 0) {
                    self::need($input, $position, 1);
                    $exceptionWidth = ord($input[$position++]);
                    if ($width >= 16 || $exceptionWidth < 1 || $exceptionWidth > 16 - $width) {
                        throw new RuntimeException('Invalid bitmap exception widths in ECMWF PFor data.');
                    }
                    $bitmapLength = intdiv($remaining + 7, 8);
                    self::need($input, $position, $bitmapLength);
                    $bitmap = substr($input, $position, $bitmapLength);
                    $position += $bitmapLength;
                    $exceptionPositions = [];
                    for ($i = 0; $i < $remaining; $i++) {
                        if ((ord($bitmap[intdiv($i, 8)]) & (1 << ($i % 8))) !== 0) $exceptionPositions[] = $i;
                    }
                    if (($remaining % 8) !== 0 && $bitmapLength > 0 &&
                        (ord($bitmap[$bitmapLength - 1]) & (0xff << ($remaining % 8))) !== 0) {
                        throw new RuntimeException('Invalid bitmap padding in ECMWF PFor data.');
                    }
                    $exceptions = self::unpackBits($input, $position, count($exceptionPositions), $exceptionWidth);
                    $base = self::unpackPfor16Values($input, $position, $remaining, $width, $remaining === 128);
                    foreach ($exceptionPositions as $index => $target) {
                        $base[$target] += $exceptions[$index] << $width;
                    }
                    $deltas = $base;
                } else {
                    $deltas = self::unpackPfor16Values($input, $position, $remaining, $width, $remaining === 128);
                }
            } else {
                // Variable-byte exception blocks use Open-Meteo's 64-bit SIMD
                // VByte thresholds (VB_MAX=254, VB_B2=6, VB_B3=3).
                self::need($input, $position, 1);
                $exceptionCount = ord($input[$position++]);
                if ($width >= 16 || $exceptionCount < 1 || $exceptionCount > $remaining) {
                    throw new RuntimeException('Invalid variable-byte exception header in ECMWF PFor data.');
                }
                $base = self::unpackPfor16Values($input, $position, $remaining, $width, false);
                $exceptions = self::readOpenMeteoVariableByte16($input, $position, $exceptionCount);
                self::need($input, $position, $exceptionCount);
                $positions = array_values(unpack('C*', substr($input, $position, $exceptionCount)) ?: []);
                $position += $exceptionCount;
                foreach ($positions as $index => $target) {
                    if ($target >= $remaining) throw new RuntimeException('ECMWF PFor exception position is outside its block.');
                    $base[$target] += $exceptions[$index] << $width;
                }
                $deltas = $base;
            }

            foreach ($deltas as $encodedDelta) {
                if ($encodedDelta < 0 || $encodedDelta > 65535) {
                    throw new RuntimeException('ECMWF PFor delta exceeds its 16-bit representation.');
                }
                $delta = ($encodedDelta >> 1) ^ (-($encodedDelta & 1));
                $unsigned = ($previous + $delta) & 0xffff;
                $previous = ($unsigned & 0x8000) !== 0 ? $unsigned - 65536 : $unsigned;
                $values[] = $previous;
            }
        }
        if ($position !== strlen($input)) {
            throw new RuntimeException('The ECMWF PFor chunk contains trailing or unconsumed bytes.');
        }
        return $values;
    }

    private static function unpackPfor16Values(string $input, int &$position, int $count, int $width, bool $vertical): array
    {
        if ($width < 0 || $width > 16) throw new RuntimeException('Invalid 16-bit ECMWF PFor bit width.');
        if (!$vertical) return self::unpackBits($input, $position, $count, $width);
        if ($width === 0) return array_fill(0, $count, 0);
        $byteCount = $width * 16;
        self::need($input, $position, $byteCount);
        $start = $position;
        $values = [];
        $mask = (1 << $width) - 1;
        for ($i = 0; $i < $count; $i++) {
            // Each 16-bit lane carries a 16-value bitstream. Eight lanes are
            // interleaved per 16-byte vector; values can cross word boundaries.
            $lane = $i % 8;
            $slot = intdiv($i, 8);
            $bitOffset = $slot * $width;
            $wordIndex = intdiv($bitOffset, 16);
            $shift = $bitOffset % 16;
            $wordOffset = $start + ($wordIndex * 16) + ($lane * 2);
            $word = ord($input[$wordOffset]) | (ord($input[$wordOffset + 1]) << 8);
            $value = $word >> $shift;
            if ($shift + $width > 16) {
                $nextOffset = $start + (($wordIndex + 1) * 16) + ($lane * 2);
                $nextWord = ord($input[$nextOffset]) | (ord($input[$nextOffset + 1]) << 8);
                $value |= $nextWord << (16 - $shift);
            }
            $values[] = $value & $mask;
        }
        $position += $byteCount;
        return $values;
    }

    private static function readOpenMeteoVariableByte16(string $input, int &$position, int $count): array
    {
        self::need($input, $position, 1);
        if (ord($input[$position]) === 255) {
            $position++;
            $values = [];
            for ($i = 0; $i < $count; $i++) $values[] = self::readLittleUnsigned($input, $position, 2);
            return $values;
        }
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            self::need($input, $position, 1);
            $first = ord($input[$position++]);
            if ($first < 177) {
                $value = $first;
            } elseif ($first < 241) {
                self::need($input, $position, 1);
                $value = (($first << 8) + ord($input[$position++])) + (177 - (177 << 8));
            } elseif ($first < 249) {
                $low = self::readLittleUnsigned($input, $position, 2);
                $value = $low + (($first - 241) << 16) + 16561;
            } else {
                $bytes = 3 + $first - 249;
                if ($bytes > 4) throw new RuntimeException('ECMWF variable-byte exception exceeds 32 bits.');
                $value = self::readLittleUnsigned($input, $position, $bytes);
            }
            if ($value < 0 || $value > 65535) throw new RuntimeException('ECMWF variable-byte exception exceeds 16 bits.');
            $values[] = $value;
        }
        return $values;
    }

    public static function decodeV3LookupTable(string $compressed, int $chunkCount): array
    {
        if ($chunkCount < 1) throw new InvalidArgumentException('The array must contain at least one data chunk.');
        $entryCount = $chunkCount + 1;
        // The reference implementation uses floor(numberOfChunks / 64) + 1 blocks.
        $blockCount = intdiv($chunkCount, self::LUT_CHUNK_COUNT) + 1;
        $size = strlen($compressed);
        if ($size === 0 || $size % $blockCount !== 0) {
            throw new RuntimeException('The .om lookup table has an invalid compressed size.');
        }
        $blockSize = intdiv($size, $blockCount);
        $result = [];
        for ($block = 0; $block < $blockCount; $block++) {
            $first = $block * self::LUT_CHUNK_COUNT;
            $count = min(self::LUT_CHUNK_COUNT, $entryCount - $first);
            $stream = substr($compressed, $block * $blockSize, $blockSize);
            foreach (self::decodePforDelta64($stream, $count) as $value) {
                if ($result !== [] && $value < $result[count($result) - 1]) {
                    throw new RuntimeException('The .om lookup table offsets are not monotonic.');
                }
                $result[] = $value;
            }
        }
        if (count($result) !== $entryCount) {
            throw new RuntimeException('The .om lookup table did not decode to the expected number of offsets.');
        }
        return $result;
    }

    private function findNode(string $name): ?array
    {
        $root = $this->loadRootNode();
        if ($root['name'] === $name) return $root;
        return $this->findNodeInChildren($root, $name, 0);
    }

    private function findNodeInChildren(array $node, string $name, int $depth): ?array
    {
        if ($depth > 12) throw new RuntimeException('The .om metadata tree is nested too deeply.');
        foreach ($node['children'] as $child) {
            $parsed = $this->parseNode($child['offset'], $child['size']);
            if ($parsed['name'] === $name) return $parsed;
            $found = $this->findNodeInChildren($parsed, $name, $depth + 1);
            if ($found !== null) return $found;
        }
        return null;
    }

    private function loadRootNode(): array
    {
        if ($this->rootNode !== null) return $this->rootNode;
        $header = $this->readAt(0, 3);
        if (substr($header, 0, 2) !== 'OM' || ord($header[2]) !== 3) {
            throw new RuntimeException('The ECMWF .om file is not in supported version 3 format.');
        }
        $trailer = $this->readAt($this->fileSize - 24, 24);
        if (substr($trailer, 0, 2) !== 'OM' || ord($trailer[2]) !== 3) {
            throw new RuntimeException('The ECMWF .om file has an invalid version 3 trailer.');
        }
        $rootOffset = self::unpackUInt64(substr($trailer, 8, 8));
        $rootSize = self::unpackUInt64(substr($trailer, 16, 8));
        if ($rootSize < 8 || $rootSize > 2097152 || $rootOffset < 3 ||
            $rootOffset > $this->fileSize - 24 - $rootSize) {
            throw new RuntimeException('The ECMWF .om root metadata range is invalid.');
        }
        $this->rootNode = $this->parseNode($rootOffset, $rootSize);
        return $this->rootNode;
    }

    private function parseNode(int $offset, int $size): array
    {
        if ($size < 8 || $size > 2097152 || $offset < 3 || $offset > $this->fileSize - 24 ||
            $size > $this->fileSize - 24 - $offset) {
            throw new RuntimeException('The ECMWF .om metadata node is outside the file bounds.');
        }
        $bytes = $this->readAt($offset, $size);
        $dataType = ord($bytes[0]);
        $nameSize = unpack('vvalue', substr($bytes, 2, 2))['value'];
        $childrenCount = unpack('Vvalue', substr($bytes, 4, 4))['value'];
        $isArray = $dataType >= 12 && $dataType <= 21;
        if (!$isArray && $dataType > 11) {
            throw new RuntimeException('The .om metadata contains an unsupported variable type.');
        }
        $headerSize = $isArray ? 40 : 8;
        if ($childrenCount > 4096 || $headerSize + $childrenCount * 16 > $size) {
            throw new RuntimeException('The .om metadata has an invalid child table.');
        }
        $children = [];
        $sizesOffset = $headerSize;
        $offsetsOffset = $headerSize + $childrenCount * 8;
        for ($i = 0; $i < $childrenCount; $i++) {
            $childSize = self::unpackUInt64(substr($bytes, $sizesOffset + $i * 8, 8));
            $childOffset = self::unpackUInt64(substr($bytes, $offsetsOffset + $i * 8, 8));
            if ($childSize < 8 || $childOffset < 3 || $childOffset > $this->fileSize - 24 ||
                $childSize > $this->fileSize - 24 - $childOffset) {
                throw new RuntimeException('The .om metadata child points outside the file.');
            }
            $children[] = ['offset' => $childOffset, 'size' => $childSize];
        }

        $node = [
            'data_type' => $dataType,
            'is_array' => $isArray,
            'name' => '',
            'children' => $children,
            'dimensions' => [],
            'chunks' => [],
            'lut_offset' => 0,
            'lut_size' => 0,
            'compression_type' => 0,
            'scale_factor' => 1.0,
            'add_offset' => 0.0,
            'string_value' => null,
        ];
        if ($isArray) {
            $dimensionCountOffset = 24;
            if ($size < $headerSize + $childrenCount * 16 + 32) {
                throw new RuntimeException('The .om array metadata is truncated.');
            }
            $lutSize = self::unpackUInt64(substr($bytes, 8, 8));
            $lutOffset = self::unpackUInt64(substr($bytes, 16, 8));
            $dimensionCount = self::unpackUInt64(substr($bytes, $dimensionCountOffset, 8));
            $node['compression_type'] = ord($bytes[1]);
            $node['scale_factor'] = unpack('gvalue', substr($bytes, 32, 4))['value'];
            $node['add_offset'] = unpack('gvalue', substr($bytes, 36, 4))['value'];
            if (!is_finite($node['scale_factor']) || $node['scale_factor'] == 0.0 || !is_finite($node['add_offset'])) {
                throw new RuntimeException('The .om array has invalid value scaling metadata.');
            }
            if ($dimensionCount !== 2) {
                throw new RuntimeException('Only two-dimensional ECMWF .om arrays are supported.');
            }
            $dimensionOffset = $headerSize + $childrenCount * 16;
            for ($i = 0; $i < 2; $i++) {
                $node['dimensions'][] = self::unpackUInt64(substr($bytes, $dimensionOffset + $i * 8, 8));
                $node['chunks'][] = self::unpackUInt64(substr($bytes, $dimensionOffset + 16 + $i * 8, 8));
            }
            $node['lut_offset'] = $lutOffset;
            $node['lut_size'] = $lutSize;
            $nameOffset = $dimensionOffset + 32;
        } else {
            $valueOffset = $headerSize + $childrenCount * 16;
            if ($dataType === 11 && $valueOffset + 8 > $size) {
                throw new RuntimeException('The .om string metadata is truncated.');
            }
            $valueSize = match ($dataType) {
                0 => 0,
                1, 2 => 1,
                3, 4 => 2,
                5, 6, 9 => 4,
                7, 8, 10 => 8,
                11 => self::unpackUInt64(substr($bytes, $valueOffset, 8)) + 8,
            };
            if ($valueOffset + $valueSize + $nameSize > $size) {
                throw new RuntimeException('The .om scalar metadata value or name is truncated.');
            }
            if ($dataType === 11) {
                $node['string_value'] = substr($bytes, $valueOffset + 8, $valueSize - 8);
            }
            $nameOffset = $valueOffset + $valueSize;
        }
        if ($nameOffset + $nameSize > $size) {
            throw new RuntimeException('The .om metadata variable name is truncated.');
        }
        $node['name'] = substr($bytes, $nameOffset, $nameSize);
        return $node;
    }

    private static function gaussianPointIndex(int $lines, array $lineCounts, array $prefix, float $latitude, float $longitude): int
    {
        $dy = 180.0 / (2.0 * $lines + 0.5);
        $yFloat = $lines - 1.0 - (($latitude - $dy / 2.0) / $dy);
        $y = max(0, min(2 * $lines - 2, (int)$yFloat));
        $upper = $y + 1;
        $nx = $lineCounts[$y];
        $nxUpper = $lineCounts[$upper];
        $dx = 360.0 / $nx;
        $dxUpper = 360.0 / $nxUpper;
        $x = (int)round($longitude / $dx, 0, PHP_ROUND_HALF_EVEN);
        $xUpper = (int)round($longitude / $dxUpper, 0, PHP_ROUND_HALF_EVEN);
        $distance = ((($lines - $y - 1) * $dy + $dy / 2.0) - $latitude) ** 2
            + (($x * $dx) - $longitude) ** 2;
        $distanceUpper = ((($lines - $upper - 1) * $dy + $dy / 2.0) - $latitude) ** 2
            + (($xUpper * $dxUpper) - $longitude) ** 2;
        if ($distance < $distanceUpper) {
            return $prefix[$y] + (($x % $nx) + $nx) % $nx;
        }
        return $prefix[$upper] + (($xUpper % $nxUpper) + $nxUpper) % $nxUpper;
    }

    private static function readSeed64(string $input, int &$position): int
    {
        self::need($input, 0, 1);
        $header = ord($input[0]);
        $bytes = 1;
        for ($mask = 0x80; $mask !== 0 && ($header & $mask) !== 0; $mask >>= 1) $bytes++;
        self::need($input, 0, $bytes);
        if ($bytes === 1) {
            $value = $header;
        } elseif ($bytes === 2 || $bytes === 4 || $bytes === 8) {
            $value = $header & (0xff >> $bytes);
            for ($i = 1; $i < $bytes; $i++) {
                if ($value > intdiv(PHP_INT_MAX - ord($input[$i]), 256)) {
                    throw new RuntimeException('The .om LUT seed exceeds the supported 64-bit offset range.');
                }
                $value = ($value << 8) | ord($input[$i]);
            }
        } elseif ($bytes === 3 || $bytes === 5) {
            $value = 0;
            for ($i = 1; $i < $bytes; $i++) {
                $part = ord($input[$i]) << (8 * ($i - 1));
                if ($part > PHP_INT_MAX - $value) {
                    throw new RuntimeException('The .om LUT seed exceeds the supported 64-bit offset range.');
                }
                $value |= $part;
            }
            $high = ($header & (0xff >> $bytes)) << (8 * ($bytes - 1));
            if ($high > PHP_INT_MAX - $value) {
                throw new RuntimeException('The .om LUT seed exceeds the supported 64-bit offset range.');
            }
            $value |= $high;
        } elseif ($bytes === 6) {
            $value = ((($header & 3) << 8) | ord($input[1])) << 32;
            for ($i = 0; $i < 4; $i++) $value |= ord($input[2 + $i]) << (8 * $i);
        } elseif ($bytes === 7) {
            $value = (($header & 1) << 48) | (ord($input[1]) << 32) | (ord($input[2]) << 40);
            for ($i = 0; $i < 4; $i++) $value |= ord($input[3 + $i]) << (8 * $i);
        } elseif ($bytes === 9) {
            $value = self::readLittleUnsigned($input, 1, 8);
        } else {
            throw new RuntimeException('Unsupported .om LUT seed encoding.');
        }
        if ($value < 0) throw new RuntimeException('The .om LUT seed exceeds the supported file-offset range.');
        $position = $bytes;
        return $value;
    }

    private static function readVariableByte64Values(string $input, int &$position, int $count): array
    {
        self::need($input, $position, 1);
        if (ord($input[$position]) === 0xff) {
            $position++;
            $values = [];
            for ($i = 0; $i < $count; $i++) $values[] = self::readLittleUnsigned($input, $position, 8);
            return $values;
        }
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            self::need($input, $position, 1);
            $first = ord($input[$position++]);
            if ($first < 121) {
                $value = $first;
            } elseif ($first < 185) {
                self::need($input, $position, 1);
                $value = (($first - 121) << 8) + ord($input[$position++]) + 121;
            } elseif ($first < 249) {
                $low = self::readLittleUnsigned($input, $position, 2);
                $value = $low + (($first - 185) << 16) + 16505;
            } else {
                $value = self::readLittleUnsigned($input, $position, 3 + $first - 249);
            }
            $values[] = $value;
        }
        return $values;
    }

    private static function unpackBits(string $input, int &$position, int $count, int $width): array
    {
        if ($width < 0 || $width > 62) {
            throw new RuntimeException('Unsupported .om PFor bit width for signed PHP offsets.');
        }
        $byteCount = intdiv($count * $width + 7, 8);
        self::need($input, $position, $byteCount);
        $startBit = $position * 8;
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $bit = $startBit + $i * $width;
            $value = 0;
            for ($b = 0; $b < $width; $b++) {
                $absoluteBit = $bit + $b;
                if ((ord($input[intdiv($absoluteBit, 8)]) & (1 << ($absoluteBit % 8))) !== 0) {
                    $value |= (1 << $b);
                }
            }
            $values[] = $value;
        }
        $position += $byteCount;
        return $values;
    }

    private static function readLittleUnsigned(string $input, int &$position, int $bytes): int
    {
        if ($bytes < 0 || $bytes > 8) throw new RuntimeException('Invalid little-endian integer width.');
        self::need($input, $position, $bytes);
        if ($bytes === 8) {
            $value = unpack('Pvalue', substr($input, $position, 8))['value'];
            $position += 8;
            if (!is_int($value) || $value < 0) {
                throw new RuntimeException('The .om value exceeds the supported PHP integer range.');
            }
            return $value;
        }
        $value = 0;
        for ($i = 0; $i < $bytes; $i++) $value |= ord($input[$position + $i]) << (8 * $i);
        $position += $bytes;
        return $value;
    }

    private static function unpackUInt64(string $bytes): int
    {
        if (strlen($bytes) !== 8) throw new RuntimeException('Truncated unsigned 64-bit .om value.');
        $value = unpack('Pvalue', $bytes)['value'];
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('The .om file contains a 64-bit value outside the supported PHP integer range.');
        }
        return $value;
    }

    private static function need(string $input, int $position, int $length): void
    {
        if ($position < 0 || $length < 0 || $position > strlen($input) ||
            $length > strlen($input) - $position) {
            throw new RuntimeException('Truncated .om lookup-table data.');
        }
    }
}
