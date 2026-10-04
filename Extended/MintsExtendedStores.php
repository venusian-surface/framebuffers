<?php

namespace Surface\Framebuffers\Extended;

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\PixelStore;

trait MintsExtendedStores
{
    protected function mint(FormatSpec $format, int $width, int $height): PixelStore
    {
        return new ExtendedPixelStore($format, $width, $height);
    }
}
