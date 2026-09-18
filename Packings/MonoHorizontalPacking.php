<?php

namespace Surface\Framebuffers\Packings;

use Surface\Contracts\Framebuffers\BitOrder;
use Surface\Contracts\Framebuffers\FormatSpec;

/** One bit per pixel, row-major, each row padded to a byte. MSB_FIRST: x0 is bit 7. */
class MonoHorizontalPacking extends Packing
{
    protected int $row_bytes;

    public function __construct(FormatSpec $spec, int $width, int $height, protected BitOrder $order)
    {
        parent::__construct($spec, $width, $height);
        $this->row_bytes = intdiv($width + 7, 8);
    }

    public function bytesFor(): int
    {
        return $this->row_bytes * $this->height;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        return (ord($bytes[$y * $this->row_bytes + ($x >> 3)]) >> $this->bit($x)) & 1;
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $i = $y * $this->row_bytes + ($x >> 3);
        $mask = 1 << $this->bit($x);
        $b = ord($bytes[$i]);
        $bytes[$i] = chr($v ? $b | $mask : $b & ~$mask);
    }

    protected function bit(int $x): int
    {
        return $this->order === BitOrder::MSB_FIRST ? 7 - ($x & 7) : $x & 7;
    }
}
