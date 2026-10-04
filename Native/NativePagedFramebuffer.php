<?php

namespace Surface\Framebuffers\Native;

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Framebuffers\PagedFramebuffer;
use Surface\Framebuffers\StoreFramebuffer;

class NativePagedFramebuffer extends PagedFramebuffer
{
    protected function window(FormatSpec $format, int $width, int $rows): StoreFramebuffer
    {
        return new NativeFullFramebuffer($format, $width, $rows);
    }
}
