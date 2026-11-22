<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/WeatherMapRasterTileRenderer.php';

function assertWeatherRaster(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$columns = 5;
$rows = 5;
$grid = [];
for ($y = 0; $y < $rows; $y++) {
    for ($x = 0; $x < $columns; $x++) {
        $grid[] = (float)($x + 2 * $y);
    }
}

$nearest = WeatherMapRasterTileRenderer::sample($grid, $columns, $rows, 1.2, 1.2, 'nearest');
$linear = WeatherMapRasterTileRenderer::sample($grid, $columns, $rows, 1.2, 1.2, 'linear');
$cubic = WeatherMapRasterTileRenderer::sample($grid, $columns, $rows, 1.2, 1.2, 'cubic');
$monotone = WeatherMapRasterTileRenderer::sample($grid, $columns, $rows, 1.2, 1.2, 'monotone');

assertWeatherRaster(abs($nearest - 3.0) < 0.000001, 'Nearest-neighbour interpolation returned an unexpected value.');
assertWeatherRaster(abs($linear - 3.6) < 0.000001, 'Bilinear interpolation did not preserve a linear plane.');
assertWeatherRaster(abs($cubic - 3.6) < 0.000001, 'Cubic interpolation did not preserve a linear plane.');
assertWeatherRaster(abs($monotone - 3.6) < 0.000001, 'Monotone interpolation did not preserve a linear plane.');

$edge = WeatherMapRasterTileRenderer::sample($grid, $columns, $rows, 0.2, 0.2, 'cubic');
assertWeatherRaster(is_finite($edge), 'Cubic interpolation failed at a grid boundary.');

$plateau = [];
for ($y = 0; $y < 5; $y++) {
    foreach ([0.0, 1.0, 1.0, 1.0, 2.0] as $value) $plateau[] = $value;
}
$monotonePlateau = WeatherMapRasterTileRenderer::sample($plateau, 5, 5, 2.5, 2.2, 'monotone');
assertWeatherRaster($monotonePlateau >= 1.0 && $monotonePlateau <= 1.0, 'Monotone interpolation overshot a flat section.');

$missingCorner = $grid;
$missingCorner[0] = NAN;
$outsideValidTriangle = WeatherMapRasterTileRenderer::sample($missingCorner, 5, 5, 0.25, 0.25, 'linear');
$insideValidTriangle = WeatherMapRasterTileRenderer::sample($missingCorner, 5, 5, 0.75, 0.75, 'linear');
assertWeatherRaster(!is_finite($outsideValidTriangle), 'Linear interpolation should reject a point outside the valid triangle.');
assertWeatherRaster(is_finite($insideValidTriangle), 'Linear interpolation should preserve a point inside the valid triangle.');

$invalidModeRejected = false;
try {
    WeatherMapRasterTileRenderer::validate($grid, $columns, $rows, 'unknown');
} catch (InvalidArgumentException) {
    $invalidModeRejected = true;
}
assertWeatherRaster($invalidModeRejected, 'Unsupported interpolation mode was not rejected.');

echo "Weather-map raster interpolation tests passed.\n";
