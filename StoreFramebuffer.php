<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PixelStore;
use Surface\Contracts\Framebuffers\Region;
use Surface\NutsAndBolts\Affine;

/**
 * The whole Framebuffer contract over one PixelStore. Subclasses say where the
 * store comes from (mint) and what a write means (touched). A write outside
 * the surface is a caller bug and throws; setSegment() clips instead, since a
 * rect straddling the edge is ordinary.
 */
abstract class StoreFramebuffer implements Framebuffer
{
    use ReadsBlitSources;

    protected PixelStore $store;

    public function __construct(
        protected FormatSpec $format,
        protected int $width,
        protected int $height,
    ) {
        $this->store = $this->mint($format, $width, $height);
    }

    abstract protected function mint(FormatSpec $format, int $width, int $height): PixelStore;

    public function store(): PixelStore
    {
        return $this->store;
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

        return $this->store->get($x, $y);
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $this->store->set($x, $y, $value);
        $this->touched(new Region($x, $y, 1, 1));

        return $this;
    }

    public function setPixels(array $pixels): static
    {
        return $this->wrote($this->store->setPixels($pixels));
    }

    public function setRegion(array $coordinates, int $value): static
    {
        return $this->wrote($this->store->setCoordinates($coordinates, $value));
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $region = (new Region($x, $y, $width, $height))->intersect(Region::wholeSurface($this->width, $this->height));
        if (is_null($region)) {
            return $this;
        }
        $this->store->rect($region, $color);

        return $this->wrote($region);
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        return $this->wrote($this->store->paintSpans($spans, $rgba8));
    }

    public function paintImage(Framebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null): static
    {
        [$rgba8, $width, $height, $top] = $this->blitSource($source);
        $plan = ImagePlacement::plan($placement, $width, $height, $top, Region::wholeSurface($this->width, $this->height), $clip, $opacity);
        if (is_null($plan)) {
            return $this;
        }
        [$target, $inverse] = $plan;
        $this->store->paintRgba8($rgba8, $width, $height, $inverse, $target, $opacity, $filter);

        return $this->wrote($target);
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        $this->store->fill($color);

        return $this->wrote(Region::wholeSurface($this->width, $this->height));
    }

    public function blitTo(Framebuffer $target, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(Framebuffer $source, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        [$rgba8, $width, $height, $top] = $this->blitSource($source);

        return $this->wrote($this->store->blitRgba8($rgba8, $width, $height, $offset_x, $offset_y + $top));
    }

    public function dump(?int $layer = null): string
    {
        return $this->store->bytes($layer);
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return self::answer($this->store->region(Region::wholeSurface($this->width, $this->height), $spec), $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }

        return self::answer($this->store->region($region, $spec), $as_array);
    }

    public function toRgba8(): string
    {
        return $this->store->toRgba8();
    }

    public function pointer(): int
    {
        return $this->store->pointer();
    }

    public function damageGranularity(): DamageGranularity
    {
        return $this->store->granularity();
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    /** Subclasses that track damage override this; every write path calls it once per region written. */
    protected function touched(Region $region): void {}

    protected function wrote(?Region $region): static
    {
        if (! is_null($region)) {
            $this->touched($region);
        }

        return $this;
    }

    protected function guard(int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw FramebufferException::outOfRange($x, $y, $this->width, $this->height);
        }
    }

    /** @return string|list<int> */
    public static function answer(string $bytes, bool $as_array): string|array
    {
        return $as_array ? array_values(unpack('C*', $bytes) ?: []) : $bytes;
    }
}
