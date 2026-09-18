<?php

namespace Surface\Framebuffers\Packings;

use Surface\Contracts\Framebuffers\BitOrder;
use Surface\Contracts\Framebuffers\FormatSpec;

/**
 * N one-bit planes in palette order, each packed like MonoHorizontal. Word
 * is a channel mask: bit k set = ink k; 0 = paper. Plane k stores
 * (bit k) xor inverted[k], so an inverted plane's paper is 1.
 */
class PlanarPacking extends Packing
{
    protected int $row_bytes;

    protected int $plane_bytes;

    /** @var list<bool> */
    protected array $inverted = [];

    public function __construct(FormatSpec $spec, int $width, int $height, protected BitOrder $order)
    {
        parent::__construct($spec, $width, $height);
        $this->row_bytes = intdiv($width + 7, 8);
        $this->plane_bytes = $this->row_bytes * $height;
        foreach ($spec->palette->channels as $channel) {
            $this->inverted[] = $channel->inverted;
        }
    }

    public function planes(): int
    {
        return count($this->inverted);
    }

    public function bytesFor(): int
    {
        return $this->plane_bytes * $this->planes();
    }

    public function blank(): string
    {
        $bytes = str_repeat("\0", $this->bytesFor());
        $this->fill($bytes, 0);

        return $bytes;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        $mask = 0;
        $bit = $this->bit($x);
        $i = $y * $this->row_bytes + ($x >> 3);
        foreach ($this->inverted as $k => $inverted) {
            $b = (ord($bytes[$k * $this->plane_bytes + $i]) >> $bit) & 1;
            if (($b ^ (int) $inverted) === 1) {
                $mask |= 1 << $k;
            }
        }

        return $mask;
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $bit = 1 << $this->bit($x);
        $i = $y * $this->row_bytes + ($x >> 3);
        foreach ($this->inverted as $k => $inverted) {
            $on = ((($v >> $k) & 1) ^ (int) $inverted) === 1;
            $j = $k * $this->plane_bytes + $i;
            $b = ord($bytes[$j]);
            $bytes[$j] = chr($on ? $b | $bit : $b & ~$bit);
        }
    }

    public function layer(string $bytes, ?int $layer): string
    {
        if (is_null($layer)) {
            return $bytes;
        }

        return substr($bytes, $layer * $this->plane_bytes, $this->plane_bytes);
    }

    protected function bit(int $x): int
    {
        return $this->order === BitOrder::MSB_FIRST ? 7 - ($x & 7) : $x & 7;
    }
}
