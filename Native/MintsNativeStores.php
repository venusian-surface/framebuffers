<?php

namespace Surface\Framebuffers\Native;

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\PixelStore;

trait MintsNativeStores
{
    protected function mint(FormatSpec $format, int $width, int $height): PixelStore
    {
        return new NativePixelStore($format, $width, $height);
    }
}
