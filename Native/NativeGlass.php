<?php

namespace Surface\Framebuffers\Native;

use Closure;
use Surface\Contracts\Framebuffers\Region;

/**
 * Glass in PHP, the arithmetic of ext-fb's fb_kawase_down(), fb_kawase_up() and fb_paint_glass() step for step, so
 * a native store answers the bytes an extended one does. Levels are RGBA8 strings, 4 bytes a pixel. Correct and slow:
 * the extended driver is the one to draw glass with.
 */
final class NativeGlass
{
    /** A level down of the region of $src ($sw wide): (4 x the 2x2 centre + the 4x4 block + 16) >> 5, edges held. */
    public static function down(string $src, int $sw, Region $r): string
    {
        [$dw, $dh] = [intdiv($r->width + 1, 2), intdiv($r->height + 1, 2)];
        [$right, $bottom] = [$r->x + $r->width - 1, $r->y + $r->height - 1];
        $out = '';
        for ($j = 0; $j < $dh; $j++) {
            $rows = [];
            for ($b = 0; $b < 4; $b++) {
                $rows[] = max($r->y, min($bottom, $r->y + 2 * $j - 1 + $b)) * $sw * 4;
            }
            for ($i = 0; $i < $dw; $i++) {
                $cols = [];
                for ($a = 0; $a < 4; $a++) {
                    $cols[] = max($r->x, min($right, $r->x + 2 * $i - 1 + $a)) * 4;
                }
                for ($k = 0; $k < 4; $k++) {
                    [$all, $centre] = [0, 0];
                    foreach ($rows as $b => $row) {
                        foreach ($cols as $a => $col) {
                            $v = ord($src[$row + $col + $k]);
                            $all += $v;
                            if (($a === 1 || $a === 2) && ($b === 1 || $b === 2)) {
                                $centre += $v;
                            }
                        }
                    }
                    $out .= chr((4 * $centre + $all + 16) >> 5);
                }
            }
        }

        return $out;
    }

    /** A level up, $dw x $dh from $src ($sw x $sh): the 8-tap tent in quarter pixels, (sum + 96) / 192, edges held. */
    public static function up(string $src, int $sw, int $sh, int $dw, int $dh): string
    {
        $tap = static function (int $x4, int $y4, int $k) use ($src, $sw, $sh): int {
            [$ix, $iy, $fx, $fy] = [$x4 >> 2, $y4 >> 2, $x4 & 3, $y4 & 3];
            [$x0, $x1] = [max(0, min($sw - 1, $ix)) * 4, max(0, min($sw - 1, $ix + 1)) * 4];
            [$r0, $r1] = [max(0, min($sh - 1, $iy)) * $sw * 4 + $k, max(0, min($sh - 1, $iy + 1)) * $sw * 4 + $k];

            return (4 - $fy) * ((4 - $fx) * ord($src[$r0 + $x0]) + $fx * ord($src[$r0 + $x1]))
                + $fy * ((4 - $fx) * ord($src[$r1 + $x0]) + $fx * ord($src[$r1 + $x1]));
        };
        $out = '';
        for ($y = 0; $y < $dh; $y++) {
            $y4 = 2 * $y - 1;
            for ($x = 0; $x < $dw; $x++) {
                $x4 = 2 * $x - 1;
                for ($k = 0; $k < 4; $k++) {
                    $sum = $tap($x4 - 4, $y4, $k) + $tap($x4 + 4, $y4, $k) + $tap($x4, $y4 - 4, $k) + $tap($x4, $y4 + 4, $k)
                        + 2 * ($tap($x4 - 2, $y4 - 2, $k) + $tap($x4 + 2, $y4 - 2, $k) + $tap($x4 - 2, $y4 + 2, $k) + $tap($x4 + 2, $y4 + 2, $k));
                    $out .= chr(intdiv($sum + 96, 192));
                }
            }
        }

        return $out;
    }

    /** ext-fb's fb_grain(): 0..65535 from the pixel's place. */
    public static function grain(int $x, int $y): int
    {
        $h = ($x * 374761393 + $y * 668265263) & 0xFFFFFFFF;
        $h = (($h ^ ($h >> 13)) * 1274126177) & 0xFFFFFFFF;
        $h ^= $h >> 16;

        return $h & 0xFFFF;
    }

    private static function sample(string $level, int $w, int $h, float $u, float $v, int $k): float
    {
        [$fu, $fv] = [floor($u), floor($v)];
        [$tx, $ty] = [$u - $fu, $v - $fv];
        [$x0, $x1] = [max(0, min($w - 1, (int) $fu)) * 4, max(0, min($w - 1, (int) $fu + 1)) * 4];
        [$r0, $r1] = [max(0, min($h - 1, (int) $fv)) * $w * 4 + $k, max(0, min($h - 1, (int) $fv + 1)) * $w * 4 + $k];
        [$a, $b, $c, $d] = [(float) ord($level[$r0 + $x0]), (float) ord($level[$r0 + $x1]), (float) ord($level[$r1 + $x0]), (float) ord($level[$r1 + $x1])];
        $top = $a + ($b - $a) * $tx;
        $under = $c + ($d - $c) * $tx;

        return $top + ($under - $top) * $ty;
    }

    private static function channel(float $c): int
    {
        $v = floor($c + 0.5);

        return $v < 0.0 ? 0 : ($v > 255.0 ? 255 : (int) $v);
    }

    /**
     * One pane: fb_paint_glass()'s pixels, each handed to $blend(x, y, red, green, blue, alpha).
     *
     * @param  array{float, float, float, float, array{float, float, float, float}}  $geometry  x0, y0, x1, y1, radii
     * @param  array{string, int, int}  $blur  [RGBA8 bytes, width, height]
     * @param  array{string, int, int}|null  $sharp
     * @param  list<float|int>  $look  [frost, refraction, bevel, saturation, light, grain, tint, rim, alpha]
     * @param  Closure(int, int, int, int, int, int): void  $blend
     */
    public static function pane(array $geometry, array $blur, int $scale, ?array $sharp, int $ox, int $oy, array $look, Region $clip, Closure $blend): ?Region
    {
        [$x0, $y0, $x1, $y1, $radii] = $geometry;
        [$frost, $refraction, $bevel, $saturation, $light, $grainAmount, $tintWord, $rimWord, $alpha] = $look;
        [$frost, $refraction, $bevel, $saturation, $light, $grainAmount] = [(float) $frost, (float) $refraction, (float) $bevel, (float) $saturation, (float) $light, (float) $grainAmount];
        [$cx, $cy, $hx, $hy] = [($x0 + $x1) / 2, ($y0 + $y1) / 2, ($x1 - $x0) / 2, ($y1 - $y0) / 2];
        $band = $bevel > 0 ? $bevel : ($refraction > 0 ? $refraction : 1.0);
        $tint = [(float) ($tintWord >> 24 & 0xFF), (float) ($tintWord >> 16 & 0xFF), (float) ($tintWord >> 8 & 0xFF)];
        $tintA = (float) ($tintWord & 0xFF) / 255.0;
        $rim = [(float) ($rimWord >> 24 & 0xFF), (float) ($rimWord >> 16 & 0xFF), (float) ($rimWord >> 8 & 0xFF)];
        $rimA = (float) ($rimWord & 0xFF) / 255.0;
        [$xFrom, $xTo] = [max($clip->x, (int) floor($x0)), min($clip->right(), (int) ceil($x1))];
        [$yFrom, $yTo] = [max($clip->y, (int) floor($y0)), min($clip->bottom(), (int) ceil($y1))];
        [$left, $top, $right, $bottom] = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN];

        for ($y = $yFrom; $y < $yTo; $y++) {
            $py = $y + 0.5;
            for ($x = $xFrom; $x < $xTo; $x++) {
                $px = $x + 0.5;
                $r = (float) ($px < $cx ? ($py < $cy ? $radii[0] : $radii[3]) : ($py < $cy ? $radii[1] : $radii[2]));
                $dx = abs($px - $cx) - ($hx - $r);
                $dy = abs($py - $cy) - ($hy - $r);
                if ($dx > 0 && $dy > 0) {
                    $len = sqrt($dx * $dx + $dy * $dy);
                    [$d, $nx, $ny] = [$len - $r, $dx / $len, $dy / $len];
                } elseif ($dx > $dy) {
                    [$d, $nx, $ny] = [$dx - $r, 1.0, 0.0];
                } else {
                    [$d, $nx, $ny] = [$dy - $r, 0.0, 1.0];
                }
                if ($d >= 0.5) {
                    continue;
                }
                $px < $cx && $nx = -$nx;
                $py < $cy && $ny = -$ny;
                $e = 1.0 + $d / $band;
                $e = $e < 0.0 ? 0.0 : ($e > 1.0 ? 1.0 : $e);
                $fe = $e * $e;
                [$sx, $sy] = [$px - $nx * $refraction * $fe, $py - $ny * $refraction * $fe];
                [$u, $v] = [($sx - $ox) / $scale - 0.5, ($sy - $oy) / $scale - 0.5];
                $c = [];
                for ($k = 0; $k < 3; $k++) {
                    $c[$k] = self::sample($blur[0], $blur[1], $blur[2], $u, $v, $k);
                }
                if ($sharp !== null && $frost < 1.0) {
                    [$su, $sv] = [$sx - $ox - 0.5, $sy - $oy - 0.5];
                    for ($k = 0; $k < 3; $k++) {
                        $s = self::sample($sharp[0], $sharp[1], $sharp[2], $su, $sv, $k);
                        $c[$k] = $s + ($c[$k] - $s) * $frost;
                    }
                }
                $luma = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
                $lit = 0.5 + 0.5 * ($nx * -0.6 + $ny * -0.8);
                $grain = $grainAmount * 0.04 * ((float) self::grain($x, $y) / 65536.0 - 0.5) * 255.0;
                $out = [];
                for ($k = 0; $k < 3; $k++) {
                    $ck = $luma + ($c[$k] - $luma) * $saturation;
                    $ck = $ck + ($tint[$k] - $ck) * $tintA;
                    $ck = $ck + $light * 255.0 + $rim[$k] * $rimA * $fe * $lit + $grain;
                    $out[$k] = self::channel($ck);
                }
                $cover = 0.5 - $d;
                $cover = $cover > 1.0 ? 1.0 : $cover;
                $a = (int) floor($cover * $alpha + 0.5);
                if ($a <= 0) {
                    continue;
                }
                $blend($x, $y, $out[0], $out[1], $out[2], $a);
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x + 1), max($bottom, $y + 1)];
            }
        }

        return $right === PHP_INT_MIN ? null : new Region($left, $top, $right - $left, $bottom - $top);
    }
}
