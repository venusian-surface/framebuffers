<?php

namespace Surface\Framebuffers\Packings;

use Surface\Contracts\Framebuffers\FormatSpec;

/** Two 12-bit pixels in three bytes: [R0 G0] [B0 R1] [G1 B1]; an odd row tail pads the second pixel with zeros. Word is 0xRGB. */
class Rgb444Packing extends Packing
{
    protected int $row_bytes;

    public function __construct(FormatSpec $spec, int $width, int $height)
    {
        parent::__construct($spec, $width, $height);
        $this->row_bytes = intdiv($width + 1, 2) * 3;
    }

    public function bytesFor(): int
    {
        return $this->row_bytes * $this->height;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        $o = $y * $this->row_bytes + intdiv($x, 2) * 3;
        if (($x & 1) === 0) {
            return (ord($bytes[$o]) << 4) | (ord($bytes[$o + 1]) >> 4);
        }

        return ((ord($bytes[$o + 1]) & 0x0F) << 8) | ord($bytes[$o + 2]);
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $o = $y * $this->row_bytes + intdiv($x, 2) * 3;
        $v &= 0xFFF;
        if (($x & 1) === 0) {
            $bytes[$o] = chr($v >> 4);
            $bytes[$o + 1] = chr((ord($bytes[$o + 1]) & 0x0F) | (($v & 0xF) << 4));

            return;
        }
        $bytes[$o + 1] = chr((ord($bytes[$o + 1]) & 0xF0) | ($v >> 8));
        $bytes[$o + 2] = chr($v & 0xFF);
    }
}
