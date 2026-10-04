<?php

namespace Surface\Framebuffers\Extended;

use FbBuffer;
use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PixelStore;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\Layout;
use ValueError;

/**
 * The pixel glob in C: one FbBuffer. Every operation is one call into ext-fb;
 * what ext-fb refuses with ValueError is rethrown as FramebufferException.
 */
final class ExtendedPixelStore implements PixelStore
{
    private FbBuffer $buffer;

    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        Layout::size($width, $height);
        $this->buffer = new FbBuffer(FbFormats::from($format), $width, $height);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function format(): FormatSpec
    {
        return $this->format;
    }

    public function get(int $x, int $y): int
    {
        try {
            return $this->buffer->get($x, $y);
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function set(int $x, int $y, int $value): void
    {
        try {
            $this->buffer->set($x, $y, $value);
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function setPixels(array $pixels): ?Region
    {
        try {
            return self::region_of($this->buffer->setPixels($pixels));
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function setCoordinates(array $coordinates, int $value): ?Region
    {
        try {
            return self::region_of($this->buffer->setCoordinates($coordinates, $value));
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function rect(Region $region, int $value): void
    {
        try {
            $this->buffer->rect($region->x, $region->y, $region->width, $region->height, $value);
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function fill(int $value): void
    {
        $this->buffer->fill($value);
    }

    public function bytes(?int $layer = null): string
    {
        try {
            return is_null($layer) ? $this->buffer->bytes() : $this->buffer->layer($layer);
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function region(Region $region, FormatSpec $spec): string
    {
        $to = $spec->equals($this->format) ? null : FbFormats::from($spec);

        try {
            return $this->buffer->region($region->x, $region->y, $region->width, $region->height, $to);
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function toRgba8(): string
    {
        return $this->buffer->rgba8();
    }

    public function blitRgba8(string $rgba8, int $width, int $height, int $offset_x, int $offset_y, ?Region $clip = null): ?Region
    {
        try {
            return self::region_of($this->buffer->blitRgba8($rgba8, $width, $height, $offset_x, $offset_y, $clip?->x ?? 0, $clip?->y ?? 0, $clip?->width, $clip?->height));
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function paintSpans(string $spans, int $rgba8): ?Region
    {
        try {
            return self::region_of($this->buffer->paintSpans($spans, $rgba8));
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function paintRgba8(string $rgba8, int $width, int $height, array $inverse, Region $target, int $opacity, Filter $filter, int $row = 0): void
    {
        try {
            $this->buffer->paintRgba8($rgba8, $width, $height, $inverse, $target->x, $target->y, $target->width, $target->height, $opacity, $filter === Filter::LINEAR, $row);
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function copy(PixelStore $source, Region $region): void
    {
        if ($source->width() !== $this->width || $source->height() !== $this->height || ! $source->format()->equals($this->format)) {
            throw new FramebufferException('copy() takes a store of the same size and format.');
        }

        try {
            if ($source instanceof self) {
                $this->buffer->copy($source->buffer, $region->x, $region->y, $region->width, $region->height);

                return;
            }

            // A store of another flavor: check the rect, then move the words one by one.
            $this->buffer->region($region->x, $region->y, $region->width, $region->height);
            for ($y = $region->y; $y < $region->bottom(); $y++) {
                for ($x = $region->x; $x < $region->right(); $x++) {
                    $this->buffer->set($x, $y, $source->get($x, $y));
                }
            }
        } catch (ValueError $e) {
            throw new FramebufferException($e->getMessage(), previous: $e);
        }
    }

    public function plane(int $value): string
    {
        return $this->buffer->plane($value);
    }

    public function pointer(): int
    {
        return $this->buffer->pointer();
    }

    public function granularity(): DamageGranularity
    {
        [$unit_width, $unit_height] = $this->buffer->granularity();

        return new DamageGranularity($unit_width, $unit_height, $this->width, $this->height);
    }

    /** @param array{int, int, int, int}|null $rect */
    private static function region_of(?array $rect): ?Region
    {
        return is_null($rect) ? null : new Region(...$rect);
    }
}
