<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PageAxis;
use Surface\Contracts\Framebuffers\PagedFramebuffer as PagedFramebufferContract;
use Surface\Contracts\Framebuffers\PixelFormat;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\Spans;
use Surface\NutsAndBolts\Affine;

/**
 * One page_rows-tall window over a width x height virtual surface, in any
 * format. Nothing full-size is ever held. The caller selects a page, draws the
 * whole picture (writes outside the page are dropped), drains the page, and
 * moves on; pagesTouching() names the pages a partial refresh needs.
 */
abstract class PagedFramebuffer implements PagedFramebufferContract
{
    use ReadsBlitSources;

    protected StoreFramebuffer $window;

    protected int $page = 0;

    protected int $pages;

    public function __construct(
        protected FormatSpec $format,
        protected int $width,
        protected int $height,
        protected int $page_rows,
    ) {
        if ($page_rows < 1) {
            throw FramebufferException::pageRows($page_rows, 'must be at least 1.');
        }
        if ($format->pixel_format === PixelFormat::MONO_VERTICAL_PAGE
            && ($format->page_axis ?? PageAxis::VERTICAL) === PageAxis::VERTICAL
            && $page_rows % 8 !== 0) {
            throw FramebufferException::pageRows($page_rows, 'a vertical-page host needs a multiple of 8.');
        }
        Layout::size($width, $height);

        $this->pages = intdiv($height + $page_rows - 1, $page_rows);
        $this->window = $this->window($format, $width, $page_rows);
    }

    /** The page's own buffer: a full framebuffer of this flavor, $rows tall. */
    abstract protected function window(FormatSpec $format, int $width, int $rows): StoreFramebuffer;

    public function pageRows(): int
    {
        return $this->page_rows;
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function setPage(int $page): static
    {
        if ($page < 0 || $page >= $this->pages) {
            throw new FramebufferException("Page {$page} is outside 0..".($this->pages - 1).'.');
        }
        $this->page = $page;
        $this->window->clear();

        return $this;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pageRegion(int $page): Region
    {
        if ($page < 0 || $page >= $this->pages) {
            throw new FramebufferException("Page {$page} is outside 0..".($this->pages - 1).'.');
        }
        $top = $page * $this->page_rows;

        return new Region(0, $top, $this->width, min($this->page_rows, $this->height - $top));
    }

    public function pagesTouching(Region $region): array
    {
        $clipped = $region->intersect(Region::wholeSurface($this->width, $this->height));
        if (is_null($clipped)) {
            return [];
        }

        return range(intdiv($clipped->y, $this->page_rows), intdiv($clipped->bottom() - 1, $this->page_rows));
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
        $this->guard($x, $y);
        $top = $this->page * $this->page_rows;

        return $y >= $top && $y < $top + $this->page_rows ? $this->window->getPixel($x, $y - $top) : 0;
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $top = $this->page * $this->page_rows;
        if ($y >= $top && $y < $top + $this->page_rows) {
            $this->window->setPixel($x, $y - $top, $value);
        }

        return $this;
    }

    public function setPixels(array $pixels): static
    {
        foreach ($pixels as [$x, $y]) {
            $this->guard($x, $y);
        }
        foreach ($pixels as [$x, $y, $value]) {
            $this->setPixel($x, $y, $value);
        }

        return $this;
    }

    public function setRegion(array $coordinates, int $value): static
    {
        foreach ($coordinates as [$x, $y]) {
            $this->guard($x, $y);
        }
        foreach ($coordinates as [$x, $y]) {
            $this->setPixel($x, $y, $value);
        }

        return $this;
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $page = $this->pageRegion($this->page);
        $region = (new Region($x, $y, $width, $height))->intersect($page);
        if (! is_null($region)) {
            $this->window->setSegment($region->x, $region->y - $page->y, $region->width, $region->height, $color);
        }

        return $this;
    }

    /** Spans are checked against the whole virtual surface; the rows off the current page are dropped. */
    public function paintSpans(string $spans, int $rgba8): static
    {
        $page = $this->pageRegion($this->page);
        $kept = '';
        foreach (SpanList::read($spans, $rgba8, $this->width, $this->height)[0] as [$y, $x, $length, $coverage]) {
            if ($y >= $page->y && $y < $page->bottom()) {
                $kept .= Spans::pack($y - $page->y, $x, $length, $coverage);
            }
        }
        if ($kept !== '') {
            $this->window->paintSpans($kept, $rgba8);
        }

        return $this;
    }

    /** Planned against the current page's rows of the virtual surface, then painted page-relative. */
    public function paintImage(Framebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null): static
    {
        [$rgba8, $width, $height, $top] = $this->blitSource($source);
        $page = $this->pageRegion($this->page);
        $plan = ImagePlacement::plan($placement, $width, $height, $top, $page, $clip, $opacity);
        if (is_null($plan)) {
            return $this;
        }
        [$target, $inverse] = $plan;
        $this->window->store()->paintRgba8($rgba8, $width, $height, $inverse, new Region($target->x, $target->y - $page->y, $target->width, $target->height), $opacity, $filter, $page->y);

        return $this;
    }

    public function clear(): static
    {
        $this->window->clear();

        return $this;
    }

    public function fill(int $color): static
    {
        $page = $this->pageRegion($this->page);
        $this->window->setSegment(0, 0, $page->width, $page->height, $color);

        return $this;
    }

    public function blitTo(Framebuffer $target, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(Framebuffer $source, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        [$rgba8, $width, $height, $top] = $this->blitSource($source);

        return $this->writeRgba8($rgba8, $width, $height, $offset_x, $offset_y + $top);
    }

    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        $page = $this->pageRegion($this->page);
        $this->window->store()->blitRgba8($rgba8, $width, $height, $x, $y - $page->y, new Region(0, 0, $page->width, $page->height));

        return $this;
    }

    /** The window's raw bytes: page_rows tall even on a short last page. */
    public function dump(?int $layer = null): string
    {
        return $this->window->dump($layer);
    }

    /** The current page only, sized to its real rows. */
    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        $page = $this->pageRegion($this->page);

        return $this->window->flushRegion(new Region(0, 0, $page->width, $page->height), $spec, $as_array);
    }

    /** The part of $region on the current page; empty when it misses the page. */
    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        $page = $this->pageRegion($this->page);
        $clipped = $region->intersect($page);
        if (is_null($clipped)) {
            return $as_array ? [] : '';
        }

        return $this->window->flushRegion(new Region($clipped->x, $clipped->y - $page->y, $clipped->width, $clipped->height), $spec, $as_array);
    }

    /** The current page's rows only. */
    public function toRgba8(): string
    {
        return substr($this->window->toRgba8(), 0, $this->pageRegion($this->page)->height * $this->width * 4);
    }

    public function pointer(): int
    {
        return $this->window->pointer();
    }

    public function damageGranularity(): DamageGranularity
    {
        $unit = $this->window->damageGranularity();

        return new DamageGranularity($this->width, max($unit->unit_height, $this->page_rows), $this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        return false;
    }

    protected function guard(int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw FramebufferException::outOfRange($x, $y, $this->width, $this->height);
        }
    }
}
