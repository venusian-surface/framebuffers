<?php

namespace Surface\Framebuffers\Php;

use Surface\Contracts\Framebuffers\BitDepth;
use Surface\Contracts\Framebuffers\BitOrder;
use Surface\Contracts\Framebuffers\EInkColor;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PixelFormat;
use Surface\Framebuffers\Packings\MonoHorizontalPacking;

/**
 * Planar planes, single-ink mono, or packed palette codes — the three ways
 * an ePaper controller takes its RAM. Refuses colour depths.
 */
class EPaperFramebuffer extends PackedGrid
{
    public function __construct(FormatSpec $format, int $width, int $height)
    {
        $ok = match (true) {
            $format->pixel_format === PixelFormat::PLANAR && $format->bit_depth === BitDepth::B1 && ! is_null($format->palette) => true,
            $format->pixel_format === PixelFormat::MONO_HORIZONTAL && $format->bit_depth === BitDepth::B1 => true,
            $format->pixel_format === PixelFormat::ROW_MAJOR && in_array($format->bit_depth, [BitDepth::B2, BitDepth::B4, BitDepth::B8], true) && ! is_null($format->palette) => true,
            default => false,
        };
        if (! $ok) {
            throw FramebufferException::unsupportedFormat($format, 'ePaper takes PLANAR + palette, MONO_HORIZONTAL B1, or ROW_MAJOR B2/B4/B8 + palette.');
        }
        parent::__construct($format, $width, $height);
    }

    /** One ink as a MonoHorizontal MSB plane: the stored plane on a planar host (polarity as stored), or code == ink computed on a packed host. Single-ink mono answers the store for BLACK. */
    public function channelDump(EInkColor $ink): string
    {
        if ($this->format->pixel_format === PixelFormat::MONO_HORIZONTAL) {
            return $this->bytes;
        }
        $k = $this->format->palette->indexOf($ink->value);
        if (is_null($k)) {
            throw new FramebufferException("{$ink->name} is not in this panel's palette.");
        }
        if ($this->format->pixel_format === PixelFormat::PLANAR) {
            return $this->packing->layer($this->bytes, $k);
        }
        $code = $this->format->palette->codes()[$k];
        $plane = new MonoHorizontalPacking($this->format, $this->width, $this->height, BitOrder::MSB_FIRST);
        $out = $plane->blank();
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if ($this->packing->get($this->bytes, $x, $y) === $code) {
                    $plane->set($out, $x, $y, 1);
                }
            }
        }

        return $out;
    }
}
