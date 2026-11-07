<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\Region;
use Surface\NutsAndBolts\Affine;

/**
 * Where a placed image lands and how to read it back: worked out once, in PHP,
 * for every store, so both flavors paint the same pixels from the same numbers.
 */
final class ImagePlacement
{
    /**
     * @param  int  $top  Rows the source's bytes start below its own origin (a paged source's current page).
     * @param  Region  $bounds  The rows and columns that exist to paint: the surface, or a paged buffer's current page.
     * @param  array{float, float, float, float}|null  $window  [u0, v0, u1, v1] in the placement's source coordinates (a paged source's rows offset by $top): the part that paints.
     * @return array{Region, array{float, float, float, float, float, float}, array{float, float, float, float}|null}|null The target rect inside $bounds
     *                                                                             and $clip (and the window's footprint), the inverse that maps a target point to a point of the source's bytes,
     *                                                                             and the window in those bytes' coordinates, held to the image (null: all of it); null when nothing lands.
     *
     * @throws FramebufferException When the opacity is outside 0..255, or the window is not four finite numbers with u0 < u1 and v0 < v1.
     */
    public static function plan(Affine $placement, int $width, int $height, int $top, Region $bounds, ?Region $clip, int $opacity, ?array $window = null): ?array
    {
        if ($opacity < 0 || $opacity > 255) {
            throw new FramebufferException("paintImage() takes an opacity 0..255, got {$opacity}.");
        }

        $held = null;
        $corners = [[0, $top], [$width, $top], [$width, $top + $height], [0, $top + $height]];
        if (! is_null($window)) {
            if (count($window) !== 4 || array_filter($window, fn (mixed $n): bool => (is_int($n) || is_float($n)) && is_finite($n)) !== $window || ! ($window[0] < $window[2] && $window[1] < $window[3])) {
                throw new FramebufferException('paintImage() takes a window of four finite numbers [u0, v0, u1, v1] with u0 < u1 and v0 < v1.');
            }
            [$u0, $v0, $u1, $v1] = [max(0.0, (float) $window[0]), max((float) $top, (float) $window[1]), min((float) $width, (float) $window[2]), min((float) ($top + $height), (float) $window[3])];
            if (! ($u0 < $u1 && $v0 < $v1)) {
                return null;
            }
            $corners = [[$u0, $v0], [$u1, $v0], [$u1, $v1], [$u0, $v1]];
            $held = [$u0, $v0 - $top, $u1, $v1 - $top];
        }

        $left = $up = INF;
        $right = $down = -INF;
        foreach ($corners as [$u, $v]) {
            [$x, $y] = $placement->apply((float) $u, (float) $v);
            $left = min($left, $x);
            $up = min($up, $y);
            $right = max($right, $x);
            $down = max($down, $y);
        }

        // Clamped before the cast: a far-off placement must not overflow an int.
        $x0 = (int) max(-1.0, min(65536.0, floor($left)));
        $y0 = (int) max(-1.0, min(65536.0, floor($up)));
        $x1 = (int) max(-1.0, min(65536.0, ceil($right)));
        $y1 = (int) max(-1.0, min(65536.0, ceil($down)));

        $target = (new Region($x0, $y0, $x1 - $x0, $y1 - $y0))->intersect($bounds);
        if (! is_null($target) && ! is_null($clip)) {
            $target = $target->intersect($clip);
        }
        $inverse = $placement->inverse();
        if (is_null($target) || is_null($inverse)) {
            return null;
        }

        return [$target, [$inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f - $top], $held];
    }
}
