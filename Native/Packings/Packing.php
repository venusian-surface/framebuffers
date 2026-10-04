<?php

namespace Surface\Framebuffers\Native\Packings;

use Surface\Contracts\Framebuffers\ChannelOrder;
use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Endianness;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\ScanDirection;
use Surface\Framebuffers\Layout;

/**
 * The byte layout of one FormatSpec at one size: where pixel (x, y) lives and
 * how its word is packed. Pure layout math over a PHP string; the string
 * itself belongs to NativePixelStore. ext-fb holds the same table in C.
 */
abstract class Packing
{
    public function __construct(
        protected FormatSpec $spec,
        protected int $width,
        protected int $height,
    ) {}

    public static function for(FormatSpec $spec, int $width, int $height): Packing
    {
        $bits = Layout::bitOrder($spec);
        $bgr = Layout::channelOrder($spec) === ChannelOrder::BGR;

        return match (Layout::of($spec)) {
            Layout::MONO_ROWS => new MonoHorizontalPacking($spec, $width, $height, $bits),
            Layout::MONO_PAGES => new MonoVerticalPagePacking($spec, $width, $height, $bits),
            Layout::INDEX2, Layout::INDEX4, Layout::INDEX8 => new PackedIndexPacking($spec, $width, $height, $spec->bit_depth->value, $bits),
            Layout::RGB444 => new Rgb444Packing($spec, $width, $height, $bgr),
            Layout::RGB565 => new Rgb565Packing($spec, $width, $height, $spec->endianness ?? Endianness::MSB, $bgr),
            Layout::RGB666 => new Rgb666Packing($spec, $width, $height, $bgr),
            Layout::RGB888 => new Rgb888Packing($spec, $width, $height, $bgr),
            Layout::RGBA8888 => new Rgba8888Packing($spec, $width, $height, Layout::channelOrder($spec)),
            Layout::PLANAR => new PlanarPacking($spec, $width, $height, $bits),
        };
    }

    abstract public function bytesFor(): int;

    abstract public function get(string $bytes, int $x, int $y): int;

    abstract public function set(string &$bytes, int $x, int $y, int $value): void;

    /** The fill(0) state. Zero bytes unless a layout says otherwise (inverted planes). */
    public function blank(): string
    {
        return str_repeat("\0", $this->bytesFor());
    }

    public function span(string &$bytes, int $x, int $y, int $length, int $value): void
    {
        for ($i = 0; $i < $length; $i++) {
            $this->set($bytes, $x + $i, $y, $value);
        }
    }

    public function fill(string &$bytes, int $value): void
    {
        for ($y = 0; $y < $this->height; $y++) {
            $this->span($bytes, 0, $y, $this->width, $value);
        }
    }

    /**
     * Bytes of a sub-rect in this same layout, rows in this spec's scan
     * direction. The whole surface top-down is the string itself.
     */
    public function region(string $bytes, Region $r): string
    {
        $reversed = $this->spec->scan_direction === ScanDirection::BOTTOM_TO_TOP;
        if (! $reversed && $r->x === 0 && $r->y === 0 && $r->width === $this->width && $r->height === $this->height) {
            return $bytes;
        }

        $sub = static::for($this->spec, $r->width, $r->height);
        $out = $sub->blank();
        for ($y = 0; $y < $r->height; $y++) {
            $row = $reversed ? $r->height - 1 - $y : $y;
            for ($x = 0; $x < $r->width; $x++) {
                $sub->set($out, $x, $row, $this->get($bytes, $r->x + $x, $r->y + $y));
            }
        }

        return $out;
    }

    /** How many layers the store has; only planar has more than one. */
    public function layers(): int
    {
        return 1;
    }

    public function layer(string $bytes, int $layer): string
    {
        return $bytes;
    }

    public function granularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    /** Fast fill for byte-aligned pixels: one repeated cell, real pixels only (rows have no padding here). */
    protected function fillCells(string &$bytes, string $cell): void
    {
        $bytes = str_repeat($cell, $this->width * $this->height);
    }
}
