<?php

namespace Surface\Framebuffers\Native\Packings;

use Surface\Contracts\Framebuffers\FormatSpec;

/** Three bytes per pixel, R G B (or B G R). Word is 0xRRGGBB. */
class Rgb888Packing extends Packing
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

        return (ord($bytes[$r]) << 16) | (ord($bytes[$o + 1]) << 8) | ord($bytes[$b]);
    }

    public function set(string &$bytes, int $x, int $y, int $value): void
    {
        $o = ($y * $this->width + $x) * 3;
        [$r, $b] = $this->bgr ? [$o + 2, $o] : [$o, $o + 2];
        $bytes[$r] = chr(($value >> 16) & 0xFF);
        $bytes[$o + 1] = chr(($value >> 8) & 0xFF);
        $bytes[$b] = chr($value & 0xFF);
    }

    public function fill(string &$bytes, int $value): void
    {
        $cell = chr(($value >> 16) & 0xFF).chr(($value >> 8) & 0xFF).chr($value & 0xFF);
        $this->fillCells($bytes, $this->bgr ? strrev($cell) : $cell);
    }
}
