<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Affine;

/**
 * An image brighter than SDR white: half floats, little-endian, R G B A,
 * linear extended sRGB (1.0 is SDR white), straight alpha. Read as a
 * framebuffer it is its SDR pixels, sRGB-encoded and clamped, made on first
 * read; a GPU device whose target is HDR uploads rgba16f() instead. Read-only:
 * new pixels make a new image.
 */
final class HdrImage implements Framebuffer
{
    private ?NativeFullFramebuffer $sdr = null;

    private function __construct(private readonly string $rgba16f, private readonly int $width, private readonly int $height) {}

    /** @throws FramebufferException When the bytes do not fill $width × $height. */
    public static function fromRgba16f(string $rgba16f, int $width, int $height): self
    {
        self::sized($width, $height);
        if (strlen($rgba16f) !== $width * $height * 8) {
            throw new FramebufferException("An HdrImage of {$width}x{$height} is ".($width * $height * 8).' bytes of RGBA16F, got '.strlen($rgba16f).'.');
        }

        return new self($rgba16f, $width, $height);
    }

    /**
     * @param  list<float>  $rgba  R G B A per pixel, row by row.
     *
     * @throws FramebufferException When the floats do not fill $width × $height.
     */
    public static function fromFloats(array $rgba, int $width, int $height): self
    {
        self::sized($width, $height);
        if (count($rgba) !== $width * $height * 4) {
            throw new FramebufferException("An HdrImage of {$width}x{$height} is ".($width * $height * 4).' floats, got '.count($rgba).'.');
        }

        return new self(pack('v*', ...array_map(self::half(...), $rgba)), $width, $height);
    }

    public function rgba16f(): string
    {
        return $this->rgba16f;
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
        return FormatSpec::rgba8();
    }

    public function getPixel(int $x, int $y): int
    {
        return $this->sdr()->getPixel($x, $y);
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        throw self::readOnly();
    }

    public function setPixels(array $pixels): static
    {
        throw self::readOnly();
    }

    public function setRegion(array $coordinates, int $value): static
    {
        throw self::readOnly();
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        throw self::readOnly();
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        throw self::readOnly();
    }

    public function paintImage(Framebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null): static
    {
        throw self::readOnly();
    }

    public function clear(): static
    {
        throw self::readOnly();
    }

    public function fill(int $color): static
    {
        throw self::readOnly();
    }

    public function blitTo(Framebuffer $target, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        return $this->sdr()->blitTo($target, $offset_x, $offset_y);
    }

    public function blitFrom(Framebuffer $source, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        throw self::readOnly();
    }

    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        throw self::readOnly();
    }

    public function dump(?int $layer = null): string
    {
        return $this->sdr()->dump($layer);
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return $this->sdr()->flush($spec, $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        return $this->sdr()->flushRegion($region, $spec, $as_array);
    }

    public function toRgba8(): string
    {
        return $this->sdr()->toRgba8();
    }

    public function pointer(): int
    {
        return $this->sdr()->pointer();
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    /** The SDR pixels, made on first read. */
    private function sdr(): NativeFullFramebuffer
    {
        if (is_null($this->sdr)) {
            $bytes = [];
            foreach (array_chunk(array_values(unpack('v*', $this->rgba16f)), 4) as [$r, $g, $b, $a]) {
                array_push($bytes, self::srgb8(self::float($r)), self::srgb8(self::float($g)), self::srgb8(self::float($b)), self::alpha8(self::float($a)));
            }
            $this->sdr = (new NativeFullFramebuffer(FormatSpec::rgba8(), $this->width, $this->height))
                ->writeRgba8(pack('C*', ...$bytes), $this->width, $this->height);
        }

        return $this->sdr;
    }

    private static function sized(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            throw new FramebufferException("An HdrImage is at least 1x1, got {$width}x{$height}.");
        }
    }

    private static function readOnly(): FramebufferException
    {
        return new FramebufferException('An HdrImage is read-only: make a new one from new pixels.');
    }

    /** IEEE 754 binary16 bits of $value, rounded to nearest, ties to even. */
    private static function half(float $value): int
    {
        $bits = unpack('V', pack('g', $value))[1];
        $sign = ($bits >> 16) & 0x8000;
        $exponent = ($bits >> 23) & 0xFF;
        $mantissa = $bits & 0x7FFFFF;
        if ($exponent === 0xFF) {
            return $sign | 0x7C00 | ($mantissa !== 0 ? 0x200 : 0);
        }
        $e = $exponent - 112;
        if ($e >= 0x1F) {
            return $sign | 0x7C00;
        }
        if ($e <= 0) {
            if ($e < -10) {
                return $sign;
            }
            $mantissa |= 0x800000;
            $shift = 14 - $e;
            $half = $mantissa >> $shift;
            $rest = $mantissa & ((1 << $shift) - 1);
            $middle = 1 << ($shift - 1);
            if ($rest > $middle || ($rest === $middle && ($half & 1) === 1)) {
                $half++;
            }

            return $sign | $half;
        }
        $half = ($e << 10) | ($mantissa >> 13);
        $rest = $mantissa & 0x1FFF;
        if ($rest > 0x1000 || ($rest === 0x1000 && ($half & 1) === 1)) {
            $half++;                                                    // a carry into the exponent is the right rounding, up to infinity
        }

        return $sign | $half;
    }

    private static function float(int $half): float
    {
        $sign = ($half & 0x8000) !== 0 ? -1.0 : 1.0;
        $exponent = ($half >> 10) & 0x1F;
        $mantissa = $half & 0x3FF;

        return match (true) {
            $exponent === 0 => $sign * $mantissa * 2 ** -24,
            $exponent === 0x1F => $mantissa === 0 ? $sign * INF : NAN,
            default => $sign * (1 + $mantissa / 1024) * 2 ** ($exponent - 15),
        };
    }

    /** A linear channel as an sRGB byte: below 0 and NaN are 0, past SDR white is 255. */
    private static function srgb8(float $linear): int
    {
        if (is_nan($linear) || $linear <= 0.0) {
            return 0;
        }
        if ($linear >= 1.0) {
            return 255;
        }
        $encoded = $linear <= 0.0031308 ? 12.92 * $linear : 1.055 * $linear ** (1 / 2.4) - 0.055;

        return (int) round($encoded * 255);
    }

    private static function alpha8(float $alpha): int
    {
        return is_nan($alpha) ? 0 : (int) round(max(0.0, min(1.0, $alpha)) * 255);
    }
}
