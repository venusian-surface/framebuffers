<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\PagedFramebuffer as PagedFramebufferContract;

trait ReadsBlitSources
{
    /**
     * What a blit takes from a source: its RGBA8 bytes, their width and height,
     * and how far down the source they start. A paged source gives its current
     * page; every other source gives its whole surface.
     *
     * @return array{string, int, int, int}
     */
    protected function blitSource(Framebuffer $source): array
    {
        if ($source instanceof PagedFramebufferContract) {
            $page = $source->pageRegion($source->page());

            return [$source->toRgba8(), $page->width, $page->height, $page->y];
        }

        return [$source->toRgba8(), $source->viewportWidth(), $source->viewportHeight(), 0];
    }
}
