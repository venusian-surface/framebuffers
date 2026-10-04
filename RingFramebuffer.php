<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\RingFramebuffer as RingFramebufferContract;

/**
 * A swap chain of $frames whole frames. Each frame is a dirty framebuffer, so
 * the ring knows what every present changed: that record is what age(),
 * repair() and damage() answer from. It keeps the last $frames presents;
 * anything older reads as "the whole surface changed".
 *
 * Starts with frame 0 in front (blank, serial 0) and frame 1 at the back.
 */
abstract class RingFramebuffer implements RingFramebufferContract
{
    /** @var list<DirtyFramebuffer> */
    protected array $slots = [];

    /** @var list<int> The serial each frame was presented at; 0 = never. */
    protected array $stamps = [];

    /** @var list<int> How many readers hold each frame. */
    protected array $holds = [];

    protected int $front = 0;

    /** Null while every frame but the front is held. */
    protected ?int $back = 1;

    protected int $serial = 0;

    /** True once repair() has made the back equal the front. */
    protected bool $repaired = false;

    /** @var array<int, list<Region>> serial => what that frame changed against the frame before it */
    protected array $log = [];

    public function __construct(
        protected FormatSpec $format,
        protected int $width,
        protected int $height,
        protected int $frames,
    ) {
        if ($frames < 2) {
            throw new FramebufferException("A ring needs at least two frames, got {$frames}.");
        }
        for ($i = 0; $i < $frames; $i++) {
            $this->slots[] = $this->slot($format, $width, $height);
            $this->stamps[] = 0;
            $this->holds[] = 0;
        }
    }

    /** One frame: a dirty framebuffer of this flavor. */
    abstract protected function slot(FormatSpec $format, int $width, int $height): DirtyFramebuffer;

    public function frames(): int
    {
        return $this->frames;
    }

    public function serial(): int
    {
        return $this->serial;
    }

    public function ready(): bool
    {
        return ! is_null($this->back);
    }

    public function present(): static
    {
        $back = $this->drawable();

        $record = new DamageRecord();
        foreach ([...$this->stale(), ...$this->slots[$back]->written()] as $region) {
            $record->add($region);
        }

        $this->serial++;
        $this->log[$this->serial] = $record->written();
        unset($this->log[$this->serial - $this->frames]);

        $this->stamps[$back] = $this->serial;
        $this->front = $back;
        $this->back = $this->pick();
        $this->arm();

        return $this;
    }

    public function front(): Framebuffer
    {
        return $this->slots[$this->front];
    }

    public function back(): Framebuffer
    {
        return $this->slots[$this->drawable()];
    }

    public function frame(int $age): ?Framebuffer
    {
        if ($age === 0) {
            return $this->slots[$this->front];
        }

        $serial = $this->serial - $age;
        if ($age < 0 || $serial < 1) {
            return null;
        }
        foreach ($this->stamps as $i => $stamp) {
            if ($stamp === $serial && $i !== $this->back) {
                return $this->slots[$i];
            }
        }

        return null;
    }

    public function age(): int
    {
        $stamp = $this->stamps[$this->drawable()];

        return match (true) {
            $this->repaired => 1,
            $stamp === 0 => 0,
            default => $this->serial - $stamp + 1,
        };
    }

    public function repair(): static
    {
        $back = $this->slots[$this->drawable()];
        if ($back->written() !== []) {
            throw new FramebufferException('repair() comes before drawing: this frame has already been written to.');
        }

        $front = $this->slots[$this->front]->store();
        foreach ($this->stale() as $region) {
            $back->store()->copy($front, $region);
        }
        $this->repaired = true;

        return $this;
    }

    public function hold(): Framebuffer
    {
        $this->holds[$this->front]++;

        return $this->slots[$this->front];
    }

    public function release(Framebuffer $frame): static
    {
        $i = array_search($frame, $this->slots, true);
        if ($i === false || $this->holds[$i] === 0) {
            throw new FramebufferException('release() takes a frame hold() answered and has not released yet.');
        }

        $this->holds[$i]--;
        if (is_null($this->back)) {
            $this->back = $this->pick();
            $this->arm();
        }

        return $this;
    }

    public function damage(?int $since = null): array
    {
        $since ??= $this->serial - 1;
        if ($this->serial === 0 || $since >= $this->serial) {
            return [];
        }

        $record = new DamageRecord();
        for ($serial = $since + 1; $serial <= $this->serial; $serial++) {
            if (! isset($this->log[$serial])) {
                return [Region::wholeSurface($this->width, $this->height)];
            }
            foreach ($this->log[$serial] as $region) {
                $record->add($region);
            }
        }

        return $record->regions($this->damageGranularity());
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
        return $this->slots[$this->drawable()]->getPixel($x, $y);
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->slots[$this->drawable()]->setPixel($x, $y, $value);

        return $this;
    }

    public function setPixels(array $pixels): static
    {
        $this->slots[$this->drawable()]->setPixels($pixels);

        return $this;
    }

    public function setRegion(array $coordinates, int $value): static
    {
        $this->slots[$this->drawable()]->setRegion($coordinates, $value);

        return $this;
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $this->slots[$this->drawable()]->setSegment($x, $y, $width, $height, $color);

        return $this;
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        $this->slots[$this->drawable()]->paintSpans($spans, $rgba8);

        return $this;
    }

    public function clear(): static
    {
        $this->slots[$this->drawable()]->clear();

        return $this;
    }

    public function fill(int $color): static
    {
        $this->slots[$this->drawable()]->fill($color);

        return $this;
    }

    public function blitTo(Framebuffer $target, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(Framebuffer $source, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        $this->slots[$this->drawable()]->blitFrom($source, $offset_x, $offset_y);

        return $this;
    }

    public function dump(?int $layer = null): string
    {
        return $this->slots[$this->front]->dump($layer);
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return $this->slots[$this->front]->flush($spec, $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        return $this->slots[$this->front]->flushRegion($region, $spec, $as_array);
    }

    public function toRgba8(): string
    {
        return $this->slots[$this->front]->toRgba8();
    }

    public function pointer(): int
    {
        return $this->slots[$this->front]->pointer();
    }

    public function damageGranularity(): DamageGranularity
    {
        return $this->slots[$this->front]->damageGranularity();
    }

    public function preservesContentsOnPresent(): bool
    {
        return false;
    }

    /** The back's index. */
    protected function drawable(): int
    {
        return $this->back ?? throw FramebufferException::notReady();
    }

    /**
     * Where the back differs from the front before this frame's own writes:
     * everything presented since the frame the back holds.
     *
     * @return list<Region>
     */
    protected function stale(): array
    {
        if ($this->repaired) {
            return [];
        }

        $stamp = $this->stamps[$this->drawable()];
        if ($stamp === 0) {
            return [Region::wholeSurface($this->width, $this->height)];
        }

        $regions = [];
        for ($serial = $stamp + 1; $serial <= $this->serial; $serial++) {
            if (! isset($this->log[$serial])) {
                return [Region::wholeSurface($this->width, $this->height)];
            }
            array_push($regions, ...$this->log[$serial]);
        }

        return $regions;
    }

    /** The frame to draw next: not the front, not held, presented longest ago. Null when there is none. */
    protected function pick(): ?int
    {
        $best = null;
        foreach ($this->stamps as $i => $stamp) {
            if ($i === $this->front || $this->holds[$i] > 0) {
                continue;
            }
            if (is_null($best) || $stamp < $this->stamps[$best]) {
                $best = $i;
            }
        }

        return $best;
    }

    /** Start the new back's own record. */
    protected function arm(): void
    {
        $this->repaired = false;
        if (! is_null($this->back)) {
            $this->slots[$this->back]->beginEpoch();
        }
    }
}
