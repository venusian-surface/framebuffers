<?php

namespace Surface\Framebuffers\Native\Packings;

use Surface\Contracts\Framebuffers\ChannelOrder;
use Surface\Contracts\Framebuffers\FormatSpec;

/** Four bytes per pixel in the spec's channel order (R G B A unless it says otherwise). Word is 0xRRGGBBAA. */
class Rgba8888Packing extends Packing
{
    /** @var array{int, int, int, int} Byte offsets of red, green, blue and alpha within a pixel. */
    protected array $at;

    public function __construct(FormatSpec $spec, int $width, int $height, protected ChannelOrder $order = ChannelOrder::RGBA)
    {
        parent::__construct($spec, $width, $height);
        $this->at = match ($order) {
            ChannelOrder::BGRA => [2, 1, 0, 3],
            ChannelOrder::ARGB => [1, 2, 3, 0],
            ChannelOrder::ABGR => [3, 2, 1, 0],
            default => [0, 1, 2, 3],
        };
    }

    public function bytesFor(): int
    {
        return $this->width * $this->height * 4;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        $o = ($y * $this->width + $x) * 4;
        [$r, $g, $b, $a] = $this->at;

        return (ord($bytes[$o + $r]) << 24) | (ord($bytes[$o + $g]) << 16) | (ord($bytes[$o + $b]) << 8) | ord($bytes[$o + $a]);
    }

    public function set(string &$bytes, int $x, int $y, int $value): void
    {
        $o = ($y * $this->width + $x) * 4;
        [$r, $g, $b, $a] = $this->at;
        $bytes[$o + $r] = chr(($value >> 24) & 0xFF);
        $bytes[$o + $g] = chr(($value >> 16) & 0xFF);
        $bytes[$o + $b] = chr(($value >> 8) & 0xFF);
        $bytes[$o + $a] = chr($value & 0xFF);
    }

    public function fill(string &$bytes, int $value): void
    {
        $cell = "\0\0\0\0";
        $this->set($cell, 0, 0, $value);
        $this->fillCells($bytes, $cell);
    }
}
