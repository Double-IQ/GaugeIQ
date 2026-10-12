<?php
declare(strict_types=1);

final class AlertRuleService
{
    private DateTimeZone $timezone;

    public function __construct(private PDO $db, ?DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new DateTimeZone(date_default_timezone_get());
        $this->migrateLegacyWeatherChangeRules();
    }

    /**
     * Convert saved change-score rules to their inverse Weather Stability
     * equivalent once, so existing rules keep approximately the same intent.
     */
    private function migrateLegacyWeatherChangeRules(): void
    {
        $rows = $this->db->query(
            "SELECT id, condition_type, configuration_json
             FROM gaugeiq_alert_rules
             WHERE metric = 'weather_change'"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return;
        }

        $update = $this->db->prepare(
            'UPDATE gaugeiq_alert_rules
             SET metric = ?, condition_type = ?, configuration_json = ?, updated_at = ?
             WHERE id = ?'
        );

        foreach ($rows as $row) {
            if ((string)$row['condition_type'] !== 'above') {
                continue;
            }

            $oldConfiguration = json_decode((string)$row['configuration_json'], true);
            if (!is_array($oldConfiguration) || !isset($oldConfiguration['value']) || !is_numeric($oldConfiguration['value'])) {
                continue;
            }

            $oldThreshold = max(1, min(10, (int)round((float)$oldConfiguration['value'])));
            $stabilityThreshold = 10 - $oldThreshold;
            $update->execute([
                'weather_stability',
                'below',
                json_encode(['value' => $stabilityThreshold], JSON_THROW_ON_ERROR),
                gmdate('c'),
                (int)$row['id'],
            ]);
        }
    }

    public function all(bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM gaugeiq_alert_rules';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY id ASC';

        return $this->db->query($sql)->fetchAll();
    }

    public function create(
        string $name,
        string $metric,
        string $conditionType,
        array $configuration,
        int $cooldownMinutes = 60
    ): int {
        $this->validate($metric, $conditionType, $configuration);

        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'INSERT INTO gaugeiq_alert_rules
             (name, metric, condition_type, configuration_json, enabled, cooldown_minutes, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        );
        $stmt->execute([
            trim($name) ?: 'GaugeIQ alert',
            $metric,
            $conditionType,
            json_encode($configuration, JSON_THROW_ON_ERROR),
            max(0, $cooldownMinutes),
            $now,
            $now,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        string $metric,
        string $conditionType,
        array $configuration,
        bool $enabled,
        int $cooldownMinutes
    ): void {
        $this->validate($metric, $conditionType, $configuration);

        $stmt = $this->db->prepare(
            'UPDATE gaugeiq_alert_rules
             SET name = ?, metric = ?, condition_type = ?, configuration_json = ?,
                 enabled = ?, cooldown_minutes = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            trim($name) ?: 'GaugeIQ alert',
            $metric,
            $conditionType,
            json_encode($configuration, JSON_THROW_ON_ERROR),
            $enabled ? 1 : 0,
            max(0, $cooldownMinutes),
            gmdate('c'),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM gaugeiq_alert_rules WHERE id = ?');
        $stmt->execute([$id]);

        $stmt = $this->db->prepare('DELETE FROM gaugeiq_alert_events WHERE rule_id = ?');
        $stmt->execute([$id]);
    }

    public function evaluate(array $previous, array $current): array
    {
        $matches = [];

        foreach ($this->all(true) as $rule) {
            $config = json_decode((string)$rule['configuration_json'], true);
            if (!is_array($config)) {
                continue;
            }

            if (!$this->cooldownExpired($rule)) {
                continue;
            }

            $result = $this->matches(
                (string)$rule['metric'],
                (string)$rule['condition_type'],
                $config,
                $previous,
                $current
            );

            if ($result === null) {
                continue;
            }

            $matches[] = [
                'id' => (int)$rule['id'],
                'name' => (string)$rule['name'],
                'message' => $result,
            ];
        }

        return $matches;
    }

    public function evaluateWeatherStability(): array
    {
        $matches = [];

        foreach ($this->all(true) as $rule) {
            if ((string)$rule['metric'] !== 'weather_stability' || !$this->cooldownExpired($rule)) {
                continue;
            }

            $config = json_decode((string)$rule['configuration_json'], true);
            if (!is_array($config)) {
                continue;
            }

            $result = $this->weatherStability((string)$rule['condition_type'], $config);
            if ($result === null) {
                continue;
            }

            $matches[] = [
                'id' => (int)$rule['id'],
                'name' => (string)$rule['name'],
                'message' => $result,
            ];
        }

        return $matches;
    }

    public function markTriggered(int $ruleId, string $message, string $observedAt): void
    {
        $now = gmdate('c');

        $stmt = $this->db->prepare(
            'UPDATE gaugeiq_alert_rules SET last_triggered_at = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $now, $ruleId]);

        $stmt = $this->db->prepare(
            'INSERT INTO gaugeiq_alert_events (rule_id, observed_at, message, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ruleId, $observedAt, $message, $now]);
    }

    private function cooldownExpired(array $rule): bool
    {
        if (empty($rule['last_triggered_at'])) {
            return true;
        }

        $last = strtotime((string)$rule['last_triggered_at']);
        if ($last === false) {
            return true;
        }

        return time() - $last >= ((int)$rule['cooldown_minutes'] * 60);
    }

    private function matches(
        string $metric,
        string $type,
        array $config,
        array $previous,
        array $current
    ): ?string {
        return match ($metric) {
            'pressure' => $this->pressure($type, $config, $previous, $current),
            'humidity' => $this->humidity($type, $config, $previous, $current),
            'wind_speed' => $this->windSpeed($type, $config, $previous, $current),
            'wind_direction' => $this->windDirection($type, $config, $previous, $current),
            'wind' => $this->combinedWind($type, $config, $previous, $current),
            'weather_stability' => null,
            default => null,
        };
    }

    private function pressure(string $type, array $c, array $p, array $n): ?string
    {
        $value = (float)$n['pressure_hpa'];
        $old = (float)$p['pressure_hpa'];
        $threshold = (float)($c['value'] ?? 0);

        if ($type === 'change' && abs($value - $old) >= $threshold) {
            return sprintf('Pressure changed by %.1f hPa to %.1f hPa.', abs($value - $old), $value);
        }
        if ($type === 'above' && $value >= $threshold && $old < $threshold) {
            return sprintf('Pressure rose above %.1f hPa to %.1f hPa.', $threshold, $value);
        }
        if ($type === 'below' && $value <= $threshold && $old > $threshold) {
            return sprintf('Pressure fell below %.1f hPa to %.1f hPa.', $threshold, $value);
        }

        return null;
    }

    private function humidity(string $type, array $c, array $p, array $n): ?string
    {
        $value = (float)$n['humidity_percent'];
        $old = (float)$p['humidity_percent'];
        $threshold = (float)($c['value'] ?? 0);

        if ($type === 'change' && abs($value - $old) >= $threshold) {
            return sprintf('Humidity changed by %.0f%% to %.0f%%.', abs($value - $old), $value);
        }
        if ($type === 'above' && $value >= $threshold && $old < $threshold) {
            return sprintf('Humidity rose above %.0f%% to %.0f%%.', $threshold, $value);
        }
        if ($type === 'below' && $value <= $threshold && $old > $threshold) {
            return sprintf('Humidity fell below %.0f%% to %.0f%%.', $threshold, $value);
        }

        return null;
    }

    private function windSpeed(string $type, array $c, array $p, array $n): ?string
    {
        $value = (float)$n['wind_speed_kmh'];
        $old = (float)$p['wind_speed_kmh'];
        $threshold = (float)($c['value'] ?? 0);

        if ($type === 'change' && abs($value - $old) >= $threshold) {
            return sprintf('Wind speed changed by %.1f km/h to %.1f km/h.', abs($value - $old), $value);
        }
        if ($type === 'above' && $value >= $threshold && $old < $threshold) {
            return sprintf('Wind speed rose above %.1f km/h to %.1f km/h.', $threshold, $value);
        }
        if ($type === 'below' && $value <= $threshold && $old > $threshold) {
            return sprintf('Wind speed fell below %.1f km/h to %.1f km/h.', $threshold, $value);
        }

        return null;
    }

    private function windDirection(string $type, array $c, array $p, array $n): ?string
    {
        $value = (float)$n['wind_direction_degrees'];
        $old = (float)$p['wind_direction_degrees'];
        $difference = $this->directionDifference($old, $value);

        if ($type === 'change' && $difference >= (float)($c['degrees'] ?? 0)) {
            return sprintf('Wind direction changed by %.0f° to %s.', $difference, PressureService::directionLabel($value));
        }

        if ($type === 'specific') {
            $target = (float)($c['degrees'] ?? 0);
            if ($this->directionDifference($target, $value) <= 22.5 &&
                $this->directionDifference($target, $old) > 22.5) {
                return sprintf('Wind is now coming from %s.', PressureService::directionLabel($value));
            }
        }

        return null;
    }

    private function weatherStability(string $type, array $configuration): ?string
    {
        if ($type !== 'below') {
            return null;
        }

        $threshold = max(0.0, min(10.0, (float)($configuration['value'] ?? -1)));
        $score = $this->currentWeatherStabilityScore();
        if ($score === null || $score > $threshold) {
            return null;
        }

        return sprintf(
            'Weather Stability is %.1f/10 (your alert threshold is %.0f/10).',
            $score,
            $threshold
        );
    }

    /**
     * Mirror the weighted six-hour calculation in public/js/app.js.
     * The alert must use the same live sources, observation timestamps,
     * duplicate-time handling, signal weights and rounding as the card.
     */
    private function currentWeatherStabilityScore(): ?float
    {
        $now = new DateTimeImmutable('now', $this->timezone);
        $nowTimestamp = $now->getTimestamp();
        $fromTimestamp = $nowTimestamp - (6 * 3600);
        $fromDay = (new DateTimeImmutable('@' . $fromTimestamp))
            ->setTimezone($this->timezone)
            ->format('Y-m-d');

        $stmt = $this->db->prepare(
            'SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent,
                    wind_speed_kmh, wind_direction_degrees, rainfall_mm,
                    cloud_cover_percent, weather_code, observed_at, created_at, source
             FROM gaugeiq_pressure_readings
             WHERE observed_at >= ?
             ORDER BY observed_at ASC, id ASC'
        );
        $stmt->execute([$fromDay]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $liveReadings = [];
        $recentBackfill = [];
        foreach ($rows as $row) {
            $source = $row['source'] ?? null;
            $isLive = $source === null || $source === 'Open-Meteo';
            $isBackfill = $source === 'Open-Meteo Recent Backfill';
            if (!$isLive && !$isBackfill) {
                continue;
            }

            $observedAt = trim((string)($row['observed_at'] ?? ''));
            if ($observedAt === '') {
                continue;
            }
            try {
                $observedDate = new DateTimeImmutable($observedAt, $this->timezone);
            } catch (Throwable) {
                continue;
            }

            $timestamp = $observedDate->getTimestamp();
            if ($timestamp < $fromTimestamp || $timestamp > $nowTimestamp + 300) {
                continue;
            }

            $row['_weather_timestamp'] = $timestamp;
            if ($isLive) {
                $liveReadings[] = $row;
            } else {
                $recentBackfill[] = $row;
            }
        }

        $changeScore = $this->weatherChangeScore($liveReadings);
        if ($changeScore === null && $recentBackfill !== []) {
            $changeScore = $this->weatherChangeScore(array_merge($liveReadings, $recentBackfill));
        }
        if ($changeScore === null) {
            return null;
        }

        return round(10.0 - $changeScore, 1, PHP_ROUND_HALF_UP);
    }

    /**
     * Return the dashboard's 0–10 weighted change score before inversion.
     * Higher change score means lower Weather Stability.
     */
    private function weatherChangeScore(array $readings): ?float
    {
        $fields = [
            'temperature' => 'temperature_c',
            'dewPoint' => 'dew_point_c',
            'pressure' => 'pressure_hpa',
            'humidity' => 'humidity_percent',
            'wind' => 'wind_speed_kmh',
            'direction' => 'wind_direction_degrees',
            'rain' => 'rainfall_mm',
            'cloud' => 'cloud_cover_percent',
            'weatherCode' => 'weather_code',
        ];

        $valid = [];
        foreach ($readings as $item) {
            $time = isset($item['_weather_timestamp']) ? (int)$item['_weather_timestamp'] : false;
            if ($time === false) {
                continue;
            }

            $row = [
                'source' => $item['source'] ?? null,
                'time' => $time,
            ];
            foreach ($fields as $key => $column) {
                $row[$key] = $this->weatherNumeric($item[$column] ?? null);
            }
            $valid[] = $row;
        }

        usort($valid, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);

        // Match the dashboard: remove duplicate timestamps and prefer live
        // observations over temporary model backfill at the same timestamp.
        $byTime = [];
        foreach ($valid as $row) {
            $key = (string)$row['time'];
            $existing = $byTime[$key] ?? null;
            if (
                $existing === null ||
                (($existing['source'] ?? null) === 'Open-Meteo Recent Backfill' &&
                    ($row['source'] ?? null) !== 'Open-Meteo Recent Backfill') ||
                ($existing['source'] ?? null) === ($row['source'] ?? null)
            ) {
                $byTime[$key] = $row;
            }
        }
        $valid = array_values($byTime);
        usort($valid, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);

        if (count($valid) < 3) {
            return null;
        }

        $totalHours = ($valid[count($valid) - 1]['time'] - $valid[0]['time']) / 3600;
        if ($totalHours < 1) {
            return null;
        }

        $weights = [
            'pressure' => 2,
            'temperature' => 1,
            'humidity' => 1,
            'windSpeed' => 2,
            'windDirection' => 1,
            'dewPointSpread' => 1,
            'rainfall' => 2,
            'cloudCover' => 1,
            'weatherCode' => 1,
        ];
        $components = [];
        $addComponent = static function (string $key, ?array $pair, float $severity) use (&$components, $weights): void {
            if ($pair === null || !is_finite($severity)) {
                return;
            }
            $components[] = [
                'weight' => $weights[$key],
                'severity' => max(0.0, min(1.0, $severity)),
            ];
        };

        $pressure = $this->weatherFieldPair($valid, 'pressure');
        if ($pressure !== null) {
            $addComponent('pressure', $pressure, $this->weatherSeverity(abs($pressure['delta']) / $pressure['hours'], 0.3, 1.5));
        }

        $temperature = $this->weatherFieldPair($valid, 'temperature');
        if ($temperature !== null) {
            $addComponent('temperature', $temperature, $this->weatherSeverity(abs($temperature['delta']) * 6 / $temperature['hours'], 1, 3));
        }

        $humidity = $this->weatherFieldPair($valid, 'humidity');
        if ($humidity !== null) {
            $addComponent('humidity', $humidity, $this->weatherSeverity(abs($humidity['delta']) * 6 / $humidity['hours'], 4, 12));
        }

        $wind = $this->weatherFieldPair($valid, 'wind');
        if ($wind !== null) {
            $addComponent('windSpeed', $wind, $this->weatherSeverity(abs($wind['delta']) * 6 / $wind['hours'], 8, 20));
        }

        $direction = $this->weatherFieldPair($valid, 'direction');
        if ($direction !== null) {
            $shift = $this->weatherDirectionDifference($direction['first'], $direction['last']);
            $addComponent('windDirection', $direction, $this->weatherSeverity($shift, 20, 90));
        }

        $firstTemp = $this->weatherFieldPair($valid, 'temperature');
        $firstDew = $this->weatherFieldPair($valid, 'dewPoint');
        if ($firstTemp !== null && $firstDew !== null) {
            $spreadFirst = $firstTemp['first'] - $firstDew['first'];
            $spreadLast = $firstTemp['last'] - $firstDew['last'];
            $narrowing = $spreadFirst - $spreadLast;
            $addComponent('dewPointSpread', [
                'first' => $spreadFirst,
                'last' => $spreadLast,
                'delta' => $spreadLast - $spreadFirst,
                'hours' => min($firstTemp['hours'], $firstDew['hours']),
            ], $this->weatherSeverity($narrowing, 1, 3));
        }

        $rain = $this->weatherFieldPair($valid, 'rain');
        if ($rain !== null) {
            $addComponent('rainfall', $rain, $this->weatherSeverity(abs($rain['delta']) * 6 / $rain['hours'], 0.2, 2));
        }

        $cloud = $this->weatherFieldPair($valid, 'cloud');
        if ($cloud !== null) {
            $addComponent('cloudCover', $cloud, $this->weatherSeverity(abs($cloud['delta']) * 6 / $cloud['hours'], 15, 50));
        }

        $codes = $this->weatherFieldPair($valid, 'weatherCode');
        if ($codes !== null) {
            $severityChange = abs(
                $this->weatherCodeSeverity((int)$codes['last']) -
                $this->weatherCodeSeverity((int)$codes['first'])
            );
            $changed = (int)$codes['first'] !== (int)$codes['last'];
            $severity = $changed
                ? max($severityChange > 0 ? $severityChange / 5 : 0.25, 0.25)
                : 0.0;
            $addComponent('weatherCode', $codes, $severity);
        }

        if (count($components) < 5) {
            return null;
        }

        $availableWeight = 0.0;
        $weightedSeverity = 0.0;
        foreach ($components as $component) {
            $availableWeight += $component['weight'];
            $weightedSeverity += $component['weight'] * $component['severity'];
        }
        if ($availableWeight <= 0) {
            return null;
        }

        return round(10 * $weightedSeverity / $availableWeight, 1, PHP_ROUND_HALF_UP);
    }

    private function weatherFieldPair(array $readings, string $field): ?array
    {
        $available = array_values(array_filter(
            $readings,
            static fn(array $row): bool => $row[$field] !== null && is_finite((float)$row[$field])
        ));
        if (count($available) < 2) {
            return null;
        }

        $first = $available[0];
        $last = $available[count($available) - 1];
        $hours = ($last['time'] - $first['time']) / 3600;
        if (!is_finite($hours) || $hours <= 0) {
            return null;
        }

        return [
            'first' => (float)$first[$field],
            'last' => (float)$last[$field],
            'delta' => (float)$last[$field] - (float)$first[$field],
            'hours' => $hours,
        ];
    }

    private function weatherNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return is_finite($number) ? $number : null;
    }

    private function weatherSeverity(float $magnitude, float $trigger, float $maximum): float
    {
        if (!is_finite($magnitude) || $magnitude < $trigger) {
            return 0.0;
        }
        return max(0.0, min(1.0, $magnitude / $maximum));
    }

    private function weatherDirectionDifference(float $a, float $b): float
    {
        $difference = abs(fmod($a - $b + 360.0, 360.0));
        return min($difference, 360.0 - $difference);
    }

    private function weatherCodeSeverity(int $code): int
    {
        return match (true) {
            in_array($code, [95, 96, 99], true) => 5,
            in_array($code, [65, 67, 75, 82, 86], true) => 4,
            in_array($code, [61, 63, 66, 71, 73, 77, 80, 81, 85], true) => 3,
            in_array($code, [45, 48, 51, 53, 55, 56, 57], true) => 2,
            in_array($code, [2, 3], true) => 1,
            default => 0,
        };
    }

    private function combinedWind(string $type, array $c, array $p, array $n): ?string
    {
        if ($type !== 'speed_and_direction') {
            return null;
        }

        $speed = (float)$n['wind_speed_kmh'];
        $oldSpeed = (float)$p['wind_speed_kmh'];
        $minSpeed = (float)($c['speed_min'] ?? 0);
        $from = (float)($c['direction_from'] ?? 0);
        $to = (float)($c['direction_to'] ?? 360);

        $speedMatch = $speed >= $minSpeed;
        $directionMatch = $this->inDirectionRange((float)$n['wind_direction_degrees'], $from, $to);
        $oldMatch = $oldSpeed >= $minSpeed &&
            $this->inDirectionRange((float)$p['wind_direction_degrees'], $from, $to);

        if ($speedMatch && $directionMatch && !$oldMatch) {
            return sprintf(
                'Wind is %.1f km/h from %s.',
                $speed,
                PressureService::directionLabel((float)$n['wind_direction_degrees'])
            );
        }

        return null;
    }

    private function inDirectionRange(float $value, float $from, float $to): bool
    {
        $value = fmod($value + 360.0, 360.0);
        $from = fmod($from + 360.0, 360.0);
        $to = fmod($to + 360.0, 360.0);

        return $from <= $to
            ? $value >= $from && $value <= $to
            : $value >= $from || $value <= $to;
    }

    private function directionDifference(float $a, float $b): float
    {
        $difference = abs(fmod($a - $b, 360.0));
        return min($difference, 360.0 - $difference);
    }

    private function validate(string $metric, string $type, array $configuration): void
    {
        $allowedMetrics = ['pressure', 'humidity', 'wind_speed', 'wind_direction', 'wind', 'weather_stability'];
        $allowedTypes = ['change', 'above', 'below', 'specific', 'speed_and_direction'];

        if (!in_array($metric, $allowedMetrics, true) || !in_array($type, $allowedTypes, true)) {
            throw new InvalidArgumentException('Unsupported GaugeIQ alert rule.');
        }

        if ($configuration === []) {
            throw new InvalidArgumentException('Alert rule configuration cannot be empty.');
        }

        if ($metric === 'weather_stability' && ($type !== 'below' || !isset($configuration['value']) || !is_numeric($configuration['value']) || (float)$configuration['value'] < 0 || (float)$configuration['value'] > 10)) {
            throw new InvalidArgumentException('Weather Stability alerts require a threshold from 0 to 10 and the Falls to or below condition.');
        }
    }
}
