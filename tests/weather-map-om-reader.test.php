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

foreach ([6599680 => [2940270, 5771535], 421120 => [187842, 367698]] as $count => $range) {
    if (WeatherMapOmReader::regionalGaussianIndexRange($count) !== $range) {
        throw new RuntimeException("Gaussian regional index range mismatch for $count points.");
    }
    echo "PASS Gaussian regional index mapping for $count points\n";
}
echo "All Open-Meteo LUT reader tests passed.\n";
