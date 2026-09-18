<?php

namespace Surface\Framebuffers\Php;

use Surface\Contracts\Framebuffers\BitDepth;
use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PagedFramebuffer as PagedFramebufferContract;
use Surface\Contracts\Framebuffers\PageAxis;
use Surface\Contracts\Framebuffers\PixelFormat;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Framebuffers\PixelMapper;

/**
 * One page_rows-tall window over a width x height virtual surface. Writes
 * outside the current page are dropped, reads outside answer 0, flushes
 * answer the current page. setPage() zeroes the window. Nothing full-size is
 * ever held.
 */
class PagedFramebuffer implements PagedFramebufferContract
{
    protected FullFramebuffer $window;

    protected int $page = 0;

    protected int $pages;

    protected PixelMapper $mapper;

    public function __construct(
        protected FormatSpec $format,
        protected int $width,
        protected int $height,
        protected int $page_rows,
    ) {
        if ($page_rows < 1) {
            throw FramebufferException::pageRows($page_rows, 'must be at least 1.');
        }
        if ($format->pixel_format === PixelFormat::MONO_VERTICAL_PAGE && ($format->page_axis ?? PageAxis::VERTICAL) === PageAxis::VERTICAL && $page_rows % 8 !== 0) {
            throw FramebufferException::pageRows($page_rows, 'a vertical-page host needs a multiple of 8.');
        }
        $this->pages = intdiv($height + $page_rows - 1, $page_rows);
        $this->window = new FullFramebuffer($format, $width, $page_rows);
        $this->mapper = $this->window->mapper();
    }

    public function pageRows(): int
    {
        return $this->page_rows;
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function setPage(int $page): void
    {
        if ($page < 0 || $page >= $this->pages) {
            throw new FramebufferException("Page {$page} is outside 0..".($this->pages - 1).'.');
        }
        $this->page = $page;
        $this->window->clear();
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pageRegion(int $page): Region
    {
        $top = $page * $this->page_rows;

        return new Region(0, $top, $this->width, min($this->page_rows, $this->height - $top));
    }

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->format;
    }

    public function getPixel(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw FramebufferException::outOfRange($x, $y, $this->width, $this->height);
        }
        $top = $this->page * $this->page_rows;
        if ($y < $top || $y >= $top + $this->page_rows) {
            return 0;
        }

        return $this->window->getPixel($x, $y - $top);
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw FramebufferException::outOfRange($x, $y, $this->width, $this->height);
        }
        $top = $this->page * $this->page_rows;
        if ($y >= $top && $y < $top + $this->page_rows) {
            $this->window->setPixel($x, $y - $top, $value);
        }

        return $this;
    }

    public function setPixels(array $pixels): static
    {
        foreach ($pixels as [$x, $y, $value]) {
            $this->setPixel($x, $y, $value);
        }

        return $this;
    }

    public function setRegion(array $coordinates, int $value): static
    {
        foreach ($coordinates as [$x, $y]) {
            $this->setPixel($x, $y, $value);
        }

        return $this;
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $r = (new Region($x, $y, $width, $height))->intersect($this->pageRegion($this->page));
        if (! is_null($r)) {
            $this->window->setSegment($r->x, $r->y - $this->page * $this->page_rows, $r->width, $r->height, $color);
        }

        return $this;
    }

    public function clear(): static
    {
        $this->window->clear();

        return $this;
    }

    public function fill(int $color): static
    {
        $this->window->fill($color);

        return $this;
    }

    public function blitTo(Framebuffer $target, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(Framebuffer $source, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        $rgba = $source->toRgba8();
        $sw = $source->viewportWidth();
        $sh = $source->viewportHeight();
        for ($y = 0; $y < $sh; $y++) {
            $ty = $y + $offset_y;
            if ($ty < 0 || $ty >= $this->height) {
                continue;
            }
            for ($x = 0; $x < $sw; $x++) {
                $tx = $x + $offset_x;
                if ($tx < 0 || $tx >= $this->width) {
                    continue;
                }
                $i = ($y * $sw + $x) * 4;
                $c = new Color(ord($rgba[$i]) / 255, ord($rgba[$i + 1]) / 255, ord($rgba[$i + 2]) / 255, ord($rgba[$i + 3]) / 255);
                $this->setPixel($tx, $ty, $this->mapper->map($c));
            }
        }

        return $this;
    }

    public function dump(?int $layer = null): string
    {
        return $this->window->dump($layer);
    }

    /** The current page only, sized to its real rows. */
    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        $r = $this->pageRegion($this->page);

        return $this->window->flushRegion(new Region(0, 0, $r->width, $r->height), $spec, $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        $r = $region->intersect($this->pageRegion($this->page));
        if (is_null($r)) {
            return $as_array ? [] : '';
        }

        return $this->window->flushRegion(new Region($r->x, $r->y - $this->page * $this->page_rows, $r->width, $r->height), $spec, $as_array);
    }

    /** The current page's rows only. */
    public function toRgba8(): string
    {
        $rows = $this->pageRegion($this->page)->height;

        return substr($this->window->toRgba8(), 0, $rows * $this->width * 4);
    }

    public function damageGranularity(): DamageGranularity
    {
        $g = $this->window->damageGranularity();

        return new DamageGranularity($this->width, max($g->unit_height, $this->page_rows), $this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        return false;
    }

    public function pointer(): int
    {
        return 0;
    }
}
