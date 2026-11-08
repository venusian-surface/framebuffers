<?php

namespace Surface\Framebuffers\Native;

use Surface\Contracts\Drawing\Effects\Blend;
use Surface\Contracts\Drawing\Effects\BlendMode;
use Surface\Contracts\Drawing\Effects\Blur;
use Surface\Contracts\Drawing\Effects\BlurSampling;
use Surface\Contracts\Drawing\Effects\Channel;
use Surface\Contracts\Drawing\Effects\ColorMatrix;
use Surface\Contracts\Drawing\Effects\Composite;
use Surface\Contracts\Drawing\Effects\CompositeOperator;
use Surface\Contracts\Drawing\Effects\Displace;
use Surface\Contracts\Drawing\Effects\EdgeMode;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Drawing\Effects\FilterStep;
use Surface\Contracts\Drawing\Effects\Field;
use Surface\Contracts\Drawing\Effects\FieldKind;
use Surface\Contracts\Drawing\Effects\Flood;
use Surface\Contracts\Drawing\Effects\Noise;
use Surface\Contracts\Drawing\Effects\NoiseType;
use Surface\Contracts\Drawing\Effects\Table;
use Surface\NutsAndBolts\Color;

/**
 * Runs a FilterChain over pixels, in PHP: the native store's side of Framebuffer::filter() (ext-fb has the same in C), the reference every engine is held to. An image is a flat list of premultiplied
 * RGBA8 values (4 per pixel, row by row): a blur, a composite and a displacement are exact there, as on a GPU texture;
 * a colour matrix and a noise work on the straight colour and premultiply again. Every step rounds to nearest, halves up.
 */
final class NativeFilters
{
    /** Turbulence's lattice, per seed: the reference code of the SVG specification. */
    private static array $lattices = [];

    /**
     * Runs a chain over the pixels of a region. The pixels are straight RGBA8 in, straight RGBA8 out; inside, every step
     * works on premultiplied values.
     *
     * @param  string  $rgba8  $width x $height straight RGBA8 pixels, the chain's `source`.
     * @param  bool  $premultiplied  The pixels in are premultiplied already (a layer's): they are taken as they are.
     * @param  int  $originX  Where the region's top left lies on the surface: noise is a function of the surface's position.
     * @return string The chain's last result, straight RGBA8, the same size.
     */
    public static function run(FilterChain $chain, string $rgba8, int $width, int $height, int $originX = 0, int $originY = 0, bool $premultiplied = false): string
    {
        $source = [];
        for ($i = 0, $n = $width * $height * 4; $i < $n; $i += 4) {
            $a = ord($rgba8[$i + 3]);
            array_push($source, $premultiplied ? ord($rgba8[$i]) : self::premultiply(ord($rgba8[$i]), $a), $premultiplied ? ord($rgba8[$i + 1]) : self::premultiply(ord($rgba8[$i + 1]), $a), $premultiplied ? ord($rgba8[$i + 2]) : self::premultiply(ord($rgba8[$i + 2]), $a), $a);
        }
        $results = [FilterStep::SOURCE => $source];
        $previous = $source;
        foreach ($chain->steps as $step) {
            $input = fn (?string $name): array => $name === null || $name === '' ? $previous : $results[$name];
            $made = match (true) {
                $step instanceof Blur => self::blur($input($step->in), $width, $height, $step),
                $step instanceof ColorMatrix => self::colorMatrix($input($step->in), $step->values),
                $step instanceof Flood => self::flood($step->color, $width, $height),
                $step instanceof Composite => self::composite($input($step->in), $input($step->in2), $step),
                $step instanceof Blend => self::blend($input($step->in), $input($step->in2), $step->mode),
                $step instanceof Noise => self::noise($step, $width, $height, $originX, $originY),
                $step instanceof Displace => self::displace($input($step->in), $width, $height, $step, is_string($step->map) ? $results[$step->map] : []),
                $step instanceof Field => self::field($step, $width, $height, $originX, $originY),
                $step instanceof Table => self::table($input($step->in), $step),
            };
            $previous = $made;
            if (($name = $step->named()) !== null) {
                $results[$name] = $made;
            }
        }
        $out = '';
        for ($i = 0, $n = $width * $height * 4; $i < $n; $i += 4) {
            $a = $previous[$i + 3];
            if ($a === 0) {
                $out .= "\0\0\0\0";

                continue;
            }
            $out .= chr(min(255, intdiv($previous[$i] * 255 + intdiv($a, 2), $a))).chr(min(255, intdiv($previous[$i + 1] * 255 + intdiv($a, 2), $a))).chr(min(255, intdiv($previous[$i + 2] * 255 + intdiv($a, 2), $a))).chr($a);
        }

        return $out;
    }

    /** @param list<int> $image */
    private static function blur(array $image, int $width, int $height, Blur $blur): array
    {
        if ($blur->sampling === BlurSampling::SPIRAL) {
            return $blur->x <= 0.0 ? $image : self::spiral($image, $width, $height, $blur->x, $blur->samples, $blur->edge);
        }
        $image = self::gaussianAxis($image, $width, $height, true, $blur->x, $blur->edge);

        return self::gaussianAxis($image, $width, $height, false, $blur->y, $blur->edge);
    }

    /**
     * SVG's rule: from a standard deviation of 2, three box blurs of size d = floor(s × 3 × √(2π) / 4 + ½): odd d, three
     * centred; even d, two of size d (the first centred on the boundary left of the pixel, the second on the boundary
     * right of it) and one of d + 1 centred. Below 2, a Gaussian kernel of integer weights that sum to 256.
     *
     * @param  list<int>  $image
     * @return list<int>
     */
    private static function gaussianAxis(array $image, int $width, int $height, bool $horizontal, float $sigma, EdgeMode $edge): array
    {
        if ($sigma <= 0.0) {
            return $image;
        }
        if ($sigma < 2.0) {
            return self::kernelAxis($image, $width, $height, $horizontal, self::kernel($sigma), $edge);
        }
        foreach (self::boxes($sigma) as [$size, $left]) {
            $image = self::boxAxis($image, $width, $height, $horizontal, $size, $left, $edge);
        }

        return $image;
    }

    /**
     * The three box blurs of a deviation of 2 or more: [size, left] each, left being how many pixels of the window lie before the pixel.
     *
     * @return list<array{int, int}>
     */
    public static function boxes(float $sigma): array
    {
        $d = (int) floor($sigma * 3 * sqrt(2 * M_PI) / 4 + 0.5);

        return $d % 2 === 1
            ? [[$d, intdiv($d - 1, 2)], [$d, intdiv($d - 1, 2)], [$d, intdiv($d - 1, 2)]]
            : [[$d, intdiv($d, 2)], [$d, intdiv($d, 2) - 1], [$d + 1, intdiv($d, 2)]];
    }

    /** @return list<int> Weights for offsets -R..R, summing to 256, for a deviation below 2. */
    public static function kernelWeights(float $sigma): array
    {
        return self::kernel($sigma);
    }

    /**
     * The sample offsets of a spiral blur: point i of N at radius √((i + ½) / N) × $radius, angle i × the golden angle.
     *
     * @return list<array{float, float}>
     */
    public static function spiralOffsets(float $radius, int $samples): array
    {
        $offsets = [];
        for ($i = 0; $i < $samples; $i++) {
            $reach = sqrt(($i + 0.5) / $samples) * $radius;
            $offsets[] = [cos($i * 2.399963229728653) * $reach, sin($i * 2.399963229728653) * $reach];
        }

        return $offsets;
    }

    /**
     * The frequencies a stitched noise uses over a tile: each moved to the whole number of periods the tile holds that is nearer.
     *
     * @return array{float, float}
     */
    public static function stitched(Noise $noise, int $tileWidth, int $tileHeight): array
    {
        $frequencyX = $noise->frequencyX;
        $frequencyY = $noise->frequencyY;
        if ($noise->stitch) {
            if ($frequencyX != 0.0) {
                $low = floor($tileWidth * $frequencyX) / $tileWidth;
                $high = ceil($tileWidth * $frequencyX) / $tileWidth;
                $frequencyX = $low > 0.0 && $frequencyX / $low < $high / $frequencyX ? $low : $high;
            }
            if ($frequencyY != 0.0) {
                $low = floor($tileHeight * $frequencyY) / $tileHeight;
                $high = ceil($tileHeight * $frequencyY) / $tileHeight;
                $frequencyY = $low > 0.0 && $frequencyY / $low < $high / $frequencyY ? $low : $high;
            }
        }

        return [$frequencyX, $frequencyY];
    }

    /** @return list<int> Weights for offsets -R..R, summing to 256. */
    private static function kernel(float $sigma): array
    {
        $radius = (int) ceil(3 * $sigma);
        $raw = [];
        for ($i = -$radius; $i <= $radius; $i++) {
            $raw[] = exp(-($i * $i) / (2 * $sigma * $sigma));
        }
        $total = array_sum($raw);
        $weights = array_map(fn (float $g): int => (int) floor(256 * $g / $total + 0.5), $raw);
        $weights[$radius] += 256 - array_sum($weights);

        return $weights;
    }

    /**
     * @param  list<int>  $image
     * @param  list<int>  $weights
     * @return list<int>
     */
    private static function kernelAxis(array $image, int $width, int $height, bool $horizontal, array $weights, EdgeMode $edge): array
    {
        $radius = intdiv(count($weights) - 1, 2);
        $length = $horizontal ? $width : $height;
        $lines = $horizontal ? $height : $width;
        $out = $image;
        for ($line = 0; $line < $lines; $line++) {
            for ($channel = 0; $channel < 4; $channel++) {
                for ($i = 0; $i < $length; $i++) {
                    $sum = 0;
                    foreach ($weights as $k => $w) {
                        $j = $i + $k - $radius;
                        if ($j < 0 || $j >= $length) {
                            if ($edge === EdgeMode::NONE) {
                                continue;
                            }
                            $j = $j < 0 ? 0 : $length - 1;
                        }
                        $sum += $w * $image[self::at($horizontal, $line, $j, $width) + $channel];
                    }
                    $out[self::at($horizontal, $line, $i, $width) + $channel] = ($sum + 128) >> 8;
                }
            }
        }

        return $out;
    }

    /**
     * One box blur of $size along an axis, the window of output pixel i from i − $left to i − $left + $size − 1,
     * the mean rounded to nearest.
     *
     * @param  list<int>  $image
     * @return list<int>
     */
    private static function boxAxis(array $image, int $width, int $height, bool $horizontal, int $size, int $left, EdgeMode $edge): array
    {
        $length = $horizontal ? $width : $height;
        $lines = $horizontal ? $height : $width;
        $half = intdiv($size, 2);
        $out = $image;
        for ($line = 0; $line < $lines; $line++) {
            for ($channel = 0; $channel < 4; $channel++) {
                $value = function (int $j) use ($image, $horizontal, $line, $width, $length, $channel, $edge): int {
                    if ($j < 0 || $j >= $length) {
                        if ($edge === EdgeMode::NONE) {
                            return 0;
                        }
                        $j = $j < 0 ? 0 : $length - 1;
                    }

                    return $image[self::at($horizontal, $line, $j, $width) + $channel];
                };
                $sum = 0;
                for ($j = -$left; $j <= -$left + $size - 1; $j++) {
                    $sum += $value($j);
                }
                for ($i = 0; $i < $length; $i++) {
                    $out[self::at($horizontal, $line, $i, $width) + $channel] = intdiv($sum + $half, $size);
                    $sum += $value($i - $left + $size) - $value($i - $left);
                }
            }
        }

        return $out;
    }

    private static function at(bool $horizontal, int $line, int $i, int $width): int
    {
        return ($horizontal ? $line * $width + $i : $i * $width + $line) * 4;
    }

    /**
     * $samples points on a golden-angle spiral (point i of N at radius √((i + ½) / N) × $radius, angle i × 2.39996…), each
     * read with bilinear weights of 1/256, averaged.
     *
     * @param  list<int>  $image
     * @return list<int>
     */
    private static function spiral(array $image, int $width, int $height, float $radius, int $samples, EdgeMode $edge): array
    {
        $offsets = self::spiralOffsets($radius, $samples);
        $out = $image;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $sum = [0, 0, 0, 0];
                foreach ($offsets as [$dx, $dy]) {
                    $texel = self::bilinear($image, $width, $height, $x + 0.5 + $dx, $y + 0.5 + $dy, $edge);
                    $sum[0] += $texel[0];
                    $sum[1] += $texel[1];
                    $sum[2] += $texel[2];
                    $sum[3] += $texel[3];
                }
                $at = ($y * $width + $x) * 4;
                for ($c = 0; $c < 4; $c++) {
                    $out[$at + $c] = intdiv($sum[$c] + intdiv($samples, 2), $samples);
                }
            }
        }

        return $out;
    }

    /**
     * The four pixels around a point (u, v), weights in 1/256ths, premultiplied values, rounded to nearest.
     *
     * @param  list<int>  $image
     * @return array{int, int, int, int}
     */
    private static function bilinear(array $image, int $width, int $height, float $u, float $v, EdgeMode $edge): array
    {
        // far enough to be off any image, and a number a cast can hold
        $fx = max(-1.0e9, min(1.0e9, $u - 0.5));
        $fy = max(-1.0e9, min(1.0e9, $v - 0.5));
        $x0 = (int) floor($fx);
        $y0 = (int) floor($fy);
        $tx = (int) floor(($fx - $x0) * 256);
        $ty = (int) floor(($fy - $y0) * 256);
        $out = [0, 0, 0, 0];
        foreach ([[$y0, $x0, (256 - $tx) * (256 - $ty)], [$y0, $x0 + 1, $tx * (256 - $ty)], [$y0 + 1, $x0, (256 - $tx) * $ty], [$y0 + 1, $x0 + 1, $tx * $ty]] as [$y, $x, $weight]) {
            if ($weight === 0) {
                continue;
            }
            if ($x < 0 || $x >= $width || $y < 0 || $y >= $height) {
                if ($edge === EdgeMode::NONE) {
                    continue;
                }
                $x = max(0, min($width - 1, $x));
                $y = max(0, min($height - 1, $y));
            }
            $at = ($y * $width + $x) * 4;
            $out[0] += $weight * $image[$at];
            $out[1] += $weight * $image[$at + 1];
            $out[2] += $weight * $image[$at + 2];
            $out[3] += $weight * $image[$at + 3];
        }

        return [($out[0] + 32768) >> 16, ($out[1] + 32768) >> 16, ($out[2] + 32768) >> 16, ($out[3] + 32768) >> 16];
    }

    /**
     * @param  list<int>  $image
     * @param  list<float>  $m
     * @return list<int>
     */
    private static function colorMatrix(array $image, array $m): array
    {
        $out = $image;
        for ($at = 0, $n = count($image); $at < $n; $at += 4) {
            $a = $image[$at + 3];
            if ($a === 0) {
                [$r, $g, $b] = [0.0, 0.0, 0.0];
            } else {
                $r = min(255, intdiv($image[$at] * 255 + intdiv($a, 2), $a)) / 255;
                $g = min(255, intdiv($image[$at + 1] * 255 + intdiv($a, 2), $a)) / 255;
                $b = min(255, intdiv($image[$at + 2] * 255 + intdiv($a, 2), $a)) / 255;
            }
            $alpha = $a / 255;
            $nr = max(0.0, min(1.0, $m[0] * $r + $m[1] * $g + $m[2] * $b + $m[3] * $alpha + $m[4]));
            $ng = max(0.0, min(1.0, $m[5] * $r + $m[6] * $g + $m[7] * $b + $m[8] * $alpha + $m[9]));
            $nb = max(0.0, min(1.0, $m[10] * $r + $m[11] * $g + $m[12] * $b + $m[13] * $alpha + $m[14]));
            $na = max(0.0, min(1.0, $m[15] * $r + $m[16] * $g + $m[17] * $b + $m[18] * $alpha + $m[19]));
            $out[$at] = (int) floor($nr * $na * 255 + 0.5);
            $out[$at + 1] = (int) floor($ng * $na * 255 + 0.5);
            $out[$at + 2] = (int) floor($nb * $na * 255 + 0.5);
            $out[$at + 3] = (int) floor($na * 255 + 0.5);
        }

        return $out;
    }

    /**
     * Raven's sd_round_box_normal (ui.frag:42-57) at a point: the distance to the rounded box (negative inside) and the
     * direction pointing out of it. $box is [x0, y0, x1, y1], $radii [top left, top right, bottom right, bottom left].
     *
     * @param  array{float, float, float, float}  $box
     * @param  array{float, float, float, float}  $radii
     * @return array{float, float, float}
     */
    public static function boxDistance(float $px, float $py, array $box, array $radii): array
    {
        [$x0, $y0, $x1, $y1] = $box;
        $hx = ($x1 - $x0) / 2;
        $hy = ($y1 - $y0) / 2;
        $p = [$px - ($x0 + $x1) / 2, $py - ($y0 + $y1) / 2];
        $radius = $p[0] < 0.0 ? ($p[1] < 0.0 ? $radii[0] : $radii[3]) : ($p[1] < 0.0 ? $radii[1] : $radii[2]);
        $radius = min($radius, $hx, $hy);
        $sideX = $p[0] < 0.0 ? -1.0 : 1.0;
        $sideY = $p[1] < 0.0 ? -1.0 : 1.0;
        $qx = abs($p[0]) - $hx + $radius;
        $qy = abs($p[1]) - $hy + $radius;
        $corner = hypot(max($qx, 0.0), max($qy, 0.0));
        if ($qx > 0.0 && $qy > 0.0) {
            $nx = $sideX * ($qx / max($corner, 1e-4));
            $ny = $sideY * ($qy / max($corner, 1e-4));
        } elseif ($qx > $qy) {
            [$nx, $ny] = [$sideX, 0.0];
        } else {
            [$nx, $ny] = [0.0, $sideY];
        }

        return [min(max($qx, $qy), 0.0) + $corner - $radius, $nx, $ny];
    }

    private static function unitByte(float $v): int
    {
        return (int) floor(max(0.0, min(1.0, $v)) * 255 + 0.5);
    }

    /** @return list<int> */
    private static function field(Field $step, int $width, int $height, int $originX, int $originY): array
    {
        $out = [];
        $span = $step->to - $step->from;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $px = $x + $originX + 0.5;
                $py = $y + $originY + 0.5;
                if ($step->kind === FieldKind::RAMP) {
                    $v = self::unitByte(($py - $step->from) / $span);
                    array_push($out, $v, $v, $v, 255);

                    continue;
                }
                [$d, $nx, $ny] = self::boxDistance($px, $py, $step->box, $step->radii);
                if ($step->kind === FieldKind::INSIDE) {
                    $v = $d < 0.0 ? 255 : 0;
                    array_push($out, $v, $v, $v, 255);

                    continue;
                }
                array_push($out, self::unitByte(($d - $step->from) / $span), self::unitByte(0.5 + 0.5 * $nx), self::unitByte(0.5 + 0.5 * $ny), 255);
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $image
     * @return list<int>
     */
    private static function table(array $image, Table $step): array
    {
        $out = $image;
        for ($at = 0, $n = count($image); $at < $n; $at += 4) {
            $a = $image[$at + 3];
            $straight = [0, 0, 0];
            if ($a !== 0) {
                for ($c = 0; $c < 3; $c++) {
                    $straight[$c] = min(255, intdiv($image[$at + $c] * 255 + intdiv($a, 2), $a));
                }
            }
            $na = $step->alpha === null ? $a : $step->alpha[$a];
            foreach ([$step->red, $step->green, $step->blue] as $c => $table) {
                $out[$at + $c] = self::premultiply($table === null ? $straight[$c] : $table[$straight[$c]], $na);
            }
            $out[$at + 3] = $na;
        }

        return $out;
    }

    /** @return list<int> */
    private static function flood(Color $color, int $width, int $height): array
    {
        $a = (int) round($color->alpha * 255);
        $pixel = [self::premultiply((int) round($color->red * 255), $a), self::premultiply((int) round($color->green * 255), $a), self::premultiply((int) round($color->blue * 255), $a), $a];

        return array_merge(...array_fill(0, $width * $height, $pixel));
    }

    private static function premultiply(int $channel, int $alpha): int
    {
        return intdiv($channel * $alpha + 127, 255);
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     * @return list<int>
     */
    private static function composite(array $a, array $b, Composite $step): array
    {
        $out = $a;
        for ($at = 0, $n = count($a); $at < $n; $at += 4) {
            $aa = $a[$at + 3] / 255;
            $ab = $b[$at + 3] / 255;
            $pixel = [];
            for ($c = 0; $c < 4; $c++) {
                $x = $a[$at + $c] / 255;
                $y = $b[$at + $c] / 255;
                $pixel[] = match ($step->operator) {
                    CompositeOperator::OVER => $x + $y * (1 - $aa),
                    CompositeOperator::IN => $x * $ab,
                    CompositeOperator::OUT => $x * (1 - $ab),
                    CompositeOperator::ATOP => $x * $ab + $y * (1 - $aa),
                    CompositeOperator::XOR => $x * (1 - $ab) + $y * (1 - $aa),
                    CompositeOperator::ARITHMETIC => $step->k1 * $x * $y + $step->k2 * $x + $step->k3 * $y + $step->k4,
                };
            }
            self::store($out, $at, $pixel);
        }

        return $out;
    }

    /**
     * @param  list<int>  $a  On top.
     * @param  list<int>  $b  Below.
     * @return list<int>
     */
    private static function blend(array $a, array $b, BlendMode $mode): array
    {
        $out = $a;
        for ($at = 0, $n = count($a); $at < $n; $at += 4) {
            $qa = $a[$at + 3] / 255;
            $qb = $b[$at + 3] / 255;
            $pixel = [];
            for ($c = 0; $c < 3; $c++) {
                $ca = $a[$at + $c] / 255;
                $cb = $b[$at + $c] / 255;
                $pixel[] = match ($mode) {
                    BlendMode::NORMAL => (1 - $qa) * $cb + $ca,
                    BlendMode::MULTIPLY => (1 - $qa) * $cb + (1 - $qb) * $ca + $ca * $cb,
                    BlendMode::SCREEN => $cb + $ca - $ca * $cb,
                    BlendMode::DARKEN => min((1 - $qa) * $cb + $ca, (1 - $qb) * $ca + $cb),
                    BlendMode::LIGHTEN => max((1 - $qa) * $cb + $ca, (1 - $qb) * $ca + $cb),
                };
            }
            $pixel[] = 1 - (1 - $qa) * (1 - $qb);
            self::store($out, $at, $pixel);
        }

        return $out;
    }

    /**
     * A premultiplied pixel of 0..1 floats into the list: clamped, the colours held to the alpha, rounded to nearest.
     *
     * @param  list<int>  $out
     * @param  array{float, float, float, float}  $pixel
     */
    private static function store(array &$out, int $at, array $pixel): void
    {
        $alpha = (int) floor(max(0.0, min(1.0, $pixel[3])) * 255 + 0.5);
        for ($c = 0; $c < 3; $c++) {
            $out[$at + $c] = min($alpha, (int) floor(max(0.0, min(1.0, $pixel[$c])) * 255 + 0.5));
        }
        $out[$at + 3] = $alpha;
    }

    /** @return list<int> */
    private static function noise(Noise $noise, int $width, int $height, int $originX, int $originY): array
    {
        $out = [];
        if ($noise->type === NoiseType::HASH) {
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $h = self::hash($x + $originX, $y + $originY, $noise->seed) & 0xFF;
                    array_push($out, $h, $h, $h, 255);
                }
            }

            return $out;
        }
        $lattice = self::lattice($noise->seed);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $straight = [];
                for ($channel = 0; $channel < 4; $channel++) {
                    $sum = self::turbulence($lattice, $channel, $x + $originX, $y + $originY, $noise, $originX, $originY, $width, $height);
                    $value = $noise->type === NoiseType::FRACTAL ? ($sum * 255 + 255) / 2 : $sum * 255;
                    $straight[] = (int) max(0, min(255, (int) floor($value + 0.5)));
                }
                $a = $straight[3];
                array_push($out, self::premultiply($straight[0], $a), self::premultiply($straight[1], $a), self::premultiply($straight[2], $a), $a);
            }
        }

        return $out;
    }

    /** One pseudo-random byte from a pixel's position and a seed: a 32-bit integer hash, the same on every engine. */
    public static function hash(int $x, int $y, int $seed): int
    {
        $h = ($x * 374761393 + $y * 668265263 + $seed * 2246822519) & 0xFFFFFFFF;
        $h = (($h ^ ($h >> 13)) * 1274126177) & 0xFFFFFFFF;

        return ($h ^ ($h >> 16)) & 0xFFFFFFFF;
    }

    /**
     * The turbulence lattice of a seed as bytes for a GPU: 514 selector entries (uint16 LE), then the gradients of the four
     * channels, 514 pairs each (float32 LE x, y).
     */
    public static function noiseTables(int $seed): string
    {
        [$selector, $gradient] = self::lattice($seed);
        $bytes = pack('v*', ...$selector);
        for ($k = 0; $k < 4; $k++) {
            for ($i = 0; $i < 514; $i++) {
                $bytes .= pack('g2', $gradient[$k][$i][0], $gradient[$k][$i][1]);
            }
        }

        return $bytes;
    }

    /** SVG's init(): the lattice selector and the gradients of the four channels, from the seed. @return array{list<int>, array<int, array<int, array{float, float}>>} */
    private static function lattice(int $seed): array
    {
        if (isset(self::$lattices[$seed])) {
            return self::$lattices[$seed];
        }
        $size = 0x100;
        $seedValue = $seed;
        if ($seedValue <= 0) {
            $seedValue = -($seedValue % (2147483647 - 1)) + 1;
        }
        if ($seedValue > 2147483647 - 1) {
            $seedValue = 2147483647 - 1;
        }
        $random = function () use (&$seedValue): int {
            $result = 16807 * ($seedValue % 127773) - 2836 * intdiv($seedValue, 127773);
            if ($result <= 0) {
                $result += 2147483647;
            }

            return $seedValue = $result;
        };
        $selector = [];
        $gradient = [];
        for ($k = 0; $k < 4; $k++) {
            for ($i = 0; $i < $size; $i++) {
                $selector[$i] = $i;
                $pair = [];
                for ($j = 0; $j < 2; $j++) {
                    $pair[] = (($random() % ($size + $size)) - $size) / $size;
                }
                $length = sqrt($pair[0] * $pair[0] + $pair[1] * $pair[1]);
                // a zero vector (one seed in 262144 per entry) stays zero: the reference code would divide by nothing
                $gradient[$k][$i] = $length === 0.0 ? [0.0, 0.0] : [$pair[0] / $length, $pair[1] / $length];
            }
        }
        for ($i = $size - 1; $i > 0; $i--) {
            $k = $selector[$i];
            $j = $random() % $size;
            $selector[$i] = $selector[$j];
            $selector[$j] = $k;
        }
        for ($i = 0; $i < $size + 2; $i++) {
            $selector[$size + $i] = $selector[$i];
            for ($k = 0; $k < 4; $k++) {
                $gradient[$k][$size + $i] = $gradient[$k][$i];
            }
        }

        return self::$lattices[$seed] = [$selector, $gradient];
    }

    /** @param array{list<int>, array<int, array<int, array{float, float}>>} $lattice */
    private static function turbulence(array $lattice, int $channel, int $px, int $py, Noise $noise, int $tileX, int $tileY, int $tileWidth, int $tileHeight): float
    {
        [$frequencyX, $frequencyY] = self::stitched($noise, $tileWidth, $tileHeight);
        $stitch = null;
        if ($noise->stitch) {
            $width = (int) ($tileWidth * $frequencyX + 0.5);
            $height = (int) ($tileHeight * $frequencyY + 0.5);
            $stitch = [$width, $height, (int) ($tileX * $frequencyX + 4096 + $width), (int) ($tileY * $frequencyY + 4096 + $height)];
        }
        $sum = 0.0;
        $vx = $px * $frequencyX;
        $vy = $py * $frequencyY;
        $ratio = 1.0;
        for ($octave = 0; $octave < $noise->octaves; $octave++) {
            $n = self::noise2($lattice, $channel, $vx, $vy, $stitch);
            $sum += ($noise->type === NoiseType::FRACTAL ? $n : abs($n)) / $ratio;
            $vx *= 2;
            $vy *= 2;
            $ratio *= 2;
            if ($stitch !== null) {
                $stitch = [$stitch[0] * 2, $stitch[1] * 2, 2 * $stitch[2] - 4096, 2 * $stitch[3] - 4096];
            }
        }

        return $sum;
    }

    /**
     * @param  array{list<int>, array<int, array<int, array{float, float}>>}  $lattice
     * @param  array{int, int, int, int}|null  $stitch  width, height, wrapX, wrapY
     */
    private static function noise2(array $lattice, int $channel, float $x, float $y, ?array $stitch): float
    {
        [$selector, $gradient] = $lattice;
        $t = $x + 4096;
        $bx0 = ((int) $t) & 0xFF;
        $bx1 = ($bx0 + 1) & 0xFF;
        $rx0 = $t - (int) $t;
        $rx1 = $rx0 - 1.0;
        $t = $y + 4096;
        $by0 = ((int) $t) & 0xFF;
        $by1 = ($by0 + 1) & 0xFF;
        $ry0 = $t - (int) $t;
        $ry1 = $ry0 - 1.0;
        if ($stitch !== null) {
            if ($bx0 >= $stitch[2]) {
                $bx0 -= $stitch[0];
            }
            if ($bx1 >= $stitch[2]) {
                $bx1 -= $stitch[0];
            }
            if ($by0 >= $stitch[3]) {
                $by0 -= $stitch[1];
            }
            if ($by1 >= $stitch[3]) {
                $by1 -= $stitch[1];
            }
        }
        $bx0 &= 0xFF;
        $bx1 &= 0xFF;
        $by0 &= 0xFF;
        $by1 &= 0xFF;
        $i = $selector[$bx0];
        $j = $selector[$bx1];
        $b00 = $selector[$i + $by0];
        $b10 = $selector[$j + $by0];
        $b01 = $selector[$i + $by1];
        $b11 = $selector[$j + $by1];
        $sx = $rx0 * $rx0 * (3.0 - 2.0 * $rx0);
        $sy = $ry0 * $ry0 * (3.0 - 2.0 * $ry0);
        $g = $gradient[$channel];
        $u = $rx0 * $g[$b00][0] + $ry0 * $g[$b00][1];
        $v = $rx1 * $g[$b10][0] + $ry0 * $g[$b10][1];
        $a = $u + $sx * ($v - $u);
        $u = $rx0 * $g[$b01][0] + $ry1 * $g[$b01][1];
        $v = $rx1 * $g[$b11][0] + $ry1 * $g[$b11][1];
        $b = $u + $sx * ($v - $u);

        return $a + $sy * ($b - $a);
    }

    /**
     * @param  list<int>  $image
     * @return list<int>
     */
    private static function displace(array $image, int $width, int $height, Displace $step, array $mapPixels = []): array
    {
        if (is_string($step->map)) {
            // a map an earlier step made: premultiplied there, straight here, the size of the layer
            [$mapWidth, $mapHeight] = [$width, $height];
            $map = '';
            for ($at = 0, $n = count($mapPixels); $at < $n; $at += 4) {
                $a = $mapPixels[$at + 3];
                $map .= $a === 0 ? "\0\0\0\0" : chr(min(255, intdiv($mapPixels[$at] * 255 + intdiv($a, 2), $a))).chr(min(255, intdiv($mapPixels[$at + 1] * 255 + intdiv($a, 2), $a))).chr(min(255, intdiv($mapPixels[$at + 2] * 255 + intdiv($a, 2), $a))).chr($a);
            }
        } else {
            $map = $step->map->toRgba8();
            $mapWidth = $step->map->viewportWidth();
            $mapHeight = $step->map->viewportHeight();
        }
        $bands = $step->bands;
        $weights = $step->weights ?? self::bandWeights($bands);
        $channel = fn (Channel $c): int => match ($c) {
            Channel::R => 0,
            Channel::G => 1,
            Channel::B => 2,
            Channel::A => 3,
        };
        $xc = $channel($step->xChannel);
        $yc = $channel($step->yChannel);
        $out = $image;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $dx = $dy = 0.0;
                if ($x < $mapWidth && $y < $mapHeight) {
                    $at = ($y * $mapWidth + $x) * 4;
                    // a framebuffer's pixels are straight RGBA8
                    $straight = fn (int $c): float => ord($map[$at + $c]) / 255;
                    $dx = $step->scale * ($straight($xc) - 0.5);
                    $dy = $step->scale * ($straight($yc) - 0.5);
                }
                $pixel = ($y * $width + $x) * 4;
                if ($bands === 1) {
                    $t = self::bilinear($image, $width, $height, $x + 0.5 + $dx, $y + 0.5 + $dy, EdgeMode::NONE);
                    array_splice($out, $pixel, 4, $t);

                    continue;
                }
                $rgb = [0.0, 0.0, 0.0];
                for ($i = 0; $i < $bands; $i++) {
                    $k = $i / ($bands - 1) * 2 - 1;
                    $f = 1 + $step->spread * $k;
                    $t = self::bilinear($image, $width, $height, $x + 0.5 + $dx * $f, $y + 0.5 + $dy * $f, EdgeMode::NONE);
                    $rgb[0] += $t[0] * $weights[$i][0];
                    $rgb[1] += $t[1] * $weights[$i][1];
                    $rgb[2] += $t[2] * $weights[$i][2];
                }
                $middle = self::bilinear($image, $width, $height, $x + 0.5 + $dx, $y + 0.5 + $dy, EdgeMode::NONE);
                $alpha = $middle[3];
                $out[$pixel] = min($alpha, (int) floor($rgb[0] + 0.5));
                $out[$pixel + 1] = min($alpha, (int) floor($rgb[1] + 0.5));
                $out[$pixel + 2] = min($alpha, (int) floor($rgb[2] + 0.5));
                $out[$pixel + 3] = $alpha;
            }
        }

        return $out;
    }

    /**
     * Default weights for $bands bands: red, green and blue lobes at 0.15, 0.5 and 0.85 along the spectrum, each channel
     * normalised to sum to 1; with three bands, the channels themselves.
     *
     * @return list<array{float, float, float}>
     */
    public static function bandWeights(int $bands): array
    {
        if ($bands === 3) {
            return [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]];
        }
        $raw = [];
        $sums = [0.0, 0.0, 0.0];
        for ($i = 0; $i < $bands; $i++) {
            $position = $bands === 1 ? 0.5 : $i / ($bands - 1);
            $triple = [exp(-(($position - 0.15) ** 2) / 0.05), exp(-(($position - 0.5) ** 2) / 0.05), exp(-(($position - 0.85) ** 2) / 0.05)];
            $raw[] = $triple;
            foreach ($triple as $c => $w) {
                $sums[$c] += $w;
            }
        }

        return array_map(fn (array $triple): array => [$triple[0] / $sums[0], $triple[1] / $sums[1], $triple[2] / $sums[2]], $raw);
    }
}
