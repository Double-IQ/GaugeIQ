<?php
declare(strict_types=1);

/**
 * Tunable inputs used by the dashboard's Weather Stability and Conditions Trend
 * calculations. Keep validation bounds here so the private formula page and
 * dashboard use the same defaults and allowed ranges.
 */
function weatherFormulaFields(): array
{
    return [
        'stability_min_readings' => ['group' => 'Weather Stability · data requirements', 'label' => 'Minimum unique readings', 'default' => 3, 'min' => 2, 'max' => 20, 'step' => 1, 'unit' => 'readings', 'help' => 'Minimum timestamp-deduplicated observations before scoring.'],
        'stability_min_hours' => ['group' => 'Weather Stability · data requirements', 'label' => 'Minimum observation span', 'default' => 1, 'min' => 0.25, 'max' => 24, 'step' => 0.25, 'unit' => 'hours', 'help' => 'Minimum elapsed time covered by the readings.'],
        'stability_min_signals' => ['group' => 'Weather Stability · data requirements', 'label' => 'Minimum valid signals', 'default' => 5, 'min' => 1, 'max' => 9, 'step' => 1, 'unit' => 'of 9', 'help' => 'At least this many of the nine signals must have usable readings.'],
        'baseline_days' => ['group' => 'Weather Stability · local baseline', 'label' => 'Look-back days', 'default' => 14, 'min' => 1, 'max' => 60, 'step' => 1, 'unit' => 'days', 'help' => 'How many previous days are searched for comparable six-hour windows.'],
        'baseline_min_windows' => ['group' => 'Weather Stability · local baseline', 'label' => 'Minimum comparable windows', 'default' => 5, 'min' => 1, 'max' => 60, 'step' => 1, 'unit' => 'windows', 'help' => 'Minimum historical scores needed before showing a baseline median.'],
        'baseline_deviation' => ['group' => 'Weather Stability · local baseline', 'label' => 'Meaningful difference from median', 'default' => 2, 'min' => 0, 'max' => 10, 'step' => 0.1, 'unit' => 'score points', 'help' => 'Absolute score difference that marks current change as more or less than usual.'],
        'trend_min_readings' => ['group' => 'Conditions Trend · data requirements', 'label' => 'Minimum unique readings', 'default' => 3, 'min' => 2, 'max' => 20, 'step' => 1, 'unit' => 'readings', 'help' => 'Minimum timestamp-deduplicated observations before classifying a trend.'],
        'trend_min_hours' => ['group' => 'Conditions Trend · data requirements', 'label' => 'Minimum observation span', 'default' => 1, 'min' => 0.25, 'max' => 24, 'step' => 0.25, 'unit' => 'hours', 'help' => 'Minimum elapsed time covered by the readings.'],
        'trend_classification_threshold' => ['group' => 'Conditions Trend · classification', 'label' => 'Classification score threshold', 'default' => 2, 'min' => 0.5, 'max' => 20, 'step' => 0.5, 'unit' => 'points', 'help' => 'A positive score at or above this value is Worsening; a negative score at or below its negative is Improving.'],
        'trend_pressure_threshold' => ['group' => 'Conditions Trend · signal thresholds', 'label' => 'Pressure change rate', 'default' => 0.3, 'min' => 0, 'max' => 5, 'step' => 0.1, 'unit' => 'hPa/hour', 'help' => 'Pressure rising/falling rate that contributes a trend signal.'],
        'trend_pressure_weight' => ['group' => 'Conditions Trend · signal weights', 'label' => 'Pressure signal weight', 'default' => 2, 'min' => 0, 'max' => 10, 'step' => 0.5, 'unit' => 'weight', 'help' => 'Influence of the pressure signal on the combined directional score.'],
        'trend_rain_onset' => ['group' => 'Conditions Trend · signal thresholds', 'label' => 'Rain onset threshold', 'default' => 0.2, 'min' => 0, 'max' => 10, 'step' => 0.1, 'unit' => 'mm', 'help' => 'Rainfall level used to detect rain appearing in the latest reading.'],
        'trend_rain_change_threshold' => ['group' => 'Conditions Trend · signal thresholds', 'label' => 'Six-hour-equivalent rainfall change', 'default' => 0.2, 'min' => 0, 'max' => 10, 'step' => 0.1, 'unit' => 'mm', 'help' => 'Rainfall change required for an increasing/decreasing rainfall signal.'],
        'trend_rain_partial_strength' => ['group' => 'Conditions Trend · signal weights', 'label' => 'Rainfall change signal strength', 'default' => 0.5, 'min' => 0, 'max' => 1, 'step' => 0.1, 'unit' => 'strength', 'help' => 'Strength applied when rainfall changes but does not cross the rain-onset condition.'],
        'trend_rain_weight' => ['group' => 'Conditions Trend · signal weights', 'label' => 'Rainfall signal weight', 'default' => 2, 'min' => 0, 'max' => 10, 'step' => 0.5, 'unit' => 'weight', 'help' => 'Influence of rainfall signals on the combined directional score.'],
        'trend_wind_threshold' => ['group' => 'Conditions Trend · signal thresholds', 'label' => 'Six-hour-equivalent wind speed change', 'default' => 8, 'min' => 0, 'max' => 100, 'step' => 1, 'unit' => 'km/h', 'help' => 'Wind speed change required to contribute a trend signal.'],
        'trend_wind_weight' => ['group' => 'Conditions Trend · signal weights', 'label' => 'Wind speed signal weight', 'default' => 1, 'min' => 0, 'max' => 10, 'step' => 0.5, 'unit' => 'weight', 'help' => 'Influence of the wind speed signal on the combined directional score.'],
        'trend_cloud_threshold' => ['group' => 'Conditions Trend · signal thresholds', 'label' => 'Six-hour-equivalent cloud-cover change', 'default' => 20, 'min' => 0, 'max' => 100, 'step' => 1, 'unit' => 'percentage points', 'help' => 'Cloud cover change required to contribute a trend signal.'],
        'trend_cloud_weight' => ['group' => 'Conditions Trend · signal weights', 'label' => 'Cloud cover signal weight', 'default' => 1, 'min' => 0, 'max' => 10, 'step' => 0.5, 'unit' => 'weight', 'help' => 'Influence of cloud cover on the combined directional score.'],
        'trend_code_threshold' => ['group' => 'Conditions Trend · signal thresholds', 'label' => 'Weather-code severity-category change', 'default' => 1, 'min' => 0, 'max' => 5, 'step' => 1, 'unit' => 'categories', 'help' => 'Difference in the weather-code severity category needed to contribute a trend signal.'],
        'trend_code_weight' => ['group' => 'Conditions Trend · signal weights', 'label' => 'Weather-code signal weight', 'default' => 2, 'min' => 0, 'max' => 10, 'step' => 0.5, 'unit' => 'weight', 'help' => 'Influence of the reported weather category on the combined directional score.'],
    ];
}

function weatherFormulaDefaults(): array
{
    $values = [];
    foreach (weatherFormulaFields() as $key => $field) {
        $values[$key] = $field['default'];
    }
    foreach ([
        'pressure' => [2, 0.3, 1.5],
        'temperature' => [1, 1, 3],
        'humidity' => [1, 4, 12],
        'wind_speed' => [2, 8, 20],
        'wind_direction' => [1, 20, 90],
        'dew_point_spread' => [1, 1, 3],
        'rainfall' => [2, 0.2, 2],
        'cloud_cover' => [1, 15, 50],
        'weather_code' => [1, 0, 1],
    ] as $signal => [$weight, $trigger, $maximum]) {
        $values['stability_weight_' . $signal] = $weight;
        $values['stability_trigger_' . $signal] = $trigger;
        $values['stability_max_' . $signal] = $maximum;
    }
    return $values;
}

function weatherFormulaValues(PDO $pdo): array
{
    $values = weatherFormulaDefaults();
    $stmt = $pdo->query("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` LIKE 'formula_%'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = substr((string)$row['key'], strlen('formula_'));
        if (array_key_exists($key, $values) && is_numeric($row['value'])) {
            $field = weatherFormulaFields()[$key] ?? null;
            if ($field === null) {
                $field = ['min' => 0, 'max' => 1000];
            }
            $number = (float)$row['value'];
            if ($number >= $field['min'] && $number <= $field['max']) {
                $values[$key] = $number;
            }
        }
    }
    return $values;
}
