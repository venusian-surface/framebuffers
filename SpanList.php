<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\Spans;

/** A span list read and checked whole before anything paints it: the one rule set for native stores and paged buffers. */
final class SpanList
{
    /**
     * @return array{list<array{int, int, int, int}>, Region|null} The spans as [y, x, length, coverage], and their bounding box.
     *
     * @throws FramebufferException When the colour is not 0xRRGGBBAA, the bytes are not whole spans, or a span is empty or outside.
     */
    public static function read(string $spans, int $rgba8, int $width, int $height): array
    {
        if ($rgba8 < 0 || $rgba8 > 0xFFFFFFFF) {
            throw new FramebufferException("paintSpans() takes a colour 0xRRGGBBAA, got {$rgba8}.");
        }

        $list = Spans::unpack($spans);
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach ($list as $i => [$y, $x, $length]) {
            if ($length < 1 || $y >= $height || $x + $length > $width) {
                throw new FramebufferException("Span {$i} is empty or not inside a {$width}x{$height} framebuffer.");
            }
            $left = min($left, $x);
            $top = min($top, $y);
            $right = max($right, $x + $length);
            $bottom = max($bottom, $y + 1);
        }

        return [$list, $list === [] ? null : new Region($left, $top, $right - $left, $bottom - $top)];
    }
}
