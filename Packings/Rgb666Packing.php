<?php

namespace Surface\Framebuffers\Packings;

/** Three bytes per pixel, six significant bits left-aligned in each (ST77xx 18-bit wire). Word is RGB888 with the low two bits of each channel zero. */
class Rgb666Packing extends Packing
{
    public function bytesFor(): int
    {
        return $this->width * $this->height * 3;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        $o = ($y * $this->width + $x) * 3;

        return ((ord($bytes[$o]) & 0xFC) << 16) | ((ord($bytes[$o + 1]) & 0xFC) << 8) | (ord($bytes[$o + 2]) & 0xFC);
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $o = ($y * $this->width + $x) * 3;
        $bytes[$o] = chr(($v >> 16) & 0xFC);
        $bytes[$o + 1] = chr(($v >> 8) & 0xFC);
        $bytes[$o + 2] = chr($v & 0xFC);
    }

    public function fill(string &$bytes, int $v): void
    {
        $this->fillCells($bytes, chr(($v >> 16) & 0xFC).chr(($v >> 8) & 0xFC).chr($v & 0xFC));
    }
}
