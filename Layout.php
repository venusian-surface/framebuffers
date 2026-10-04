<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\BitDepth;
use Surface\Contracts\Framebuffers\BitOrder;
use Surface\Contracts\Framebuffers\ChannelOrder;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PageAxis;
use Surface\Contracts\Framebuffers\PixelFormat;

/**
 * The byte layouts a FormatSpec can name, and the one rule set that says which
 * specs are storable. Both drivers resolve a spec here, so they accept and
 * refuse exactly the same formats.
 */
enum Layout
{
    /** One bit per pixel, rows padded to a byte. */
    case MONO_ROWS;
    /** One bit per pixel, one byte per column per 8-row page (SSD1306). */
    case MONO_PAGES;
    /** 2, 4 or 8 bits per pixel, rows padded to a byte: grey levels, or palette codes. */
    case INDEX2;
    case INDEX4;
    case INDEX8;
    case RGB444;
    case RGB565;
    case RGB666;
    case RGB888;
    case RGBA8888;
    /** One one-bit plane per ink. */
    case PLANAR;

    public const int MAX_INKS = 16;

    public const int MAX_SIDE = 65535;

    /** @throws FramebufferException When no layout stores $spec. */
    public static function of(FormatSpec $spec): self
    {
        $format = $spec->pixel_format;
        $depth = $spec->bit_depth;
        $order = $spec->channel_order;
        $palette = $spec->palette;

        $layout = match (true) {
            $format === PixelFormat::MONO_HORIZONTAL && $depth === BitDepth::B1 => self::MONO_ROWS,
            $format === PixelFormat::MONO_VERTICAL_PAGE && $depth === BitDepth::B1 => ($spec->page_axis ?? PageAxis::VERTICAL) === PageAxis::HORIZONTAL ? self::MONO_ROWS : self::MONO_PAGES,
            $format === PixelFormat::PLANAR && $depth === BitDepth::B1 => self::PLANAR,
            $format === PixelFormat::ROW_MAJOR => match ($depth) {
                BitDepth::B2 => self::INDEX2,
                BitDepth::B4 => self::INDEX4,
                BitDepth::B8 => self::INDEX8,
                BitDepth::B12 => self::RGB444,
                BitDepth::B16 => self::RGB565,
                BitDepth::B18 => self::RGB666,
                BitDepth::B24 => self::RGB888,
                BitDepth::B32 => self::RGBA8888,
                default => throw FramebufferException::unsupportedFormat($spec),
            },
            default => throw FramebufferException::unsupportedFormat($spec),
        };

        if ($layout === self::PLANAR && is_null($palette)) {
            throw FramebufferException::unsupportedFormat($spec, 'PLANAR needs a palette.');
        }
        if (! is_null($order) && (! $layout->isColour() || $order->hasAlpha() !== ($layout === self::RGBA8888))) {
            throw FramebufferException::unsupportedFormat($spec, "{$order->name} is not a channel order of this format.");
        }
        if ($layout->takesPalette() && ! is_null($palette)) {
            if ($palette->count() > self::MAX_INKS) {
                throw FramebufferException::unsupportedFormat($spec, 'A palette holds at most '.self::MAX_INKS.' inks.');
            }
            if ($layout !== self::PLANAR && max($palette->codes()) >= 1 << $depth->value || min($palette->codes()) < 0) {
                throw FramebufferException::unsupportedFormat($spec, "A palette code must fit {$depth->value} bits.");
            }
        }

        return $layout;
    }

    /** The RGB layouts: the ones a channel order applies to. */
    public function isColour(): bool
    {
        return in_array($this, [self::RGB444, self::RGB565, self::RGB666, self::RGB888, self::RGBA8888], true);
    }

    /** The layouts whose words come from a palette. */
    public function takesPalette(): bool
    {
        return in_array($this, [self::INDEX2, self::INDEX4, self::INDEX8, self::PLANAR], true);
    }

    /** The spec's bit order, or the layout's own: LSB first for MONO_VERTICAL_PAGE (SSD1306), MSB first otherwise. */
    public static function bitOrder(FormatSpec $spec): BitOrder
    {
        return $spec->bit_order ?? ($spec->pixel_format === PixelFormat::MONO_VERTICAL_PAGE ? BitOrder::LSB_FIRST : BitOrder::MSB_FIRST);
    }

    /** The spec's channel order, or the depth's own: RGBA at 32 bits, RGB below. */
    public static function channelOrder(FormatSpec $spec): ChannelOrder
    {
        return $spec->channel_order ?? ($spec->bit_depth === BitDepth::B32 ? ChannelOrder::RGBA : ChannelOrder::RGB);
    }

    /** @throws FramebufferException When a side is not 1..MAX_SIDE. */
    public static function size(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw new FramebufferException("A framebuffer's sides are 1..".self::MAX_SIDE.", got {$width}x{$height}.");
        }
    }
}
