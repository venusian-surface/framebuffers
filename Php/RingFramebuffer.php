<?php

namespace Surface\Framebuffers\Php;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\MultiFrameFramebuffer;
use Surface\Contracts\Framebuffers\Region;

/**
 * N full frames. Writes land on the back frame; every read, flush and dump
 * answers the front. present() makes the back the front and advances the back
 * to the next slot. Starts with frame 0 in front and frame 1 at the back.
 */
class RingFramebuffer implements MultiFrameFramebuffer
{
    /** @var list<FullFramebuffer> */
    protected array $ring = [];

    protected int $front = 0;

    protected int $back = 1;

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
            $this->ring[] = new FullFramebuffer($format, $width, $height);
        }
    }

    public function frames(): int
    {
        return $this->frames;
    }

    public function present(): void
    {
        $this->front = $this->back;
        $this->back = ($this->back + 1) % $this->frames;
    }

    public function front(): Framebuffer
    {
        return $this->ring[$this->front];
    }

    protected function back(): FullFramebuffer
    {
        return $this->ring[$this->back];
    }

    public function viewportWidth(): int { return $this->width; }
    public function viewportHeight(): int { return $this->height; }
    public function hostFormat(): FormatSpec { return $this->format; }
    public function getPixel(int $x, int $y): int { return $this->front()->getPixel($x, $y); }
    public function setPixel(int $x, int $y, int $value): static { $this->back()->setPixel($x, $y, $value); return $this; }
    public function setPixels(array $pixels): static { $this->back()->setPixels($pixels); return $this; }
    public function setRegion(array $coordinates, int $value): static { $this->back()->setRegion($coordinates, $value); return $this; }
    public function setSegment(int $x, int $y, int $width, int $height, int $color): static { $this->back()->setSegment($x, $y, $width, $height, $color); return $this; }
    public function clear(): static { $this->back()->clear(); return $this; }
    public function fill(int $color): static { $this->back()->fill($color); return $this; }
    public function blitTo(Framebuffer $target, int $offset_x = 0, int $offset_y = 0): Framebuffer { return $target->blitFrom($this, $offset_x, $offset_y); }
    public function blitFrom(Framebuffer $source, int $offset_x = 0, int $offset_y = 0): Framebuffer { $this->back()->blitFrom($source, $offset_x, $offset_y); return $this; }
    public function dump(?int $layer = null): string { return $this->front()->dump($layer); }
    public function flush(FormatSpec $spec, bool $as_array = false): string|array { return $this->front()->flush($spec, $as_array); }
    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array { return $this->front()->flushRegion($region, $spec, $as_array); }
    public function toRgba8(): string { return $this->front()->toRgba8(); }
    public function damageGranularity(): DamageGranularity { return $this->front()->damageGranularity(); }
    public function preservesContentsOnPresent(): bool { return false; }
    public function pointer(): int { return 0; }
}
