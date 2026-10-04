<?php

namespace Surface\Framebuffers\Extended;

use FbFormat;
use Surface\Contracts\Framebuffers\BitOrder;
use Surface\Contracts\Framebuffers\ChannelOrder;
use Surface\Contracts\Framebuffers\ChannelSpec;
use Surface\Contracts\Framebuffers\EInkColor;
use Surface\Contracts\Framebuffers\Endianness;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\ScanDirection;
use Surface\Framebuffers\Layout;

/** A FormatSpec in ext-fb's own vocabulary. ext-fb knows nothing of Surface; this is the one place that translates. */
final class FbFormats
{
    public static function from(FormatSpec $spec): FbFormat
    {
        $layout = Layout::of($spec);

        return new FbFormat(
            match ($layout) {
                Layout::MONO_ROWS => \FB_LAYOUT_MONO_ROWS,
                Layout::MONO_PAGES => \FB_LAYOUT_MONO_PAGES,
                Layout::INDEX2 => \FB_LAYOUT_INDEX2,
                Layout::INDEX4 => \FB_LAYOUT_INDEX4,
                Layout::INDEX8 => \FB_LAYOUT_INDEX8,
                Layout::RGB444 => \FB_LAYOUT_RGB444,
                Layout::RGB565 => \FB_LAYOUT_RGB565,
                Layout::RGB666 => \FB_LAYOUT_RGB666,
                Layout::RGB888 => \FB_LAYOUT_RGB888,
                Layout::RGBA8888 => \FB_LAYOUT_RGBA8888,
                Layout::PLANAR => \FB_LAYOUT_PLANAR,
            },
            Layout::bitOrder($spec) === BitOrder::MSB_FIRST ? \FB_BIT_ORDER_MSB : \FB_BIT_ORDER_LSB,
            ($spec->endianness ?? Endianness::MSB) === Endianness::MSB ? \FB_BYTE_ORDER_MSB : \FB_BYTE_ORDER_LSB,
            ! $layout->isColour() ? \FB_CHANNELS_RGB : match (Layout::channelOrder($spec)) {
                ChannelOrder::RGB => \FB_CHANNELS_RGB,
                ChannelOrder::BGR => \FB_CHANNELS_BGR,
                ChannelOrder::RGBA => \FB_CHANNELS_RGBA,
                ChannelOrder::BGRA => \FB_CHANNELS_BGRA,
                ChannelOrder::ARGB => \FB_CHANNELS_ARGB,
                ChannelOrder::ABGR => \FB_CHANNELS_ABGR,
            },
            $spec->scan_direction === ScanDirection::TOP_TO_BOTTOM ? \FB_SCAN_TOP_DOWN : \FB_SCAN_BOTTOM_UP,
            $layout->takesPalette() && ! is_null($spec->palette) ? self::palette($spec->palette->channels) : [],
        );
    }

    /**
     * @param  list<ChannelSpec>  $channels
     * @return list<array{int, bool, int}> [0xRRGGBB, inverted, code]
     */
    private static function palette(array $channels): array
    {
        $inks = [];
        foreach ($channels as $position => $channel) {
            [$red, $green, $blue] = EInkColor::from($channel->color)->rgb();
            $inks[] = [($red << 16) | ($green << 8) | $blue, $channel->inverted, $channel->code ?? $position];
        }

        return $inks;
    }
}
