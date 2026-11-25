<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/WeatherMapOmReader.php';

function checkFixture(string $name, string $hex, array $expected): void
{
    $input = hex2bin(preg_replace('/\s+/', '', $hex));
    $actual = WeatherMapOmReader::decodePforDelta64($input, count($expected));
    if ($actual !== $expected) {
        $limit = min(count($actual), count($expected));
        for ($i = 0; $i < $limit; $i++) {
            if ($actual[$i] !== $expected[$i]) {
                throw new RuntimeException("$name mismatch at $i: expected {$expected[$i]}, got {$actual[$i]}");
            }
        }
        throw new RuntimeException("$name length mismatch.");
    }
    echo "PASS $name (" . count($actual) . " values)\n";
}

$tail = [];
for ($i = 0; $i < 9; $i++) $tail[] = 10 + intdiv($i * ($i + 1), 2);
checkFixture('64-bit delta tail', '0a0421436587', $tail);
checkFixture('64-bit bitmap exception', '0a8101020100', [10, 10, 12, 12, 12]);
checkFixture('64-bit standard variable-byte exception', '0a420111794f01', [10, 11, 811, 812]);

$blocks = [];
$value = 0;
for ($i = 0; $i < 137; $i++) {
    $value += $i % 7;
    $blocks[] = $value;
}
checkFixture(
    '64-bit delta 128-value block plus tail',
    '0003d158231a6b44638d68ac118d35a2b14634d688c61ad158231a6b44638d68ac118d35a2b14634d688c61ad158231a6b4403638d68',
    $blocks
);

$exceptions = [];
$value = 0;
for ($i = 0; $i < 129; $i++) {
    $value += ($i === 64 ? (1 << 62) : 1);
    $exceptions[] = $value;
}
checkFixture(
    '64-bit variable-byte raw-array exception',
    '014101ffffffffffffff7fffffffffffffffffff00000000000000203f',
    $exceptions
);
checkFixture('64-bit zero blocks', '000000', array_fill(0, 137, 0));

function checkPfor16Fixture(string $name, string $hex, array $expected): void
{
    $actual = WeatherMapOmReader::decodePforDelta16(hex2bin($hex), count($expected));
    if ($actual !== $expected) {
        for ($i = 0; $i < min(count($actual), count($expected)); $i++) {
            if ($actual[$i] !== $expected[$i]) {
                throw new RuntimeException("$name mismatch at $i: expected {$expected[$i]}, got {$actual[$i]}");
            }
        }
        throw new RuntimeException("$name length mismatch.");
    }
    echo "PASS $name (" . count($actual) . " values)\\n";
}

$zigzagTail = [];
for ($i = 0; $i < 9; $i++) $zigzagTail[] = 10 + ($i % 2 === 1 ? intdiv($i + 1, 2) : -intdiv($i, 2));
checkPfor16Fixture('ECMWF 16-bit zigzag tail', '0a043276bafe', $zigzagTail);
checkPfor16Fixture('ECMWF 16-bit zero blocks', '000000', array_fill(0, 137, 0));
checkPfor16Fixture('ECMWF 16-bit signed seed', 'c0ffff', [-1]);


$zigzagBlocks = [];
for ($i = 0; $i < 137; $i++) $zigzagBlocks[] = $i % 8;
checkPfor16Fixture(
    'ECMWF 16-bit vertical block plus tail',
    '00820280808080808080808080808080808080ffffffffaaaaaaaaaaaaaaaaaaaaaaaaaaaa5555aaaaaaaaaaaaaaaaaaaaaaaaaaaa555504222222d2',
    $zigzagBlocks
);

$zigzagExceptions = [];
$value = 1;
$zigzagExceptions[] = $value;
for ($i = 1; $i < 129; $i++) {
    $value = ($value + ($i === 64 ? 16384 : 1)) & 0xffff;
    $zigzagExceptions[] = $value >= 0x8000 ? $value - 0x10000 : $value;
}
checkPfor16Fixture(
    'ECMWF 16-bit variable-byte exception',
    '014201aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaad04f3f',
    $zigzagExceptions
);


// Open-Meteo's official C decoder dispatches every full 128-value
// 16-bit block to p4*z*dec128v16, regardless of the header's bit 7.
// This fixture uses different lane values so linear unpacking cannot pass.
$ordinary128Packed = "\\0\\x01"
    . "\\0\\0\\xff\\xff"
    . "\\0\\0\\xff\\xff"
    . "\\0\\0\\xff\\xff"
    . "\\0\\0\\xff\\xff";
$ordinary128Expected = [0];
$value = 0;
for ($i = 0; $i < 128; $i++) {
    if (($i % 8) % 2 === 1) $value--;
    $ordinary128Expected[] = $value;
}
checkPfor16Fixture('ECMWF ordinary 128-value vector-interleaved block', bin2hex($ordinary128Packed), $ordinary128Expected);

function encodeSmallSeed(int $value): string
{
    if ($value < 128) return chr($value);
    if ($value < 16384) return chr(0x80 | ($value >> 8)) . chr($value & 0xff);
    throw new RuntimeException('Test seed is outside the small-seed range.');
}

function makeLutFixture(int $chunks): array
{
    $entries = $chunks + 1;
    $groups = array_chunk(range(0, $entries - 1), 64);
    $streams = [];
    $expected = [];
    $last = 100;
    foreach ($groups as $group) {
        $groupCount = count($group);
        $expected[] = $last;
        for ($i = 1; $i < $groupCount; $i++) $expected[] = ++$last;
        $stream = encodeSmallSeed($expected[count($expected) - $groupCount]);
        if ($groupCount > 1) $stream .= "\xc1\x01";
        $streams[] = $stream;
    }
    $slotSize = max(array_map('strlen', $streams));
    $compressed = '';
    foreach ($streams as $stream) $compressed .= str_pad($stream, $slotSize, "\0");
    return [$compressed, $expected];
}

foreach ([1, 63, 64, 65, 129] as $chunks) {
    [$bytes, $expected] = makeLutFixture($chunks);
    $actual = WeatherMapOmReader::decodeV3LookupTable($bytes, $chunks);
    if ($actual !== $expected) throw new RuntimeException("V3 LUT boundary $chunks failed.");
    echo "PASS v3 LUT boundary $chunks chunks (" . count($actual) . " offsets)\n";
}

function makeV3FixtureFile(): string
{
    $name = 'cloud_cover';
    $crsName = 'crs_wkt';
    $crs = 'Reduced Gaussian Grid O1280';
    $arraySize = 40 + 32 + strlen($name);
    $stringSize = 8 + 8 + strlen($crs) + strlen($crsName);
    $arrayOffset = 3;
    $stringOffset = $arrayOffset + $arraySize;
    $lut = chr(100) . "\xc4\x0a";
    $lutOffset = $stringOffset + $stringSize;
    $rootOffset = $lutOffset + strlen($lut);

    $array = chr(20) . chr(0) . pack('v', strlen($name)) . pack('V', 0)
        . pack('P', strlen($lut)) . pack('P', $lutOffset) . pack('P', 2)
        . pack('g', 1.0) . pack('g', 0.0)
        . pack('P', 1) . pack('P', 8) . pack('P', 1) . pack('P', 2) . $name;
    $string = chr(11) . chr(0) . pack('v', strlen($crsName)) . pack('V', 0)
        . pack('P', strlen($crs)) . $crs . $crsName;
    $rootName = 'root';
    $root = chr(0) . chr(0) . pack('v', strlen($rootName)) . pack('V', 2)
        . pack('P', strlen($array)) . pack('P', strlen($string))
        . pack('P', $arrayOffset) . pack('P', $stringOffset) . $rootName;
    $trailer = 'OM' . chr(3) . chr(0) . pack('V', 0) . pack('P', $rootOffset) . pack('P', strlen($root));
    return 'OM' . chr(3) . $array . $string . $lut . $root . $trailer;
}

$file = makeV3FixtureFile();
$reader = new WeatherMapOmReader(
    static fn(int $offset, int $length): string => substr($file, $offset, $length),
    strlen($file)
);
if ($reader->getString('crs_wkt') !== 'Reduced Gaussian Grid O1280') {
    throw new RuntimeException('v3 metadata string lookup failed.');
}
$arrayInfo = $reader->getArrayInfo('cloud_cover');
if ($arrayInfo['dimensions'] !== [1, 8] || $arrayInfo['chunks'] !== [1, 2] ||
    $arrayInfo['data_type'] !== 20 || $arrayInfo['compression_type'] !== 0 ||
    $arrayInfo['scale_factor'] !== 1.0 || $arrayInfo['add_offset'] !== 0.0) {
    throw new RuntimeException('v3 array metadata layout or encoding metadata failed.');
}
$selected = WeatherMapOmReader::selectChunkRanges($arrayInfo, 2, 6);
if ($selected !== [[110, 120], [120, 130]]) {
    throw new RuntimeException('v3 regional chunk-range selection failed.');
}
echo "PASS v3 metadata tree, array layout and regional chunk selection\n";

$regularGridLut = [];
for ($index = 0; $index <= 48; $index++) $regularGridLut[] = 100 + ($index * 10);
$regularGridInfo = [
    'dimensions' => [721, 1440],
    'chunks' => [100, 256],
    'lut' => $regularGridLut,
];
$regularGridRanges = WeatherMapOmReader::selectRegularGridChunkRanges($regularGridInfo);
$expectedRegularGridRanges = [
    [180, 190], [190, 200],
    [240, 250], [250, 260],
    [300, 310], [310, 320],
];
if ($regularGridRanges !== $expectedRegularGridRanges) {
    throw new RuntimeException('ECMWF IFS 0.25° row-major regular-grid regional chunk selection failed.');
}
echo "PASS ECMWF IFS 0.25° regular-grid regional chunk selection\n";
$nearbyRegular = WeatherMapOmReader::regularGridNeighborhood(-32.986992, 27.932365, 100.0);
if ($nearbyRegular === [] || count($nearbyRegular) < 10) {
    throw new RuntimeException('ECMWF 0.25-degree regular-grid neighbourhood sampling returned too few cells.');
}
foreach ($nearbyRegular as $cell) {
    if ($cell['index'] < 0 || $cell['index'] >= 721 * 1440 ||
        $cell['distance_km'] > 100.0 || $cell['index'] !== ((int)round(($cell['latitude'] + 90.0) / 0.25) * 1440 + (int)round(($cell['longitude'] + 180.0) / 0.25))) {
        throw new RuntimeException('ECMWF regular-grid neighbourhood contains an invalid cell or index.');
    }
}
echo "PASS ECMWF IFS 0.25-degree regular-grid neighbourhood sampling (" . count($nearbyRegular) . " cells)\\n";

$antimeridianCells = WeatherMapOmReader::regularGridNeighborhood(0.0, 179.9, 100.0);
$hasWrappedLongitude = false;
foreach ($antimeridianCells as $cell) {
    if ($cell['longitude'] < -170.0) $hasWrappedLongitude = true;
}
if (!$hasWrappedLongitude) {
    throw new RuntimeException('ECMWF regular-grid neighbourhood did not wrap longitude across the antimeridian.');
}
echo "PASS ECMWF regular-grid antimeridian neighbourhood wrapping\\n";
$regularChunkBytes = str_repeat("\0", 3) . "\0\0" . str_repeat("\0", 22);
$regularChunkReader = new WeatherMapOmReader(
    static fn(int $offset, int $length): string => substr($regularChunkBytes, $offset, $length),
    strlen($regularChunkBytes)
);
$regularChunkInfo = [
    'data_type' => 20,
    'compression_type' => 0,
    'scale_factor' => 1.0,
    'add_offset' => 0.0,
    'dimensions' => [2, 2],
    'chunks' => [2, 2],
    'lut' => [3, 5],
];
$decodedRegularCells = $regularChunkReader->decodeFloatArrayGridIndices($regularChunkInfo, [0, 3]);
if ($decodedRegularCells !== [0 => 0.0, 3 => 0.0]) {
    throw new RuntimeException('ECMWF regular-grid two-dimensional chunk decoding failed.');
}
echo "PASS ECMWF regular-grid two-dimensional chunk decoding\\n";

$nanChunkBytes = str_repeat("\0", 27);
$nanChunkReader = new WeatherMapOmReader(
    static fn(int $offset, int $length): string => substr($nanChunkBytes, $offset, $length),
    strlen($nanChunkBytes)
);
$nanChunkInfo = [
    'data_type' => 20,
    'compression_type' => 0,
    'scale_factor' => 1.0,
    'add_offset' => 0.0,
    'dimensions' => [1, 3],
    'chunks' => [1, 1],
    'lut' => [3, 4, 4, 5],
];
$decodedNanChunkCells = $nanChunkReader->decodeFloatArrayGridIndices($nanChunkInfo, [0, 1, 2]);
if (($decodedNanChunkCells[0] ?? null) !== 0.0 ||
    !is_nan($decodedNanChunkCells[1] ?? 0.0) ||
    ($decodedNanChunkCells[2] ?? null) !== 0.0) {
    throw new RuntimeException('OM v3 zero-byte all-NaN chunk decoding failed.');
}
echo "PASS OM v3 zero-byte all-NaN chunk decoding\\n";




// An ordinary 128-value PFor block is bit-packed linearly. The vector
// layout is used only when the PFor header explicitly sets bit 7.
$ordinary128Packed = "\0\x01" . str_repeat("\xaa", 16);
$ordinary128Decoded = WeatherMapOmReader::decodePforDelta16($ordinary128Packed, 129);
$ordinary128Expected = [0];
for ($i = 0; $i < 128; $i++) $ordinary128Expected[] = -intdiv($i + 1, 2);
if ($ordinary128Decoded !== $ordinary128Expected) {
    throw new RuntimeException('ECMWF ordinary 128-value PFor blocks must use linear bit unpacking.');
}
echo "PASS ECMWF ordinary 128-value PFor block layout selection\\n";

$delta2dFixture = WeatherMapOmReader::decodeDelta2D16([10, 20, 1, 2, 3, 4], 3, 2);
if ($delta2dFixture !== [10, 20, 11, 22, 14, 26]) {
    throw new RuntimeException('ECMWF PFor 2D vertical delta reconstruction failed.');
}
$wrappedDelta2dFixture = WeatherMapOmReader::decodeDelta2D16([32760, -32760, 10, -10], 2, 2);
if ($wrappedDelta2dFixture !== [32760, -32760, -32766, 32766]) {
    throw new RuntimeException('ECMWF PFor 2D signed 16-bit delta wrapping failed.');
}
echo "PASS ECMWF PFor 2D vertical delta reconstruction and signed wrapping\\n";

$scaledPrecipitation = WeatherMapOmReader::decodeScaledPfor16Value(28, 10.0, 0.0);
if (abs($scaledPrecipitation - 2.8) > 0.000001) {
    throw new RuntimeException('ECMWF PFor scale factor must divide the stored integer value.');
}
$offsetPrecipitation = WeatherMapOmReader::decodeScaledPfor16Value(35, 10.0, 0.5);
if (abs($offsetPrecipitation - 3.0) > 0.000001) {
    throw new RuntimeException('ECMWF PFor add_offset decoding failed.');
}
if (!is_nan(WeatherMapOmReader::decodeScaledPfor16Value(32767, 10.0, 0.0))) {
    throw new RuntimeException('ECMWF PFor missing-value sentinel was not decoded as NaN.');
}
echo "PASS ECMWF PFor scale, offset and missing-value decoding\\n";


foreach ([6599680 => [2940270, 5771535], 421120 => [187842, 367698]] as $count => $range) {
    if (WeatherMapOmReader::regionalGaussianIndexRange($count) !== $range) {
        throw new RuntimeException("Gaussian regional index range mismatch for $count points.");
    }
    echo "PASS Gaussian regional index mapping for $count points\n";
}
$nearbyO1280 = WeatherMapOmReader::gaussianNeighborhood(6599680, -32.986992, 27.932365, 50.0);
if ($nearbyO1280 === [] || count($nearbyO1280) < 10) {
    throw new RuntimeException('O1280 Gaussian neighbourhood sampling returned too few cells.');
}
foreach ($nearbyO1280 as $cell) {
    if ($cell['index'] < 0 || $cell['index'] >= 6599680 || $cell['distance_km'] > 50.0) {
        throw new RuntimeException('O1280 Gaussian neighbourhood contains an invalid grid cell.');
    }
}
echo "PASS O1280 local Gaussian-grid sampling (" . count($nearbyO1280) . " cells)\\n";

$nearbyO320 = WeatherMapOmReader::gaussianNeighborhood(421120, -32.986992, 27.932365, 50.0);
if ($nearbyO320 === [] || count($nearbyO320) < 2) {
    throw new RuntimeException('O320 Gaussian neighbourhood sampling returned too few cells.');
}
echo "PASS O320 local Gaussian-grid sampling (" . count($nearbyO320) . " cells)\\n";

echo "All Open-Meteo LUT reader tests passed.\n";
