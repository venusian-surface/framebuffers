<?php

namespace Surface\Framebuffers;

use Closure;
use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\GLFramebuffer as GLFramebufferContract;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\Native\NativeDirtyFramebuffer;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Affine;

/**
 * Everything engine-neutral about a GPU engine's framebuffer. A package's
 * class supplies the target's size and two GPU calls: read a region back as
 * RGBA8, and upload RGBA8 over a region. A target at a new size is a new
 * GLFramebuffer.
 *
 * Reads go to the GPU each time. Writes run on a CPU copy read back once and
 * kept until the engine next draws; what each write changed is uploaded and
 * recorded as damage.
 */
abstract class GLFramebuffer implements GLFramebufferContract
{
    protected DamageRecord $record;

    /** The target's pixels on the CPU while they are current; null once the engine has drawn past them. */
    private ?NativeDirtyFramebuffer $shadow = null;

    private ?Framebuffer $staging = null;

    public function __construct()
    {
        $this->record = new DamageRecord();
    }

    abstract public function width(): int;

    abstract public function height(): int;

    /**
     * $region of the target as RGBA8, top row first, once the frame that last drew it has finished.
     *
     * @return string $region->width × $region->height × 4 bytes
     */
    abstract public function readRgba8(Region $region): string;

    /** Replace $region of the target with RGBA8 bytes, top row first. */
    abstract public function uploadRgba8(string $rgba8, Region $region): void;

    public function viewportWidth(): int
    {
        return $this->width();
    }

    public function viewportHeight(): int
    {
        return $this->height();
    }

    public function hostFormat(): FormatSpec
    {
        return FormatSpec::rgba8();
    }

    public function drawn(array $regions): static
    {
        $this->shadow = null;
        foreach ($regions as $region) {
            $this->record->add($region);
        }

        return $this;
    }

    public function beginEpoch(): static
    {
        $this->record->clear();

        return $this;
    }

    public function damage(): array
    {
        return $this->record->regions($this->damageGranularity());
    }

    public function stageIn(?Framebuffer $staging): static
    {
        if (! is_null($staging) && ($staging->viewportWidth() !== $this->width() || $staging->viewportHeight() !== $this->height())) {
            throw new FramebufferException("A staging copy is the size of its target: {$this->width()} × {$this->height()}, got {$staging->viewportWidth()} × {$staging->viewportHeight()}.");
        }
        $this->staging = $staging;

        return $this;
    }

    public function stage(Region $region): Framebuffer
    {
        $staging = $this->staging ?? throw new FramebufferException('No staging copy is set: stageIn() one first.');
        $staging->writeRgba8($this->readRgba8($region), $region->width, $region->height, $region->x, $region->y);

        return $staging;
    }

    public function getPixel(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->width() || $y >= $this->height()) {
            throw FramebufferException::outOfRange($x, $y, $this->width(), $this->height());
        }
        $rgba = $this->readRgba8(new Region($x, $y, 1, 1));

        return PixelMapper::for(FormatSpec::rgba8())->fromRgba8(ord($rgba[0]), ord($rgba[1]), ord($rgba[2]), ord($rgba[3]));
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        return $this->through(fn (Framebuffer $copy) => $copy->setPixel($x, $y, $value));
    }

    public function setPixels(array $pixels): static
    {
        return $this->through(fn (Framebuffer $copy) => $copy->setPixels($pixels));
    }

    public function setRegion(array $coordinates, int $value): static
    {
        return $this->through(fn (Framebuffer $copy) => $copy->setRegion($coordinates, $value));
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        return $this->through(fn (Framebuffer $copy) => $copy->setSegment($x, $y, $width, $height, $color));
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        return $this->through(fn (Framebuffer $copy) => $copy->paintSpans($spans, $rgba8));
    }

    public function paintImage(Framebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null): static
    {
        return $this->through(fn (Framebuffer $copy) => $copy->paintImage($source, $placement, $opacity, $filter, $clip));
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        return $this->through(fn (Framebuffer $copy) => $copy->fill($color));
    }

    public function blitTo(Framebuffer $target, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(Framebuffer $source, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        return $this->through(fn (Framebuffer $copy) => $copy->blitFrom($source, $offset_x, $offset_y));
    }

    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        return $this->through(fn (Framebuffer $copy) => $copy->writeRgba8($rgba8, $width, $height, $x, $y));
    }

    public function dump(?int $layer = null): string
    {
        return $this->toRgba8();
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return $this->flushRegion($this->whole(), $spec, $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width() || $region->bottom() > $this->height()) {
            throw FramebufferException::outsideSurface($region, $this->width(), $this->height());
        }
        $rgba8 = $this->readRgba8($region);
        if ($spec->equals(FormatSpec::rgba8())) {
            return StoreFramebuffer::answer($rgba8, $as_array);
        }

        // Packed as a native framebuffer packs: the region alone, as its own surface.
        $part = new NativeFullFramebuffer(FormatSpec::rgba8(), $region->width, $region->height);
        $part->writeRgba8($rgba8, $region->width, $region->height);

        return $part->flush($spec, $as_array);
    }

    public function toRgba8(): string
    {
        return $this->readRgba8($this->whole());
    }

    /** The staging copy's address while one is set; 0 otherwise: the bytes are on the GPU. */
    public function pointer(): int
    {
        return $this->staging?->pointer() ?? 0;
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width(), $this->height());
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    protected function whole(): Region
    {
        return Region::wholeSurface($this->width(), $this->height());
    }

    /**
     * Run one write on the CPU copy, upload what it changed, record it.
     *
     * @param  Closure(Framebuffer): mixed  $write
     */
    private function through(Closure $write): static
    {
        if (is_null($this->shadow)) {
            $this->shadow = new NativeDirtyFramebuffer(FormatSpec::rgba8(), $this->width(), $this->height());
            $this->shadow->writeRgba8($this->readRgba8($this->whole()), $this->width(), $this->height());
            $this->shadow->beginEpoch();
        }

        $write($this->shadow);
        foreach ($this->shadow->damage() as $region) {
            $this->uploadRgba8($this->shadow->flushRegion($region, FormatSpec::rgba8()), $region);
            $this->record->add($region);
        }
        $this->shadow->beginEpoch();

        return $this;
    }
}
