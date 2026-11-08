<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\Region;

/** A mask list checked whole before anything paints it, by ext-fb's FbBuffer::paintMasks() rules: native stores and paged buffers. */
final class MaskList
{
    /** A mask's place may lie off the surface, but within this. */
    public const int REACH = 1 << 30;

    /**
     * @param  list<array{string, int, int, int}>  $masks
     *
     * @throws FramebufferException When the colour is not 0xRRGGBBAA, the clip is empty or outside, or a mask is not
     *                              [coverage, width, left, top] of whole rows of 1..65535 bytes, at most 65535 rows, placed within ±2^30.
     */
    public static function check(array $masks, int $rgba8, Region $clip, int $width, int $height): void
    {
        if ($rgba8 < 0 || $rgba8 > 0xFFFFFFFF) {
            throw new FramebufferException("paintMasks() takes a colour 0xRRGGBBAA, got {$rgba8}.");
        }
        if ($clip->isEmpty() || $clip->x < 0 || $clip->y < 0 || $clip->right() > $width || $clip->bottom() > $height) {
            throw new FramebufferException("The clip is empty or not inside a {$width}x{$height} framebuffer.");
        }
        $i = 0;
        foreach ($masks as $mask) {
            if (! is_array($mask) || count($mask) !== 4 || ! is_string($mask[0] ?? null) || ! is_int($mask[1] ?? null) || ! is_int($mask[2] ?? null) || ! is_int($mask[3] ?? null)) {
                throw new FramebufferException("Mask {$i} is not [string coverage, int width, int left, int top].");
            }
            [$coverage, $side, $left, $top] = $mask;
            $bytes = strlen($coverage);
            if ($side < 1 || $side > 65535 || $bytes === 0 || $bytes % $side !== 0 || intdiv($bytes, $side) > 65535) {
                throw new FramebufferException("Mask {$i} is not whole rows of 1..65535 bytes, at most 65535 rows.");
            }
            if (abs($left) > self::REACH || abs($top) > self::REACH) {
                throw new FramebufferException("Mask {$i} is placed past +/-2^30.");
            }
            $i++;
        }
    }
}
