<?php

namespace Surface\Framebuffers\Extended;

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Framebuffers\PagedFramebuffer;
use Surface\Framebuffers\StoreFramebuffer;

class ExtendedPagedFramebuffer extends PagedFramebuffer
{
    protected function window(FormatSpec $format, int $width, int $rows): StoreFramebuffer
    {
        return new ExtendedFullFramebuffer($format, $width, $rows);
    }
}
