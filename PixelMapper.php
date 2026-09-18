<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\BitDepth;
use Surface\Contracts\Framebuffers\EInkColor;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PixelFormat;
use Surface\Contracts\NativeWindows\Views\Color;

/**
 * Colour policy for one FormatSpec: a Color becomes the host word and back.
 * Alpha is ignored except at B32. Driver-independent — the same on php and native.
 */
final class PixelMapper
{
    /** @var list<array{int, Color}> [word, colour] for palette modes */
    private array $entries = [];

    private function __construct(private PixelMapperMode $mode, private FormatSpec $spec) {}

    public static function for(FormatSpec $spec): self
    {
        $palette = $spec->palette;
        $format = $spec->pixel_format;
        $depth = $spec->bit_depth;

        if ($format === PixelFormat::PLANAR) {
            if (is_null($palette)) {
                throw FramebufferException::unsupportedFormat($spec, 'PLANAR needs a palette.');
            }
            $m = new self(PixelMapperMode::PLANAR, $spec);
            $m->entries[] = [0, EInkColor::WHITE->color()];
            foreach ($palette->channels as $k => $channel) {
                $m->entries[] = [1 << $k, EInkColor::from($channel->color)->color()];
            }

            return $m;
        }
        if ($format === PixelFormat::MONO_HORIZONTAL || $format === PixelFormat::MONO_VERTICAL_PAGE || $depth === BitDepth::B1) {
            return new self(PixelMapperMode::MONO, $spec);
        }
        if (in_array($depth, [BitDepth::B2, BitDepth::B4, BitDepth::B8], true) && ! is_null($palette)) {
            $m = new self(PixelMapperMode::INDEX, $spec);
            foreach ($palette->channels as $k => $channel) {
                $m->entries[] = [$channel->code ?? $k, EInkColor::from($channel->color)->color()];
            }

            return $m;
        }
        if ($depth === BitDepth::B8) {
            return new self(PixelMapperMode::GREY, $spec);
        }
        if (in_array($depth, [BitDepth::B12, BitDepth::B16, BitDepth::B18, BitDepth::B24, BitDepth::B32], true)) {
            return new self(PixelMapperMode::RGB, $spec);
        }

        throw FramebufferException::unsupportedFormat($spec);
    }

    public static function luma(Color $c): float
    {
        return 0.2126 * $c->red + 0.7152 * $c->green + 0.0722 * $c->blue;
    }

    public function map(Color $c): int
    {
        $r = (int) round($c->red * 255);
        $g = (int) round($c->green * 255);
        $b = (int) round($c->blue * 255);

        return match ($this->mode) {
            PixelMapperMode::MONO => self::luma($c) >= 0.5 ? 1 : 0,
            PixelMapperMode::GREY => (int) round(self::luma($c) * 255),
            PixelMapperMode::PLANAR, PixelMapperMode::INDEX => $this->nearest($c),
            PixelMapperMode::RGB => match ($this->spec->bit_depth) {
                BitDepth::B12 => (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4),
                BitDepth::B16 => (($r >> 3) << 11) | (($g >> 2) << 5) | ($b >> 3),
                BitDepth::B18 => (($r & 0xFC) << 16) | (($g & 0xFC) << 8) | ($b & 0xFC),
                BitDepth::B24 => ($r << 16) | ($g << 8) | $b,
                BitDepth::B32 => ($r << 24) | ($g << 16) | ($b << 8) | (int) round($c->alpha * 255),
            },
        };
    }

    public function unmap(int $v): Color
    {
        return match ($this->mode) {
            PixelMapperMode::MONO => $v ? new Color(1.0, 1.0, 1.0) : new Color(0.0, 0.0, 0.0),
            PixelMapperMode::GREY => new Color($v / 255, $v / 255, $v / 255),
            PixelMapperMode::PLANAR => $this->planarUnmap($v),
            PixelMapperMode::INDEX => $this->entry($v),
            PixelMapperMode::RGB => match ($this->spec->bit_depth) {
                BitDepth::B12 => new Color((($v >> 8) & 0xF) / 15, (($v >> 4) & 0xF) / 15, ($v & 0xF) / 15),
                BitDepth::B16 => new Color((($v >> 11) & 0x1F) / 31, (($v >> 5) & 0x3F) / 63, ($v & 0x1F) / 31),
                BitDepth::B18 => new Color((($v >> 16) & 0xFC) / 252, (($v >> 8) & 0xFC) / 252, ($v & 0xFC) / 252),
                BitDepth::B24 => new Color((($v >> 16) & 0xFF) / 255, (($v >> 8) & 0xFF) / 255, ($v & 0xFF) / 255),
                BitDepth::B32 => new Color((($v >> 24) & 0xFF) / 255, (($v >> 16) & 0xFF) / 255, (($v >> 8) & 0xFF) / 255, ($v & 0xFF) / 255),
            },
        };
    }

    private function nearest(Color $c): int
    {
        $best = 0;
        $best_d = PHP_FLOAT_MAX;
        foreach ($this->entries as [$word, $colour]) {
            $d = ($c->red - $colour->red) ** 2 + ($c->green - $colour->green) ** 2 + ($c->blue - $colour->blue) ** 2;
            if ($d < $best_d) {
                $best_d = $d;
                $best = $word;
            }
        }

        return $best;
    }

    private function entry(int $v): Color
    {
        foreach ($this->entries as [$word, $colour]) {
            if ($word === $v) {
                return $colour;
            }
        }

        return EInkColor::WHITE->color();
    }

    /** Packings table: unmap a planar mask to the lowest set bit's colour; 0 is paper. */
    private function planarUnmap(int $v): Color
    {
        if ($v === 0) {
            return EInkColor::WHITE->color();
        }
        $k = 0;
        while ((($v >> $k) & 1) === 0) {
            $k++;
        }

        return $this->entry(1 << $k);
    }
}
