<?php

namespace Surface\Framebuffers\Native;

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Framebuffers\DirtyFramebuffer;
use Surface\Framebuffers\RingFramebuffer;

class NativeRingFramebuffer extends RingFramebuffer
{
    protected function slot(FormatSpec $format, int $width, int $height): DirtyFramebuffer
    {
        return new NativeDirtyFramebuffer($format, $width, $height);
    }
}
