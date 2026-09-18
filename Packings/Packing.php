<?php

namespace Surface\Framebuffers\Packings;

use Surface\Contracts\Framebuffers\BitDepth;
use Surface\Contracts\Framebuffers\BitOrder;
use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Endianness;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PageAxis;
use Surface\Contracts\Framebuffers\PixelFormat;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\ScanDirection;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Framebuffers\PixelMapper;

/**
 * The byte layout of one FormatSpec at one size: where pixel (x, y) lives
 * and how its word is packed. Pure layout math over a PHP string; the store
 * itself belongs to PackedGrid. Every method here is the reference for the
 * same table in ext-fb.
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
        $format = $spec->pixel_format;
        $depth = $spec->bit_depth;

        if ($format === PixelFormat::MONO_HORIZONTAL && $depth === BitDepth::B1) {
            return new MonoHorizontalPacking($spec, $width, $height, $spec->bit_order ?? BitOrder::MSB_FIRST);
        }
        if ($format === PixelFormat::MONO_VERTICAL_PAGE && $depth === BitDepth::B1) {
            $order = $spec->bit_order ?? BitOrder::LSB_FIRST;
            if (($spec->page_axis ?? PageAxis::VERTICAL) === PageAxis::HORIZONTAL) {
                return new MonoHorizontalPacking($spec, $width, $height, $order);
            }

            return new MonoVerticalPagePacking($spec, $width, $height, $order);
        }
        if ($format === PixelFormat::PLANAR && $depth === BitDepth::B1) {
            if (is_null($spec->palette)) {
                throw FramebufferException::unsupportedFormat($spec, 'PLANAR needs a palette.');
            }

            return new PlanarPacking($spec, $width, $height, $spec->bit_order ?? BitOrder::MSB_FIRST);
        }
        if ($format === PixelFormat::ROW_MAJOR) {
            return match ($depth) {
                BitDepth::B2, BitDepth::B4, BitDepth::B8 => new PackedIndexPacking($spec, $width, $height, $depth->value, $spec->bit_order ?? BitOrder::MSB_FIRST),
                BitDepth::B12 => new Rgb444Packing($spec, $width, $height),
                BitDepth::B16 => new Rgb565Packing($spec, $width, $height, $spec->endianness ?? Endianness::MSB),
                BitDepth::B18 => new Rgb666Packing($spec, $width, $height),
                BitDepth::B24 => new Rgb888Packing($spec, $width, $height),
                BitDepth::B32 => new Rgba8888Packing($spec, $width, $height),
                default => throw FramebufferException::unsupportedFormat($spec),
            };
        }

        throw FramebufferException::unsupportedFormat($spec);
    }

    public function spec(): FormatSpec
    {
        return $this->spec;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    abstract public function bytesFor(): int;

    abstract public function get(string $bytes, int $x, int $y): int;

    abstract public function set(string &$bytes, int $x, int $y, int $v): void;

    /** The fill(0) state. Zero bytes unless a layout says otherwise (inverted planes). */
    public function blank(): string
    {
        return str_repeat("\0", $this->bytesFor());
    }

    public function span(string &$bytes, int $x, int $y, int $length, int $v): void
    {
        for ($i = 0; $i < $length; $i++) {
            $this->set($bytes, $x + $i, $y, $v);
        }
    }

    public function fill(string &$bytes, int $v): void
    {
        for ($y = 0; $y < $this->height; $y++) {
            $this->span($bytes, 0, $y, $this->width, $v);
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
            $yy = $reversed ? $r->height - 1 - $y : $y;
            for ($x = 0; $x < $r->width; $x++) {
                $sub->set($out, $x, $yy, $this->get($bytes, $r->x + $x, $r->y + $y));
            }
        }

        return $out;
    }

    /** A layer of the store; only planar has more than one. */
    public function layer(string $bytes, ?int $layer): string
    {
        return $bytes;
    }

    public function granularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    public function toRgba8(string $bytes, PixelMapper $m): string
    {
        $out = '';
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $c = $m->unmap($this->get($bytes, $x, $y));
                $out .= chr((int) round($c->red * 255)).chr((int) round($c->green * 255)).chr((int) round($c->blue * 255)).chr((int) round($c->alpha * 255));
            }
        }

        return $out;
    }

    public function fromRgba8(string $rgba8, PixelMapper $m): string
    {
        $out = $this->blank();
        $i = 0;
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++, $i += 4) {
                $c = new Color(ord($rgba8[$i]) / 255, ord($rgba8[$i + 1]) / 255, ord($rgba8[$i + 2]) / 255, ord($rgba8[$i + 3]) / 255);
                $this->set($out, $x, $y, $m->map($c));
            }
        }

        return $out;
    }

    /** Fast fill for byte-aligned pixels: one repeated cell, real pixels only (rows have no padding here). */
    protected function fillCells(string &$bytes, string $cell): void
    {
        $bytes = str_repeat($cell, $this->width * $this->height);
    }
}
