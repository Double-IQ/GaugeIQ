<?php
declare(strict_types=1);

/** Samples the decoded regular ECMWF grid with Open-Meteo-compatible interpolation. */
final class WeatherMapRasterTileRenderer
{
    private static function at(array $grid, int $columns, int $x, int $y): float
    {
        return (float)($grid[$y * $columns + $x] ?? NAN);
    }

    private static function bilinearNaNAware(
        float $p0,
        float $p1,
        float $p2,
        float $p3,
        float $xFraction,
        float $yFraction
    ): float {
        $w0 = (1 - $xFraction) * (1 - $yFraction);
        $w1 = $xFraction * (1 - $yFraction);
        $w2 = (1 - $xFraction) * $yFraction;
        $w3 = $xFraction * $yFraction;
        $n0 = !is_finite($p0);
        $n1 = !is_finite($p1);
        $n2 = !is_finite($p2);
        $n3 = !is_finite($p3);

        if (!$n0 && !$n1 && !$n2 && !$n3) {
            return $p0 * $w0 + $p1 * $w1 + $p2 * $w2 + $p3 * $w3;
        }
        if ($n0 && !$n1 && !$n2 && !$n3) {
            if ($xFraction + $yFraction < 1) return NAN;
            $weights = $w1 + $w2 + $w3;
            return $weights > 0 ? ($p1 * $w1 + $p2 * $w2 + $p3 * $w3) / $weights : NAN;
        }
        if (!$n0 && $n1 && !$n2 && !$n3) {
            if ($xFraction > $yFraction) return NAN;
            $weights = $w0 + $w2 + $w3;
            return $weights > 0 ? ($p0 * $w0 + $p2 * $w2 + $p3 * $w3) / $weights : NAN;
        }
        if (!$n0 && !$n1 && $n2 && !$n3) {
            if ($xFraction < $yFraction) return NAN;
            $weights = $w0 + $w1 + $w3;
            return $weights > 0 ? ($p0 * $w0 + $p1 * $w1 + $p3 * $w3) / $weights : NAN;
        }
        if (!$n0 && !$n1 && !$n2 && $n3) {
            if ($xFraction + $yFraction > 1) return NAN;
            $weights = $w0 + $w1 + $w2;
            return $weights > 0 ? ($p0 * $w0 + $p1 * $w1 + $p2 * $w2) / $weights : NAN;
        }
        return NAN;
    }

    private static function linear(array $grid, int $columns, int $rows, int $x, int $y, float $xf, float $yf): float
    {
        if ($x < 0 || $y < 0 || $x >= $columns - 1 || $y >= $rows - 1) return NAN;
        $index = $y * $columns + $x;
        return self::bilinearNaNAware(
            (float)($grid[$index] ?? NAN),
            (float)($grid[$index + 1] ?? NAN),
            (float)($grid[$index + $columns] ?? NAN),
            (float)($grid[$index + $columns + 1] ?? NAN),
            $xf,
            $yf
        );
    }

    private static function cubic1D(float $p0, float $p1, float $p2, float $p3, float $t): float
    {
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        return 0.5 * (
            (-$t3 + 2 * $t2 - $t) * $p0 +
            (3 * $t3 - 5 * $t2 + 2) * $p1 +
            (-3 * $t3 + 4 * $t2 + $t) * $p2 +
            ($t3 - $t2) * $p3
        );
    }

    private static function monotone1D(float $t, float $p0, float $p1, float $p2, float $p3): float
    {
        $d0 = $p1 - $p0;
        $d1 = $p2 - $p1;
        $d2 = $p3 - $p2;
        $m1 = $d0 * $d1 <= 0 ? 0.0 : (2 * $d0 * $d1) / ($d0 + $d1);
        $m2 = $d1 * $d2 <= 0 ? 0.0 : (2 * $d1 * $d2) / ($d1 + $d2);
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        return (2 * $t3 - 3 * $t2 + 1) * $p1 +
            ($t3 - 2 * $t2 + $t) * $m1 +
            (-2 * $t3 + 3 * $t2) * $p2 +
            ($t3 - $t2) * $m2;
    }

    private static function interpolateHighOrder(
        array $grid,
        int $columns,
        int $rows,
        int $x,
        int $y,
        float $xf,
        float $yf,
        bool $monotone
    ): float {
        if ($x < 1 || $x >= $columns - 2 || $y < 1 || $y >= $rows - 2) {
            return self::linear($grid, $columns, $rows, $x, $y, $xf, $yf);
        }

        $rowValues = [];
        $innerMin = INF;
        $innerMax = -INF;
        for ($row = -1; $row <= 2; $row++) {
            $base = ($y + $row) * $columns;
            $p0 = (float)($grid[$base + $x - 1] ?? NAN);
            $p1 = (float)($grid[$base + $x] ?? NAN);
            $p2 = (float)($grid[$base + $x + 1] ?? NAN);
            $p3 = (float)($grid[$base + $x + 2] ?? NAN);
            if (!is_finite($p0) || !is_finite($p1) || !is_finite($p2) || !is_finite($p3)) {
                return self::linear($grid, $columns, $rows, $x, $y, $xf, $yf);
            }
            $rowValues[] = $monotone
                ? self::monotone1D($xf, $p0, $p1, $p2, $p3)
                : self::cubic1D($p0, $p1, $p2, $p3, $xf);
            if ($row === 0 || $row === 1) {
                $innerMin = min($innerMin, $p1, $p2);
                $innerMax = max($innerMax, $p1, $p2);
            }
        }

        if ($monotone) {
            return self::monotone1D($yf, $rowValues[0], $rowValues[1], $rowValues[2], $rowValues[3]);
        }
        $value = self::cubic1D($rowValues[0], $rowValues[1], $rowValues[2], $rowValues[3], $yf);
        return min(max($value, $innerMin), $innerMax);
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
        $xf = $x - $x0;
        $yf = $y - $y0;

        if ($mode === 'nearest') {
            $xi = $xf >= 0.5 ? $x0 + 1 : $x0;
            $yi = $yf >= 0.5 ? $y0 + 1 : $y0;
            $xi = max(0, min($columns - 1, $xi));
            $yi = max(0, min($rows - 1, $yi));
            return self::at($grid, $columns, $xi, $yi);
        }
        if ($mode === 'linear') return self::linear($grid, $columns, $rows, $x0, $y0, $xf, $yf);
        return self::interpolateHighOrder(
            $grid,
            $columns,
            $rows,
            $x0,
            $y0,
            $xf,
            $yf,
            $mode === 'monotone'
        );
    }
}
