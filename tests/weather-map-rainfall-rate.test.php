<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/WeatherMapRainfallNowcastService.php';

function assertRainfallRate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$threeHourRate = WeatherMapRainfallNowcastService::precipitationRate(5.1, 3.0);
$sixHourRate = WeatherMapRainfallNowcastService::precipitationRate(10.2, 6.0);
assertRainfallRate(abs($threeHourRate - 1.7) < 0.000001, 'Three-hour accumulated precipitation was not normalised to mm/h.');
assertRainfallRate(abs($sixHourRate - 1.7) < 0.000001, 'Six-hour accumulated precipitation was not normalised to mm/h.');
assertRainfallRate(WeatherMapRainfallNowcastService::precipitationRate(-2.0, 3.0) === 0.0, 'Negative precipitation must be clamped to zero.');

$invalidDurationRejected = false;
try {
    WeatherMapRainfallNowcastService::precipitationRate(1.0, 0.0);
} catch (InvalidArgumentException) {
    $invalidDurationRejected = true;
}
assertRainfallRate($invalidDurationRejected, 'A zero-length forecast step must be rejected.');

echo "ECMWF rainfall-rate tests passed.\n";
