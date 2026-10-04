<?php

namespace Surface\Framebuffers\Extended;

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Framebuffers\DirtyFramebuffer;
use Surface\Framebuffers\RingFramebuffer;

class ExtendedRingFramebuffer extends RingFramebuffer
{
    protected function slot(FormatSpec $format, int $width, int $height): DirtyFramebuffer
    {
        return new ExtendedDirtyFramebuffer($format, $width, $height);
    }
}
