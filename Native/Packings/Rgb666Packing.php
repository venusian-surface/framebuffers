<?php

namespace Surface\Framebuffers\Native\Packings;

use Surface\Contracts\Framebuffers\FormatSpec;

/**
 * Three bytes per pixel, six significant bits left-aligned in each (ST77xx
 * 18-bit wire). Word is RGB888 with the low two bits of each channel zero.
 */
class Rgb666Packing extends Packing
{
    public function __construct(FormatSpec $spec, int $width, int $height, protected bool $bgr = false)
    {
        parent::__construct($spec, $width, $height);
    }

    public function bytesFor(): int
    {
        return $this->width * $this->height * 3;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        $o = ($y * $this->width + $x) * 3;
        [$r, $b] = $this->bgr ? [$o + 2, $o] : [$o, $o + 2];

        return ((ord($bytes[$r]) & 0xFC) << 16) | ((ord($bytes[$o + 1]) & 0xFC) << 8) | (ord($bytes[$b]) & 0xFC);
    }

    public function set(string &$bytes, int $x, int $y, int $value): void
    {
        $o = ($y * $this->width + $x) * 3;
        [$r, $b] = $this->bgr ? [$o + 2, $o] : [$o, $o + 2];
        $bytes[$r] = chr(($value >> 16) & 0xFC);
        $bytes[$o + 1] = chr(($value >> 8) & 0xFC);
        $bytes[$b] = chr($value & 0xFC);
    }

    public function fill(string &$bytes, int $value): void
    {
        $cell = chr(($value >> 16) & 0xFC).chr(($value >> 8) & 0xFC).chr($value & 0xFC);
        $this->fillCells($bytes, $this->bgr ? strrev($cell) : $cell);
    }
}
