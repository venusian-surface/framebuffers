<?php

namespace Surface\Framebuffers\Php;

use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferDriver;
use Surface\Contracts\Framebuffers\MultiFrameFramebuffer;
use Surface\Contracts\Framebuffers\PagedFramebuffer as PagedFramebufferContract;

/** The in-house driver: bytes in PHP strings. Always available. */
class PhpFramebufferDriver implements FramebufferDriver
{
    public function driver(): string
    {
        return 'php';
    }

    public function full(FormatSpec $format, int $width, int $height): Framebuffer
    {
        return new FullFramebuffer($format, $width, $height);
    }

    public function dirty(FormatSpec $format, int $width, int $height): DamageTrackingFramebuffer
    {
        return new DirtyFramebuffer($format, $width, $height);
    }

    public function epaper(FormatSpec $format, int $width, int $height): Framebuffer
    {
        return new EPaperFramebuffer($format, $width, $height);
    }

    public function paged(FormatSpec $format, int $width, int $height, int $page_rows): PagedFramebufferContract
    {
        return new PagedFramebuffer($format, $width, $height, $page_rows);
    }

    public function ring(FormatSpec $format, int $width, int $height, int $frames): MultiFrameFramebuffer
    {
        return new RingFramebuffer($format, $width, $height, $frames);
    }
}
