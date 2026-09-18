<?php

namespace Surface\Framebuffers\Packings;

use Surface\Framebuffers\PixelMapper;

/** Four bytes per pixel, R G B A — the rgba8() canonical. Word is 0xRRGGBBAA. */
class Rgba8888Packing extends Packing
{
    public function bytesFor(): int
    {
        return $this->width * $this->height * 4;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        $o = ($y * $this->width + $x) * 4;

        return (ord($bytes[$o]) << 24) | (ord($bytes[$o + 1]) << 16) | (ord($bytes[$o + 2]) << 8) | ord($bytes[$o + 3]);
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $o = ($y * $this->width + $x) * 4;
        $bytes[$o] = chr(($v >> 24) & 0xFF);
        $bytes[$o + 1] = chr(($v >> 16) & 0xFF);
        $bytes[$o + 2] = chr(($v >> 8) & 0xFF);
        $bytes[$o + 3] = chr($v & 0xFF);
    }

    public function fill(string &$bytes, int $v): void
    {
        $this->fillCells($bytes, chr(($v >> 24) & 0xFF).chr(($v >> 16) & 0xFF).chr(($v >> 8) & 0xFF).chr($v & 0xFF));
    }

    /** The store already is RGBA8. */
    public function toRgba8(string $bytes, PixelMapper $m): string
    {
        return $bytes;
    }

    public function fromRgba8(string $rgba8, PixelMapper $m): string
    {
        return $rgba8;
    }
}
