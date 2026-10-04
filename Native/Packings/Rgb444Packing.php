<?php

namespace Surface\Framebuffers\Native\Packings;

use Surface\Contracts\Framebuffers\FormatSpec;

/**
 * Two 12-bit pixels in three bytes: [R0 G0] [B0 R1] [G1 B1]; an odd row tail
 * pads the second pixel with zeros. Word is 0xRGB; BGR stores it as 0xBGR.
 */
class Rgb444Packing extends Packing
{
    protected int $row_bytes;

    public function __construct(FormatSpec $spec, int $width, int $height, protected bool $bgr = false)
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
        $stored = ($x & 1) === 0
            ? (ord($bytes[$o]) << 4) | (ord($bytes[$o + 1]) >> 4)
            : ((ord($bytes[$o + 1]) & 0x0F) << 8) | ord($bytes[$o + 2]);

        return $this->swap($stored);
    }

    public function set(string &$bytes, int $x, int $y, int $value): void
    {
        $o = $y * $this->row_bytes + intdiv($x, 2) * 3;
        $stored = $this->swap($value & 0xFFF);
        if (($x & 1) === 0) {
            $bytes[$o] = chr($stored >> 4);
            $bytes[$o + 1] = chr((ord($bytes[$o + 1]) & 0x0F) | (($stored & 0xF) << 4));

            return;
        }
        $bytes[$o + 1] = chr((ord($bytes[$o + 1]) & 0xF0) | ($stored >> 8));
        $bytes[$o + 2] = chr($stored & 0xFF);
    }

    /** RGB and BGR differ by swapping the outer nibbles; the swap is its own inverse. */
    protected function swap(int $word): int
    {
        return $this->bgr ? (($word & 0xF) << 8) | ($word & 0xF0) | ($word >> 8) : $word;
    }
}
