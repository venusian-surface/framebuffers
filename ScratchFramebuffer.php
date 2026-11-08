<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\PixelStore;

/** A blank RGBA8 frame made by the store kind of another framebuffer: native stays native, extended stays in C. */
final class ScratchFramebuffer extends FullFramebuffer
{
    public function __construct(private readonly StoreFramebuffer $kind, int $width, int $height)
    {
        parent::__construct(FormatSpec::rgba8(), $width, $height);
    }

    protected function mint(FormatSpec $format, int $width, int $height): PixelStore
    {
        return $this->kind->mint($format, $width, $height);
    }
}
