<?php
declare(strict_types=1);

/** Samples the decoded regular ECMWF grid using the selected map interpolation. */
final class WeatherMapRasterTileRenderer
{
    public static function sample(array $grid, int $columns, int $rows, float $x, float $y, string $mode): float
    {
        if (!in_array($mode, ['nearest', 'linear', 'cubic', 'monotone'], true)) {
            throw new InvalidArgumentException('Unsupported weather-map interpolation mode.');
        }
        if ($columns < 1 || $rows < 1 || count($grid) !== $columns * $rows) {
            throw new InvalidArgumentException('Invalid decoded weather-map grid.');
        }

        $at = static function (int $ix, int $iy) use ($grid, $columns, $rows): float {
            $ix = max(0, min($columns - 1, $ix));
            $iy = max(0, min($rows - 1, $iy));
            return (float)($grid[$iy * $columns + $ix] ?? NAN);
        };
        $x0 = (int)floor($x);
        $y0 = (int)floor($y);
        $fx = $x - $x0;
        $fy = $y - $y0;
        if ($mode === 'nearest') return $at((int)round($x), (int)round($y));

        if ($mode === 'linear') {
            $a = $at($x0, $y0);
            $b = $at($x0 + 1, $y0);
            $c = $at($x0, $y0 + 1);
            $d = $at($x0 + 1, $y0 + 1);
            if (!is_finite($a) || !is_finite($b) || !is_finite($c) || !is_finite($d)) {
                return $at((int)round($x), (int)round($y));
            }
            return ($a * (1 - $fx) + $b * $fx) * (1 - $fy) +
                ($c * (1 - $fx) + $d * $fx) * $fy;
        }

        $interpolate = static function (float $p0, float $p1, float $p2, float $p3, float $t): float {
            return 0.5 * (
                2 * $p1 + (-$p0 + $p2) * $t +
                (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3) * $t * $t +
                (-$p0 + 3 * $p1 - 3 * $p2 + $p3) * $t * $t * $t
            );
        };

        $intermediate = [];
        for ($row = -1; $row <= 2; $row++) {
            $samples = [
                $at($x0 - 1, $y0 + $row),
                $at($x0, $y0 + $row),
                $at($x0 + 1, $y0 + $row),
                $at($x0 + 2, $y0 + $row),
            ];
            foreach ($samples as $sample) {
                if (!is_finite($sample)) return $at((int)round($x), (int)round($y));
            }
            $intermediate[] = $interpolate($samples[0], $samples[1], $samples[2], $samples[3], $fx);
        }
        $value = $interpolate($intermediate[0], $intermediate[1], $intermediate[2], $intermediate[3], $fy);
        if ($mode === 'monotone') {
            $corners = [$at($x0, $y0), $at($x0 + 1, $y0), $at($x0, $y0 + 1), $at($x0 + 1, $y0 + 1)];
            $value = max(min($corners), min(max($corners), $value));
        }
        return $value;
    }
}
