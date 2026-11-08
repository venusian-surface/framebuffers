<?php

namespace Surface\Framebuffers\Native;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\FramebufferException;
use Surface\Contracts\Framebuffers\PixelStore;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\ScanDirection;
use Surface\Contracts\Framebuffers\Spans;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Framebuffers\Layout;
use Surface\Framebuffers\MaskList;
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

    /** Whether the bytes already are RGBA8 rows, top row first: then RGBA8 reads and writes copy rows whole. */
    private bool $plain;

    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        Layout::size($width, $height);
        $this->packing = Packing::for($format, $width, $height);
        $this->mapper = PixelMapper::for($format);
        $this->bytes = $this->packing->blank();
        $this->plain = $format->equals(FormatSpec::rgba8());
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
        if ($this->plain) {
            return $this->bytes;
        }

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

        if ($this->plain) {
            $this->spliceRgba8($rgba8, $width, $offset_x, $offset_y, $target);

            return $target;
        }

        for ($y = $target->y; $y < $target->bottom(); $y++) {
            $i = (($y - $offset_y) * $width + $target->x - $offset_x) * 4;
            for ($x = $target->x; $x < $target->right(); $x++, $i += 4) {
                $this->packing->set($this->bytes, $x, $y, $this->mapper->fromRgba8(ord($rgba8[$i]), ord($rgba8[$i + 1]), ord($rgba8[$i + 2]), ord($rgba8[$i + 3])));
            }
        }

        return $target;
    }

    /** Rebuild the bytes once from the untouched parts and the block's rows: one pass over the surface, not one call per pixel. */
    private function spliceRgba8(string $rgba8, int $width, int $offset_x, int $offset_y, Region $target): void
    {
        $stride = $this->width * 4;
        $left = $target->x * 4;
        $run = $target->width * 4;
        $parts = [substr($this->bytes, 0, $target->y * $stride)];
        for ($y = $target->y; $y < $target->bottom(); $y++) {
            $row = $y * $stride;
            $parts[] = substr($this->bytes, $row, $left);
            $parts[] = substr($rgba8, (($y - $offset_y) * $width + $target->x - $offset_x) * 4, $run);
            $parts[] = substr($this->bytes, $row + $left + $run, $stride - $left - $run);
        }
        $parts[] = substr($this->bytes, $target->bottom() * $stride);
        $this->bytes = implode('', $parts);
    }

    public function paintSpans(string $spans, int $rgba8): ?Region
    {
        [$list, $bounds] = SpanList::read($spans, $rgba8, $this->width, $this->height);
        $paint = $this->runPaint($rgba8);
        foreach ($list as [$y, $x, $length, $coverage]) {
            $this->paintRun($x, $y, $length, $coverage, $paint);
        }

        return $bounds;
    }

    /** Each mask row cut to the clip, walked run by run of one coverage (strspn finds a run in C), each run painted as a span. */
    public function paintMasks(array $masks, int $rgba8, Region $clip): ?Region
    {
        MaskList::check($masks, $rgba8, $clip, $this->width, $this->height);
        $paint = $this->runPaint($rgba8);
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach ($masks as [$coverage, $width, $mx, $my]) {
            $y0 = max($my, $clip->y);
            $y1 = min($my + intdiv(strlen($coverage), $width), $clip->bottom());
            $x0 = max($mx, $clip->x);
            $x1 = min($mx + $width, $clip->right());
            for ($y = $y0; $y < $y1 && $x0 < $x1; $y++) {
                // the byte of column x on this row is at $at + x
                $at = ($y - $my) * $width - $mx;
                for ($x = $x0; $x < $x1; $x += $length) {
                    $c = $coverage[$at + $x];
                    $length = strspn($coverage, $c, $at + $x, $x1 - $x);
                    if ($c !== "\0") {
                        $this->paintRun($x, $y, $length, ord($c), $paint);
                        $left = min($left, $x);
                        $right = max($right, $x + $length);
                        $top = min($top, $y);
                        $bottom = max($bottom, $y + 1);
                    }
                }
            }
        }

        return $right === PHP_INT_MIN ? null : new Region($left, $top, $right - $left, $bottom - $top);
    }

    /** @return array{int, int, int, int, int, bool} red, green, blue, alpha, the colour's word, whether the format blends */
    private function runPaint(int $rgba8): array
    {
        [$red, $green, $blue, $alpha] = [($rgba8 >> 24) & 0xFF, ($rgba8 >> 16) & 0xFF, ($rgba8 >> 8) & 0xFF, $rgba8 & 0xFF];

        return [$red, $green, $blue, $alpha, $this->mapper->fromRgba8($red, $green, $blue, $alpha), in_array($this->mapper->mode(), [PixelMapperMode::RGB, PixelMapperMode::GREY], true)];
    }

    /**
     * A run of $length pixels from (x, y) at one coverage, inside the store: what one span paints.
     *
     * @param  array{int, int, int, int, int, bool}  $paint
     */
    private function paintRun(int $x, int $y, int $length, int $coverage, array $paint): void
    {
        [$red, $green, $blue, $alpha, $word, $blends] = $paint;
        $a = intdiv($alpha * $coverage + 127, 255);
        if ($a === 0 || (! $blends && $a < 128)) {
            return;
        }
        if ($a === 255 || ! $blends) {
            $this->packing->span($this->bytes, $x, $y, $length, $word);

            return;
        }
        for ($i = $x; $i < $x + $length; $i++) {
            $this->blend($i, $y, $red, $green, $blue, $a, true);
        }
    }

    public function filter(FilterChain $chain, Region $region, int $row = 0, bool $premultiplied = false): void
    {
        $this->inside($region);
        $all = $this->toRgba8();
        $stride = $this->width * 4;
        $pixels = '';
        for ($y = $region->y; $y < $region->bottom(); $y++) {
            $pixels .= substr($all, $y * $stride + $region->x * 4, $region->width * 4);
        }
        $this->blitRgba8(NativeFilters::run($chain, $pixels, $region->width, $region->height, $region->x, $region->y + $row, $premultiplied), $region->width, $region->height, $region->x, $region->y);
    }

    /** Along a span the position steps by the inverse's x column, so a pixel costs two additions, not a multiply. */
    public function paintGradient(string $spans, string $ramp, string $kind, GradientSpread $spread, array $inverse, int $row = 0): ?Region
    {
        if (strlen($ramp) !== 1024) {
            throw new FramebufferException('paintGradient() takes a ramp of 256 RGBA8 colours (1024 bytes), got '.strlen($ramp).'.');
        }
        if ($kind !== 'linear' && $kind !== 'radial') {
            throw new FramebufferException("paintGradient() takes a kind 'linear' or 'radial', got '{$kind}'.");
        }
        if (count($inverse) !== 6 || array_filter($inverse, fn (mixed $n): bool => (is_int($n) || is_float($n)) && is_finite($n)) !== $inverse) {
            throw new FramebufferException('paintGradient() takes an inverse of six finite numbers.');
        }
        if ($row < 0 || $row > 0xFFFF) {
            throw new FramebufferException("paintGradient() takes a row 0..65535, got {$row}.");
        }
        [$list, $bounds] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        [$ia, $ib, $ic, $id, $ie, $if] = array_values($inverse);
        $blends = in_array($this->mapper->mode(), [PixelMapperMode::RGB, PixelMapperMode::GREY], true);
        $radial = $kind === 'radial';

        foreach ($list as [$y, $x, $length, $cover]) {
            $py = ($y + $row) + 0.5;
            $px = $x + 0.5;
            $u = $ia * $px + $ic * $py + $ie;
            $v = $ib * $px + $id * $py + $if;
            for ($i = 0; $i < $length; $i++, $u += $ia, $v += $ib) {
                $t = $radial ? hypot($u, $v) : $u;
                $t = match ($spread) {
                    GradientSpread::PAD => min(1.0, max(0.0, $t)),
                    GradientSpread::REPEAT => $t - floor($t),
                    GradientSpread::REFLECT => ($m = $t - 2.0 * floor($t / 2.0)) > 1.0 ? 2.0 - $m : $m,
                };
                $at = ((int) floor($t * 255 + 0.5)) * 4;
                $alpha = ord($ramp[$at + 3]);
                if ($cover !== 255) {
                    $alpha = intdiv($alpha * $cover + 127, 255);
                }
                $this->blend($x + $i, $y, ord($ramp[$at]), ord($ramp[$at + 1]), ord($ramp[$at + 2]), $alpha, $blends);
            }
        }

        return $bounds;
    }

    public function paintRgba8(string $rgba8, int $width, int $height, array $inverse, Region $target, int $opacity, Filter $filter, int $row = 0, int $tint = 0xFFFFFFFF, ?array $window = null, ?string $coverage = null): void
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("paintRgba8() takes {$width}x{$height} RGBA8 pixels (".($width * $height * 4).' bytes), got '.strlen($rgba8).'.');
        }
        if ($opacity < 0 || $opacity > 255) {
            throw new FramebufferException("paintRgba8() takes an opacity 0..255, got {$opacity}.");
        }
        if ($row < 0 || $row > 0xFFFF) {
            throw new FramebufferException("paintRgba8() takes a row 0..65535, got {$row}.");
        }
        if (count($inverse) !== 6 || array_filter($inverse, fn (mixed $n): bool => (is_int($n) || is_float($n)) && is_finite($n)) !== $inverse) {
            throw new FramebufferException('paintRgba8() takes an inverse of six finite numbers.');
        }
        if ($tint < 0 || $tint > 0xFFFFFFFF) {
            throw new FramebufferException("paintRgba8() takes a tint 0xRRGGBBAA, got {$tint}.");
        }
        if (! is_null($window) && (count($window) !== 4 || ! ($window[0] < $window[2] && $window[1] < $window[3]))) {
            throw new FramebufferException('paintRgba8() takes a window [u0, v0, u1, v1] with u0 < u1 and v0 < v1.');
        }
        $this->inside($target);

        // the mask by row: [from, to, coverage] runs, ascending
        $mask = null;
        if (! is_null($coverage)) {
            $mask = [];
            $lastY = -1;
            $lastEnd = 0;
            foreach (Spans::unpack($coverage) as $i => [$my, $mx, $mlength, $mcover]) {
                if ($mlength < 1 || $my >= $this->height || $mx + $mlength > $this->width) {
                    throw new FramebufferException("paintRgba8() takes coverage inside the {$this->width}x{$this->height} store: span {$i} is empty or outside.");
                }
                if ($my < $lastY || ($my === $lastY && $mx < $lastEnd)) {
                    throw new FramebufferException("paintRgba8() takes coverage in order: span {$i} is out of order (rows ascend, x ascends without overlap within a row).");
                }
                [$lastY, $lastEnd] = [$my, $mx + $mlength];
                $mask[$my][] = [$mx, $mx + $mlength, $mcover];
            }
        }
        [$tr, $tg, $tb, $ta] = [($tint >> 24) & 0xFF, ($tint >> 16) & 0xFF, ($tint >> 8) & 0xFF, $tint & 0xFF];
        $tinted = $tint !== 0xFFFFFFFF;

        [$ia, $ib, $ic, $id, $ie, $if] = array_values($inverse);
        $smooth = $filter === Filter::LINEAR;
        $blends = in_array($this->mapper->mode(), [PixelMapperMode::RGB, PixelMapperMode::GREY], true);

        for ($y = $target->y; $y < $target->bottom(); $y++) {
            $py = ($y + $row) + 0.5;
            $runs = $mask[$y] ?? [];
            $run = 0;
            if (! is_null($mask) && $runs === []) {
                continue;
            }
            for ($x = $target->x; $x < $target->right(); $x++) {
                $px = $x + 0.5;
                $u = $ia * $px + $ic * $py + $ie;
                $v = $ib * $px + $id * $py + $if;
                if (! ($u >= 0 && $u < $width && $v >= 0 && $v < $height)) {
                    continue;
                }
                if (! is_null($window) && ! ($u >= $window[0] && $u < $window[2] && $v >= $window[1] && $v < $window[3])) {
                    continue;
                }
                $cover = 255;
                if (! is_null($mask)) {
                    while ($run < count($runs) && $runs[$run][1] <= $x) {
                        $run++;
                    }
                    if ($run >= count($runs) || $runs[$run][0] > $x) {
                        continue;
                    }
                    $cover = $runs[$run][2];
                }

                if (! $smooth) {
                    $i = ((int) floor($v) * $width + (int) floor($u)) * 4;
                    [$red, $green, $blue, $alpha] = [ord($rgba8[$i]), ord($rgba8[$i + 1]), ord($rgba8[$i + 2]), ord($rgba8[$i + 3])];
                } else {
                    // The four pixels around the point, weights in 1/256ths, colours weighed by their alpha.
                    $fx = $u - 0.5;
                    $x0 = floor($fx);
                    $tx = (int) floor(($fx - $x0) * 256);
                    $fy = $v - 0.5;
                    $y0 = floor($fy);
                    $ty = (int) floor(($fy - $y0) * 256);
                    $xa = max(0, min($width - 1, (int) $x0));
                    $xb = max(0, min($width - 1, (int) $x0 + 1));
                    $ya = max(0, min($height - 1, (int) $y0));
                    $yb = max(0, min($height - 1, (int) $y0 + 1));

                    $sum = $red = $green = $blue = 0;
                    foreach ([[$ya, $xa, (256 - $tx) * (256 - $ty)], [$ya, $xb, $tx * (256 - $ty)], [$yb, $xa, (256 - $tx) * $ty], [$yb, $xb, $tx * $ty]] as [$source_row, $source_column, $weight]) {
                        $i = ($source_row * $width + $source_column) * 4;
                        $weighed = $weight * ord($rgba8[$i + 3]);
                        $sum += $weighed;
                        $red += $weighed * ord($rgba8[$i]);
                        $green += $weighed * ord($rgba8[$i + 1]);
                        $blue += $weighed * ord($rgba8[$i + 2]);
                    }
                    if ($sum === 0) {
                        continue;
                    }
                    $half = intdiv($sum, 2);
                    [$red, $green, $blue, $alpha] = [intdiv($red + $half, $sum), intdiv($green + $half, $sum), intdiv($blue + $half, $sum), ($sum + 32768) >> 16];
                }

                if ($tinted) {
                    $red = intdiv($red * $tr + 127, 255);
                    $green = intdiv($green * $tg + 127, 255);
                    $blue = intdiv($blue * $tb + 127, 255);
                    $alpha = intdiv($alpha * $ta + 127, 255);
                }
                $alpha = intdiv($alpha * $opacity + 127, 255);
                if ($cover !== 255) {
                    $alpha = intdiv($alpha * $cover + 127, 255);
                }
                $this->blend($x, $y, $red, $green, $blue, $alpha, $blends);
            }
        }
    }

    public function kawaseDown(PixelStore $source, Region $region): void
    {
        $this->level($source, intdiv($region->width + 1, 2), intdiv($region->height + 1, 2));
        if ($region->x < 0 || $region->y < 0 || $region->isEmpty() || $region->right() > $source->width() || $region->bottom() > $source->height()) {
            throw new FramebufferException("The region is empty or not inside the {$source->width()}x{$source->height()} source.");
        }
        $this->bytes = NativeGlass::down($source->bytes(), $source->width(), $region);
    }

    public function kawaseUp(PixelStore $source): void
    {
        if ($this->width > 2 * $source->width() || $this->height > 2 * $source->height()) {
            throw new FramebufferException("A level up of {$source->width()}x{$source->height()} is at most twice its size, this is {$this->width}x{$this->height}.");
        }
        $this->level($source, $this->width, $this->height);
        $this->bytes = NativeGlass::up($source->bytes(), $source->width(), $source->height(), $this->width, $this->height);
    }

    public function paintGlass(array $geometry, PixelStore $blur, int $scale, ?PixelStore $sharp, int $originX, int $originY, array $look, Region $clip): ?Region
    {
        $this->inside($clip);
        $blends = in_array($this->mapper->mode(), [PixelMapperMode::RGB, PixelMapperMode::GREY], true);

        return NativeGlass::pane($geometry, [$blur->bytes(), $blur->width(), $blur->height()], $scale, $sharp === null ? null : [$sharp->bytes(), $sharp->width(), $sharp->height()],
            $originX, $originY, $look, $clip, fn (int $x, int $y, int $r, int $g, int $b, int $a) => $this->blend($x, $y, $r, $g, $b, $a, $blends));
    }

    /** This store and $source are RGBA8, and this one is $width x $height. */
    private function level(PixelStore $source, int $width, int $height): void
    {
        if (! $this->format->equals(FormatSpec::rgba8()) || ! $source->format()->equals(FormatSpec::rgba8())) {
            throw new FramebufferException('Glass levels are RGBA8 stores.');
        }
        if ($this->width !== $width || $this->height !== $height) {
            throw new FramebufferException("A level down of that region is {$width}x{$height}, this is {$this->width}x{$this->height}.");
        }
    }

    /** One pixel, source-over at alpha $a where the format blends; the colour itself from 128 up where it cannot. */
    private function blend(int $x, int $y, int $red, int $green, int $blue, int $a, bool $blends): void
    {
        if ($a === 0 || (! $blends && $a < 128)) {
            return;
        }
        if ($a === 255 || ! $blends) {
            $this->packing->set($this->bytes, $x, $y, $this->mapper->fromRgba8($red, $green, $blue));

            return;
        }

        $d = $this->mapper->toRgba8($this->packing->get($this->bytes, $x, $y));
        $this->packing->set($this->bytes, $x, $y, $this->mapper->fromRgba8(
            intdiv($red * $a + (($d >> 24) & 0xFF) * (255 - $a) + 127, 255),
            intdiv($green * $a + (($d >> 16) & 0xFF) * (255 - $a) + 127, 255),
            intdiv($blue * $a + (($d >> 8) & 0xFF) * (255 - $a) + 127, 255),
            intdiv(255 * $a + ($d & 0xFF) * (255 - $a) + 127, 255),
        ));
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
