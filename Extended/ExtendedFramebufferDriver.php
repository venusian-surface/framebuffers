<?php

namespace Surface\Framebuffers\Extended;

use FbBuffer;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\ePaperFramebuffer;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferDriver;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PagedFramebuffer;
use Surface\Contracts\Framebuffers\RingFramebuffer;

/** The five kinds with their bytes in C, through ext-fb. */
class ExtendedFramebufferDriver implements FramebufferDriver
{
    /** @throws FramebufferException When this PHP has no ext-fb, or one older than 0.10 (which has no FbBuffer class). */
    public function __construct()
    {
        if (! class_exists(FbBuffer::class, false)) {
            throw FramebufferException::extensionMissing('fb');
        }
    }

    public function driver(): string
    {
        return 'extended';
    }

    public function full(FormatSpec $format, int $width, int $height): Framebuffer
    {
        return new ExtendedFullFramebuffer($format, $width, $height);
    }

    public function dirty(FormatSpec $format, int $width, int $height): DamageTrackingFramebuffer
    {
        return new ExtendedDirtyFramebuffer($format, $width, $height);
    }

    public function epaper(FormatSpec $format, int $width, int $height): ePaperFramebuffer
    {
        return new ExtendedePaperFramebuffer($format, $width, $height);
    }

    public function paged(FormatSpec $format, int $width, int $height, int $page_rows): PagedFramebuffer
    {
        return new ExtendedPagedFramebuffer($format, $width, $height, $page_rows);
    }

    public function ring(FormatSpec $format, int $width, int $height, int $frames): RingFramebuffer
    {
        return new ExtendedRingFramebuffer($format, $width, $height, $frames);
    }
}
