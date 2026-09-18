<?php

namespace Surface\Framebuffers\Php;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\ScanDirection;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Framebuffers\Packings\Packing;
use Surface\Framebuffers\PixelMapper;

/**
 * One packing over one PHP string in the host format. The whole Framebuffer
 * contract lives here; subclasses add damage tracking or ePaper rules by
 * overriding touched() and the constructor. A write outside the surface is a
 * caller bug and throws — the rasterizer clips before it writes.
 */
abstract class PackedGrid implements Framebuffer
{
    protected string $bytes;

    protected Packing $packing;

    protected PixelMapper $mapper;

    public function __construct(
        protected FormatSpec $format,
        protected int $width,
        protected int $height,
    ) {
        if ($width < 1 || $height < 1) {
            throw new FramebufferException("A framebuffer needs a positive size, got {$width}x{$height}.");
        }
        $this->packing = Packing::for($format, $width, $height);
        $this->mapper = PixelMapper::for($format);
        $this->bytes = $this->packing->blank();
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

    public function mapper(): PixelMapper
    {
        return $this->mapper;
    }

    /** The raw store, top-down, host format. */
    public function bytes(): string
    {
        return $this->bytes;
    }

    public function getPixel(int $x, int $y): int
    {
        $this->guard($x, $y);

        return $this->packing->get($this->bytes, $x, $y);
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $this->packing->set($this->bytes, $x, $y, $value);
        $this->touched(new Region($x, $y, 1, 1));

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
        $r = (new Region($x, $y, $width, $height))->intersect(Region::wholeSurface($this->width, $this->height));
        if (is_null($r)) {
            return $this;
        }
        for ($row = $r->y; $row < $r->bottom(); $row++) {
            $this->packing->span($this->bytes, $r->x, $row, $r->width, $color);
        }
        $this->touched($r);

        return $this;
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        $this->packing->fill($this->bytes, $color);
        $this->touched(Region::wholeSurface($this->width, $this->height));

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
        return $this->packing->layer($this->bytes, $layer);
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return $this->answer($this->flushRegionBytes(Region::wholeSurface($this->width, $this->height), $spec), $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        return $this->answer($this->flushRegionBytes($region, $spec), $as_array);
    }

    public function toRgba8(): string
    {
        return $this->packing->toRgba8($this->bytes, $this->mapper);
    }

    public function damageGranularity(): DamageGranularity
    {
        return $this->packing->granularity();
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    public function pointer(): int
    {
        return 0;
    }

    /** Subclasses that track damage override this; every write path calls it once per region. */
    protected function touched(Region $region): void {}

    protected function guard(int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw FramebufferException::outOfRange($x, $y, $this->width, $this->height);
        }
    }

    /** Same spec: the packing's own region bytes. Other spec: pixel by pixel through RGBA, rows in the target's scan order. */
    protected function flushRegionBytes(Region $r, FormatSpec $spec): string
    {
        if ($spec->equals($this->format)) {
            return $this->packing->region($this->bytes, $r);
        }
        $sub = Packing::for($spec, $r->width, $r->height);
        $target = PixelMapper::for($spec);
        $out = $sub->blank();
        $reversed = $spec->scan_direction === ScanDirection::BOTTOM_TO_TOP;
        for ($y = 0; $y < $r->height; $y++) {
            $yy = $reversed ? $r->height - 1 - $y : $y;
            for ($x = 0; $x < $r->width; $x++) {
                $sub->set($out, $x, $yy, $target->map($this->mapper->unmap($this->packing->get($this->bytes, $r->x + $x, $r->y + $y))));
            }
        }

        return $out;
    }

    /** @return string|list<int> */
    protected function answer(string $bytes, bool $as_array): string|array
    {
        return $as_array ? array_values(unpack('C*', $bytes)) : $bytes;
    }
}
