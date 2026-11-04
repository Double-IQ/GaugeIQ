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
