<?php

namespace Surface\Framebuffers\Packings;

use Surface\Contracts\Framebuffers\Endianness;
use Surface\Contracts\Framebuffers\FormatSpec;

/** Two bytes per pixel. MSB: high byte first (ST77xx wire). */
class Rgb565Packing extends Packing
{
    public function __construct(FormatSpec $spec, int $width, int $height, protected Endianness $endianness)
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

        return (ord($bytes[$hi]) << 8) | ord($bytes[$lo]);
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $o = ($y * $this->width + $x) * 2;
        [$hi, $lo] = $this->endianness === Endianness::MSB ? [$o, $o + 1] : [$o + 1, $o];
        $bytes[$hi] = chr(($v >> 8) & 0xFF);
        $bytes[$lo] = chr($v & 0xFF);
    }

    public function fill(string &$bytes, int $v): void
    {
        $cell = chr(($v >> 8) & 0xFF).chr($v & 0xFF);
        $this->fillCells($bytes, $this->endianness === Endianness::MSB ? $cell : strrev($cell));
    }
}
