<?php

namespace Surface\Framebuffers\Native;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PixelStore;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\ScanDirection;
use Surface\Framebuffers\Layout;
use Surface\Framebuffers\Native\Packings\Packing;
use Surface\Framebuffers\PixelMapper;
use Surface\Framebuffers\PixelMapperMode;
use Surface\Framebuffers\SpanList;

/** The pixel glob in PHP: one string in the host format, one Packing that knows its layout. */
final class NativePixelStore implements PixelStore
{
    private string $bytes;

    private Packing $packing;

    private PixelMapper $mapper;

    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        Layout::size($width, $height);
        $this->packing = Packing::for($format, $width, $height);
        $this->mapper = PixelMapper::for($format);
        $this->bytes = $this->packing->blank();
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
        $this->guard($x, $y);

        return $this->packing->get($this->bytes, $x, $y);
    }

    public function set(int $x, int $y, int $value): void
    {
        $this->guard($x, $y);
        $this->packing->set($this->bytes, $x, $y, $value);
    }

    public function setPixels(array $pixels): ?Region
    {
        $bounds = $this->bounds($pixels, 3, '[x, y, value]');
        foreach ($pixels as [$x, $y, $value]) {
            $this->packing->set($this->bytes, $x, $y, $value);
        }

        return $bounds;
    }

    public function setCoordinates(array $coordinates, int $value): ?Region
    {
        $bounds = $this->bounds($coordinates, 2, '[x, y]');
        foreach ($coordinates as [$x, $y]) {
            $this->packing->set($this->bytes, $x, $y, $value);
        }

        return $bounds;
    }

    public function rect(Region $region, int $value): void
    {
        $this->inside($region);
        for ($y = $region->y; $y < $region->bottom(); $y++) {
            $this->packing->span($this->bytes, $region->x, $y, $region->width, $value);
        }
    }

    public function fill(int $value): void
    {
        $this->packing->fill($this->bytes, $value);
    }

    public function bytes(?int $layer = null): string
    {
        if (is_null($layer)) {
            return $this->bytes;
        }
        if ($layer < 0 || $layer >= $this->packing->layers()) {
            throw new FramebufferException("Layer {$layer} is outside 0..".($this->packing->layers() - 1).'.');
        }

        return $this->packing->layer($this->bytes, $layer);
    }

    public function region(Region $region, FormatSpec $spec): string
    {
        $this->inside($region);
        if ($spec->equals($this->format)) {
            return $this->packing->region($this->bytes, $region);
        }

        $sub = Packing::for($spec, $region->width, $region->height);
        $target = PixelMapper::for($spec);
        $out = $sub->blank();
        $reversed = $spec->scan_direction === ScanDirection::BOTTOM_TO_TOP;
        for ($y = 0; $y < $region->height; $y++) {
            $row = $reversed ? $region->height - 1 - $y : $y;
            for ($x = 0; $x < $region->width; $x++) {
                $rgba = $this->mapper->toRgba8($this->packing->get($this->bytes, $region->x + $x, $region->y + $y));
                $sub->set($out, $x, $row, $target->fromRgba8(($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF));
            }
        }

        return $out;
    }

    public function toRgba8(): string
    {
        $out = '';
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $out .= pack('N', $this->mapper->toRgba8($this->packing->get($this->bytes, $x, $y)));
            }
        }

        return $out;
    }

    public function blitRgba8(string $rgba8, int $width, int $height, int $offset_x, int $offset_y, ?Region $clip = null): ?Region
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("blitRgba8() takes {$width}x{$height} RGBA8 pixels (".($width * $height * 4).' bytes), got '.strlen($rgba8).'.');
        }

        $target = (new Region($offset_x, $offset_y, $width, $height))->intersect(Region::wholeSurface($this->width, $this->height));
        if (! is_null($target) && ! is_null($clip)) {
            $target = $target->intersect($clip);
        }
        if (is_null($target)) {
            return null;
        }

        for ($y = $target->y; $y < $target->bottom(); $y++) {
            $i = (($y - $offset_y) * $width + $target->x - $offset_x) * 4;
            for ($x = $target->x; $x < $target->right(); $x++, $i += 4) {
                $this->packing->set($this->bytes, $x, $y, $this->mapper->fromRgba8(ord($rgba8[$i]), ord($rgba8[$i + 1]), ord($rgba8[$i + 2]), ord($rgba8[$i + 3])));
            }
        }

        return $target;
    }

    public function paintSpans(string $spans, int $rgba8): ?Region
    {
        [$list, $bounds] = SpanList::read($spans, $rgba8, $this->width, $this->height);
        [$red, $green, $blue, $alpha] = [($rgba8 >> 24) & 0xFF, ($rgba8 >> 16) & 0xFF, ($rgba8 >> 8) & 0xFF, $rgba8 & 0xFF];
        $word = $this->mapper->fromRgba8($red, $green, $blue, $alpha);
        $blends = in_array($this->mapper->mode(), [PixelMapperMode::RGB, PixelMapperMode::GREY], true);

        foreach ($list as [$y, $x, $length, $coverage]) {
            $a = intdiv($alpha * $coverage + 127, 255);
            if ($a === 0 || (! $blends && $a < 128)) {
                continue;
            }
            if ($a === 255 || ! $blends) {
                $this->packing->span($this->bytes, $x, $y, $length, $word);

                continue;
            }
            for ($i = $x; $i < $x + $length; $i++) {
                $d = $this->mapper->toRgba8($this->packing->get($this->bytes, $i, $y));
                $this->packing->set($this->bytes, $i, $y, $this->mapper->fromRgba8(
                    intdiv($red * $a + (($d >> 24) & 0xFF) * (255 - $a) + 127, 255),
                    intdiv($green * $a + (($d >> 16) & 0xFF) * (255 - $a) + 127, 255),
                    intdiv($blue * $a + (($d >> 8) & 0xFF) * (255 - $a) + 127, 255),
                    intdiv(255 * $a + ($d & 0xFF) * (255 - $a) + 127, 255),
                ));
            }
        }

        return $bounds;
    }

    public function copy(PixelStore $source, Region $region): void
    {
        if ($source->width() !== $this->width || $source->height() !== $this->height || ! $source->format()->equals($this->format)) {
            throw new FramebufferException('copy() takes a store of the same size and format.');
        }
        $this->inside($region);

        if ($source instanceof self && $region->width === $this->width && $region->height === $this->height) {
            $this->bytes = $source->bytes;

            return;
        }
        for ($y = $region->y; $y < $region->bottom(); $y++) {
            for ($x = $region->x; $x < $region->right(); $x++) {
                $this->packing->set($this->bytes, $x, $y, $source->get($x, $y));
            }
        }
    }

    public function plane(int $value): string
    {
        $row_bytes = intdiv($this->width + 7, 8);
        $out = str_repeat("\0", $row_bytes * $this->height);
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if ($this->packing->get($this->bytes, $x, $y) === $value) {
                    $i = $y * $row_bytes + ($x >> 3);
                    $out[$i] = chr(ord($out[$i]) | (0x80 >> ($x & 7)));
                }
            }
        }

        return $out;
    }

    public function pointer(): int
    {
        return 0;
    }

    public function granularity(): DamageGranularity
    {
        return $this->packing->granularity();
    }

    private function guard(int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw FramebufferException::outOfRange($x, $y, $this->width, $this->height);
        }
    }

    private function inside(Region $region): void
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }
    }

    /**
     * Check a whole pixel list before anything is written, and answer its bounding box.
     *
     * @param  array<int, array<int, int>>  $pixels
     */
    private function bounds(array $pixels, int $arity, string $shape): ?Region
    {
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        $position = 0;
        foreach ($pixels as $pixel) {
            if (! is_array($pixel) || count($pixel) !== $arity || ! is_int($pixel[0] ?? null) || ! is_int($pixel[1] ?? null) || ($arity === 3 && ! is_int($pixel[2] ?? null))) {
                throw new FramebufferException("Pixel {$position} is not {$shape}.");
            }
            $position++;
            $this->guard($pixel[0], $pixel[1]);
            $left = min($left, $pixel[0]);
            $top = min($top, $pixel[1]);
            $right = max($right, $pixel[0]);
            $bottom = max($bottom, $pixel[1]);
        }

        return $pixels === [] ? null : new Region($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
