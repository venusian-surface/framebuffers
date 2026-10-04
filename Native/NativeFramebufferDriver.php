<?php

namespace Surface\Framebuffers\Native;

use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\ePaperFramebuffer;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferDriver;
use Surface\Contracts\Framebuffers\PagedFramebuffer;
use Surface\Contracts\Framebuffers\RingFramebuffer;

/** The five kinds with their bytes in PHP strings. Always available. */
class NativeFramebufferDriver implements FramebufferDriver
{
    public function driver(): string
    {
        return 'native';
    }

    public function full(FormatSpec $format, int $width, int $height): Framebuffer
    {
        return new NativeFullFramebuffer($format, $width, $height);
    }

    public function dirty(FormatSpec $format, int $width, int $height): DamageTrackingFramebuffer
    {
        return new NativeDirtyFramebuffer($format, $width, $height);
    }

    public function epaper(FormatSpec $format, int $width, int $height): ePaperFramebuffer
    {
        return new NativeePaperFramebuffer($format, $width, $height);
    }

    public function paged(FormatSpec $format, int $width, int $height, int $page_rows): PagedFramebuffer
    {
        return new NativePagedFramebuffer($format, $width, $height, $page_rows);
    }

    public function ring(FormatSpec $format, int $width, int $height, int $frames): RingFramebuffer
    {
        return new NativeRingFramebuffer($format, $width, $height, $frames);
    }
}
