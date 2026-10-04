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
     * @return array{Region, array{float, float, float, float, float, float}}|null The target rect inside $bounds
     *                                                                             and $clip, and the inverse that maps a target point to a point of the source's bytes; null when nothing lands.
     *
     * @throws FramebufferException When the opacity is outside 0..255.
     */
    public static function plan(Affine $placement, int $width, int $height, int $top, Region $bounds, ?Region $clip, int $opacity): ?array
    {
        if ($opacity < 0 || $opacity > 255) {
            throw new FramebufferException("paintImage() takes an opacity 0..255, got {$opacity}.");
        }

        $left = $up = INF;
        $right = $down = -INF;
        foreach ([[0, $top], [$width, $top], [$width, $top + $height], [0, $top + $height]] as [$u, $v]) {
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

        return [$target, [$inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f - $top]];
    }
}
