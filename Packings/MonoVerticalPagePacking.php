<?php

namespace Surface\Framebuffers\Packings;

use Surface\Contracts\Framebuffers\BitOrder;
use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\FormatSpec;

/** SSD1306: one byte per column per 8-row page, page-major. LSB_FIRST: bit 0 is the page's top row. */
class MonoVerticalPagePacking extends Packing
{
    public function __construct(FormatSpec $spec, int $width, int $height, protected BitOrder $order)
    {
        parent::__construct($spec, $width, $height);
    }

    public function bytesFor(): int
    {
        return $this->width * intdiv($this->height + 7, 8);
    }

    public function get(string $bytes, int $x, int $y): int
    {
        return (ord($bytes[($y >> 3) * $this->width + $x]) >> $this->bit($y)) & 1;
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $i = ($y >> 3) * $this->width + $x;
        $mask = 1 << $this->bit($y);
        $b = ord($bytes[$i]);
        $bytes[$i] = chr($v ? $b | $mask : $b & ~$mask);
    }

    public function granularity(): DamageGranularity
    {
        return DamageGranularity::rows(8, $this->width, $this->height);
    }

    protected function bit(int $y): int
    {
        return $this->order === BitOrder::LSB_FIRST ? $y & 7 : 7 - ($y & 7);
    }
}
