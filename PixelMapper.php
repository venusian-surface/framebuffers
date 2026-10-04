<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\BitDepth;
use Surface\Contracts\Framebuffers\EInkColor;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PixelFormat;
use Surface\NutsAndBolts\Color;

/**
 * Colour policy for one FormatSpec: a colour becomes the host's pixel word and
 * back. Integer arithmetic on 0..255 channels throughout, so the `native` and
 * `extended` stores answer the same word for the same colour. Alpha is kept at
 * B32 only; every other format reads back opaque.
 *
 * Words: mono 0/1 (1 = lit, white); grey 0..max at 2, 4 or 8 bits; packed index
 * = the palette's wire code; planar = a channel mask (0 = paper); RGB = the
 * channels packed at the format's depth, 0xRRGGBBAA at 32 bits.
 */
final class PixelMapper
{
    /** Rec. 709 luma weights, scaled by 10000: luma(255, 255, 255) is 2 550 000. */
    private const int LUMA_MAX = 2_550_000;

    /** @var list<array{int, int, int, int}> [word, red, green, blue] for palette modes */
    private array $entries = [];

    private function __construct(
        private readonly PixelMapperMode $mode,
        private readonly BitDepth $depth,
        private readonly int $levels = 255,
    ) {}

    public static function for(FormatSpec $spec): self
    {
        $palette = $spec->palette;
        $format = $spec->pixel_format;
        $depth = $spec->bit_depth;

        if ($format === PixelFormat::PLANAR) {
            if (is_null($palette)) {
                throw FramebufferException::unsupportedFormat($spec, 'PLANAR needs a palette.');
            }
            $mapper = new self(PixelMapperMode::PLANAR, $depth);
            $mapper->entries[] = [0, 255, 255, 255];
            foreach ($palette->channels as $k => $channel) {
                $mapper->entries[] = [1 << $k, ...EInkColor::from($channel->color)->rgb()];
            }

            return $mapper;
        }

        if ($format === PixelFormat::MONO_HORIZONTAL || $format === PixelFormat::MONO_VERTICAL_PAGE || $depth === BitDepth::B1) {
            return new self(PixelMapperMode::MONO, $depth);
        }

        if (in_array($depth, [BitDepth::B2, BitDepth::B4, BitDepth::B8], true)) {
            if (is_null($palette)) {
                return new self(PixelMapperMode::GREY, $depth, (1 << $depth->value) - 1);
            }
            $mapper = new self(PixelMapperMode::INDEX, $depth);
            foreach ($palette->channels as $k => $channel) {
                $mapper->entries[] = [$channel->code ?? $k, ...EInkColor::from($channel->color)->rgb()];
            }

            return $mapper;
        }

        if (in_array($depth, [BitDepth::B12, BitDepth::B16, BitDepth::B18, BitDepth::B24, BitDepth::B32], true)) {
            return new self(PixelMapperMode::RGB, $depth);
        }

        throw FramebufferException::unsupportedFormat($spec);
    }

    public function mode(): PixelMapperMode
    {
        return $this->mode;
    }

    /** The colour is quantised to 0..255 channels first, so map() and fromRgba8() always agree. */
    public function map(Color $color): int
    {
        return $this->fromRgba8(
            (int) round($color->red * 255),
            (int) round($color->green * 255),
            (int) round($color->blue * 255),
            (int) round($color->alpha * 255),
        );
    }

    public function unmap(int $word): Color
    {
        $rgba = $this->toRgba8($word);

        return Color::rgba(($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, ($rgba & 0xFF) / 255);
    }

    /** 0..255 channels to the host word. */
    public function fromRgba8(int $red, int $green, int $blue, int $alpha = 255): int
    {
        return match ($this->mode) {
            PixelMapperMode::MONO => self::luma($red, $green, $blue) * 2 >= self::LUMA_MAX ? 1 : 0,
            PixelMapperMode::GREY => intdiv(self::luma($red, $green, $blue) * $this->levels * 2 + self::LUMA_MAX, self::LUMA_MAX * 2),
            PixelMapperMode::PLANAR, PixelMapperMode::INDEX => $this->nearest($red, $green, $blue),
            PixelMapperMode::RGB => match ($this->depth) {
                BitDepth::B12 => (($red >> 4) << 8) | (($green >> 4) << 4) | ($blue >> 4),
                BitDepth::B16 => (($red >> 3) << 11) | (($green >> 2) << 5) | ($blue >> 3),
                BitDepth::B18 => (($red & 0xFC) << 16) | (($green & 0xFC) << 8) | ($blue & 0xFC),
                BitDepth::B24 => ($red << 16) | ($green << 8) | $blue,
                BitDepth::B32 => ($red << 24) | ($green << 16) | ($blue << 8) | $alpha,
            },
        };
    }

    /** The host word to 0xRRGGBBAA. */
    public function toRgba8(int $word): int
    {
        return match ($this->mode) {
            PixelMapperMode::MONO => $word ? 0xFFFFFFFF : 0x000000FF,
            PixelMapperMode::GREY => self::opaque(...array_fill(0, 3, self::widen($word & $this->levels, $this->levels))),
            PixelMapperMode::PLANAR => $this->entry($word === 0 ? 0 : $word & -$word),
            PixelMapperMode::INDEX => $this->entry($word),
            PixelMapperMode::RGB => match ($this->depth) {
                BitDepth::B12 => self::opaque((($word >> 8) & 0xF) * 17, (($word >> 4) & 0xF) * 17, ($word & 0xF) * 17),
                BitDepth::B16 => self::opaque(self::widen(($word >> 11) & 0x1F, 31), self::widen(($word >> 5) & 0x3F, 63), self::widen($word & 0x1F, 31)),
                BitDepth::B18 => self::opaque(self::widen(($word >> 18) & 0x3F, 63), self::widen(($word >> 10) & 0x3F, 63), self::widen(($word >> 2) & 0x3F, 63)),
                BitDepth::B24 => (($word & 0xFFFFFF) << 8) | 0xFF,
                BitDepth::B32 => $word & 0xFFFFFFFF,
            },
        };
    }

    private static function luma(int $red, int $green, int $blue): int
    {
        return 2126 * $red + 7152 * $green + 722 * $blue;
    }

    /** round($value / $max * 255) in integers. */
    private static function widen(int $value, int $max): int
    {
        return intdiv($value * 510 + $max, $max * 2);
    }

    private static function opaque(int $red, int $green, int $blue): int
    {
        return ($red << 24) | ($green << 16) | ($blue << 8) | 0xFF;
    }

    /** The palette entry nearest in RGB; the earlier entry wins a tie. */
    private function nearest(int $red, int $green, int $blue): int
    {
        $best = 0;
        $best_distance = PHP_INT_MAX;
        foreach ($this->entries as [$word, $r, $g, $b]) {
            $distance = ($red - $r) ** 2 + ($green - $g) ** 2 + ($blue - $b) ** 2;
            if ($distance < $best_distance) {
                $best_distance = $distance;
                $best = $word;
            }
        }

        return $best;
    }

    /** A palette word's colour; a word the palette does not hold reads as paper. */
    private function entry(int $word): int
    {
        foreach ($this->entries as [$entry, $r, $g, $b]) {
            if ($entry === $word) {
                return self::opaque($r, $g, $b);
            }
        }

        return 0xFFFFFFFF;
    }
}
