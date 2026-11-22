<?php
declare(strict_types=1);

/** Samples the decoded regular ECMWF grid using the selected map interpolation. */
final class WeatherMapRasterTileRenderer
{
    private static function valueAt(array $grid, int $columns, int $rows, int $x, int $y): float
    {
        $x = max(0, min($columns - 1, $x));
        $y = max(0, min($rows - 1, $y));
        return (float)($grid[$y * $columns + $x] ?? NAN);
    }

    private static function cubic(float $p0, float $p1, float $p2, float $p3, float $t): float
    {
        return 0.5 * (
            2 * $p1 + (-$p0 + $p2) * $t +
            (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3) * $t * $t +
            (-$p0 + 3 * $p1 - 3 * $p2 + $p3) * $t * $t * $t
        );
    }

    public static function validate(array $grid, int $columns, int $rows, string $mode): void
    {
        if (!in_array($mode, ['nearest', 'linear', 'cubic', 'monotone'], true)) {
            throw new InvalidArgumentException('Unsupported weather-map interpolation mode.');
        }
        if ($columns < 1 || $rows < 1 || count($grid) !== $columns * $rows) {
            throw new InvalidArgumentException('Invalid decoded weather-map grid.');
        }
    }

    public static function sample(array $grid, int $columns, int $rows, float $x, float $y, string $mode): float
    {
        $x0 = (int)floor($x);
        $y0 = (int)floor($y);
        $fx = $x - $x0;
        $fy = $y - $y0;
        if ($mode === 'nearest') {
            return self::valueAt($grid, $columns, $rows, (int)round($x), (int)round($y));
        }

        if ($mode === 'linear') {
            $a = self::valueAt($grid, $columns, $rows, $x0, $y0);
            $b = self::valueAt($grid, $columns, $rows, $x0 + 1, $y0);
            $c = self::valueAt($grid, $columns, $rows, $x0, $y0 + 1);
            $d = self::valueAt($grid, $columns, $rows, $x0 + 1, $y0 + 1);
            if (!is_finite($a) || !is_finite($b) || !is_finite($c) || !is_finite($d)) {
                return self::valueAt($grid, $columns, $rows, (int)round($x), (int)round($y));
            }
            return ($a * (1 - $fx) + $b * $fx) * (1 - $fy) +
                ($c * (1 - $fx) + $d * $fx) * $fy;
        }

        $rowsOfValues = [];
        for ($row = -1; $row <= 2; $row++) {
            $p0 = self::valueAt($grid, $columns, $rows, $x0 - 1, $y0 + $row);
            $p1 = self::valueAt($grid, $columns, $rows, $x0, $y0 + $row);
            $p2 = self::valueAt($grid, $columns, $rows, $x0 + 1, $y0 + $row);
            $p3 = self::valueAt($grid, $columns, $rows, $x0 + 2, $y0 + $row);
            if (!is_finite($p0) || !is_finite($p1) || !is_finite($p2) || !is_finite($p3)) {
                return self::valueAt($grid, $columns, $rows, (int)round($x), (int)round($y));
            }
            $rowsOfValues[] = self::cubic($p0, $p1, $p2, $p3, $fx);
        }
        $value = self::cubic(
            $rowsOfValues[0],
            $rowsOfValues[1],
            $rowsOfValues[2],
            $rowsOfValues[3],
            $fy
        );
        if ($mode === 'monotone') {
            $a = self::valueAt($grid, $columns, $rows, $x0, $y0);
            $b = self::valueAt($grid, $columns, $rows, $x0 + 1, $y0);
            $c = self::valueAt($grid, $columns, $rows, $x0, $y0 + 1);
            $d = self::valueAt($grid, $columns, $rows, $x0 + 1, $y0 + 1);
            $value = max(min($a, $b, $c, $d), min(max($a, $b, $c, $d), $value));
        }
        return $value;
    }
}
