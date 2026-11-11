<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$configPath = __DIR__ . '/../../config/local.php';
if (!is_file($configPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'GaugeIQ is not installed.']);
    exit;
}

try {
    $config = require $configPath;
    require_once __DIR__ . '/../../app/AdminAuth.php';
    AdminAuth::startSession();
    if (!AdminAuth::check()) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign in to GaugeIQ Admin to view rainfall diagnostics.']);
        exit;
    }

    require_once __DIR__ . '/../../app/Database.php';
    $locationId = filter_input(INPUT_GET, 'location', FILTER_VALIDATE_INT);
    $locationId = $locationId === false || $locationId === null ? 2 : $locationId;
    if (!in_array($locationId, [1, 2], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Location must be 1 or 2.']);
        exit;
    }

    $pdo = (new Database($config))->pdo();
    $key = $locationId === 2 ? 'weather_map_rainfall_nowcast_location_2' : 'weather_map_rainfall_nowcast';
    $stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $stored = $stmt->fetchColumn();
    if (!is_string($stored) || $stored === '') {
        http_response_code(404);
        echo json_encode(['error' => 'No stored rainfall outlook is available for this location yet.']);
        exit;
    }

    $payload = json_decode($stored, true);
    if (!is_array($payload) || !is_array($payload['frames'] ?? null)) {
        http_response_code(500);
        echo json_encode(['error' => 'The stored rainfall outlook is not valid JSON data.']);
        exit;
    }

    $diagnosticFrames = [];
    foreach ($payload['frames'] as $frame) {
        $cells = is_array($frame['rain_cells'] ?? null) ? $frame['rain_cells'] : [];
        $quadrants = [
            'north' => ['count' => 0, 'max_precipitation' => 0.0, 'examples' => []],
            'east' => ['count' => 0, 'max_precipitation' => 0.0, 'examples' => []],
            'south' => ['count' => 0, 'max_precipitation' => 0.0, 'examples' => []],
            'west' => ['count' => 0, 'max_precipitation' => 0.0, 'examples' => []],
        ];
        $normalisedCells = [];
        foreach ($cells as $cell) {
            if (!is_array($cell) ||
                !is_numeric($cell['bearing_degrees'] ?? null) ||
                !is_numeric($cell['distance_km'] ?? null) ||
                !is_numeric($cell['precipitation_mm_per_hour'] ?? $cell['precipitation_mm'] ?? null)) {
                continue;
            }
            $bearing = fmod((float)$cell['bearing_degrees'] + 360.0, 360.0);
            $quadrant = $bearing >= 315.0 || $bearing < 45.0 ? 'north'
                : ($bearing < 135.0 ? 'east'
                : ($bearing < 225.0 ? 'south' : 'west'));
            $amount = (float)($cell['precipitation_mm_per_hour'] ?? $cell['precipitation_mm']);
            $entry = [
                'bearing_degrees' => round($bearing, 1),
                'distance_km' => round((float)$cell['distance_km'], 1),
                'raw_precipitation_mm' => is_numeric($cell['raw_precipitation_mm'] ?? null) ? round((float)$cell['raw_precipitation_mm'], 3) : null,
                'precipitation' => round($amount, 2),
                'latitude' => is_numeric($cell['latitude'] ?? null) ? round((float)$cell['latitude'], 4) : null,
                'longitude' => is_numeric($cell['longitude'] ?? null) ? round((float)$cell['longitude'], 4) : null,
                'quadrant' => $quadrant,
            ];
            $quadrants[$quadrant]['count']++;
            $quadrants[$quadrant]['max_precipitation'] = max($quadrants[$quadrant]['max_precipitation'], $amount);
            if (count($quadrants[$quadrant]['examples']) < 8) {
                $quadrants[$quadrant]['examples'][] = $entry;
            }
            $normalisedCells[] = $entry;
        }
        foreach ($quadrants as &$quadrantData) {
            $quadrantData['max_precipitation'] = round($quadrantData['max_precipitation'], 2);
        }
        unset($quadrantData);
        usort($normalisedCells, static fn(array $a, array $b): int =>
            $a['bearing_degrees'] <=> $b['bearing_degrees']
        );
        $diagnosticFrames[] = [
            'time' => $frame['time'] ?? null,
            'step_duration_hours' => $frame['step_duration_hours'] ?? null,
            'local_precipitation' => $frame['local_precipitation_mm_per_hour'] ?? $frame['local_precipitation_mm'] ?? null,
            'wet_cells' => $frame['wet_cells'] ?? count($normalisedCells),
            'cell_count_returned' => count($normalisedCells),
            'quadrants' => $quadrants,
            'cells' => array_slice($normalisedCells, 0, 240),
            'cells_truncated' => count($normalisedCells) > 240,
        ];
    }

    echo json_encode([
        'diagnostic' => 'stored-decoded-ecmwf-rainfall-cells',
        'location_id' => $locationId,
        'location' => $payload['location'] ?? null,
        'source' => $payload['source'] ?? null,
        'updated_at' => $payload['updated_at'] ?? null,
        'search_radius_km' => $payload['search_radius_km'] ?? null,
        'schema_version' => $payload['schema_version'] ?? 1,
        'precipitation_unit' => (int)($payload['schema_version'] ?? 1) >= 2 ? 'mm/h' : 'mm',
        'wet_threshold' => $payload['wet_threshold_mm_per_hour'] ?? $payload['wet_threshold_mm'] ?? null,
        'encoding' => $payload['encoding'] ?? null,
        'frames' => $diagnosticFrames,
        'note' => 'Read-only diagnostic of the already stored decoded forecast. This endpoint does not trigger a refresh or change stored data.',
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('GaugeIQ rainfall diagnostic API failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Unable to read rainfall diagnostics.']);
}
