<?php

namespace Surface\Framebuffers\Native\Packings;

use Surface\Contracts\Framebuffers\Endianness;
use Surface\Contracts\Framebuffers\FormatSpec;

/** Two bytes per pixel. MSB: high byte first (ST77xx wire). Word is RGB565; BGR stores blue in the high five bits. */
class Rgb565Packing extends Packing
{
    public function __construct(FormatSpec $spec, int $width, int $height, protected Endianness $endianness, protected bool $bgr = false)
    {
        parent::__construct($spec, $width, $height);
    }

    public function bytesFor(): int
    {
        return $this->width * $this->height * 2;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        $o = ($y * $this->width + $x) * 2;
        [$hi, $lo] = $this->endianness === Endianness::MSB ? [$o, $o + 1] : [$o + 1, $o];

        return $this->swap((ord($bytes[$hi]) << 8) | ord($bytes[$lo]));
    }

    public function set(string &$bytes, int $x, int $y, int $value): void
    {
        $o = ($y * $this->width + $x) * 2;
        [$hi, $lo] = $this->endianness === Endianness::MSB ? [$o, $o + 1] : [$o + 1, $o];
        $stored = $this->swap($value & 0xFFFF);
        $bytes[$hi] = chr($stored >> 8);
        $bytes[$lo] = chr($stored & 0xFF);
    }

    public function fill(string &$bytes, int $value): void
    {
        $stored = $this->swap($value & 0xFFFF);
        $cell = chr($stored >> 8).chr($stored & 0xFF);
        $this->fillCells($bytes, $this->endianness === Endianness::MSB ? $cell : strrev($cell));
    }

    /** RGB and BGR differ by swapping the two five-bit fields; the swap is its own inverse. */
    protected function swap(int $word): int
    {
        return $this->bgr ? (($word & 0x1F) << 11) | ($word & 0x07E0) | ($word >> 11) : $word;
    }
}
