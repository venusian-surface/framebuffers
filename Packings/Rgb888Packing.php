<?php

namespace Surface\Framebuffers\Packings;

/** Three bytes per pixel, R G B. Word is 0xRRGGBB. */
class Rgb888Packing extends Packing
{
    public function bytesFor(): int
    {
        return $this->width * $this->height * 3;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        $o = ($y * $this->width + $x) * 3;

        return (ord($bytes[$o]) << 16) | (ord($bytes[$o + 1]) << 8) | ord($bytes[$o + 2]);
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $o = ($y * $this->width + $x) * 3;
        $bytes[$o] = chr(($v >> 16) & 0xFF);
        $bytes[$o + 1] = chr(($v >> 8) & 0xFF);
        $bytes[$o + 2] = chr($v & 0xFF);
    }

    public function fill(string &$bytes, int $v): void
    {
        $this->fillCells($bytes, chr(($v >> 16) & 0xFF).chr(($v >> 8) & 0xFF).chr($v & 0xFF));
    }
}
