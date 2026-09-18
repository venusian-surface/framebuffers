<?php

namespace Surface\Framebuffers\Packings;

use Surface\Contracts\Framebuffers\BitOrder;
use Surface\Contracts\Framebuffers\FormatSpec;

/** 2, 4 or 8 bits per pixel, row-major, rows byte-padded. MSB_FIRST: x0 is the high crumb/nibble. */
class PackedIndexPacking extends Packing
{
    protected int $per_byte;

    protected int $row_bytes;

    protected int $mask;

    public function __construct(FormatSpec $spec, int $width, int $height, protected int $bits, protected BitOrder $order)
    {
        parent::__construct($spec, $width, $height);
        $this->per_byte = intdiv(8, $bits);
        $this->row_bytes = intdiv($width * $bits + 7, 8);
        $this->mask = (1 << $bits) - 1;
    }

    public function bytesFor(): int
    {
        return $this->row_bytes * $this->height;
    }

    public function get(string $bytes, int $x, int $y): int
    {
        return (ord($bytes[$y * $this->row_bytes + intdiv($x, $this->per_byte)]) >> $this->shift($x)) & $this->mask;
    }

    public function set(string &$bytes, int $x, int $y, int $v): void
    {
        $i = $y * $this->row_bytes + intdiv($x, $this->per_byte);
        $shift = $this->shift($x);
        $bytes[$i] = chr((ord($bytes[$i]) & ~($this->mask << $shift)) | (($v & $this->mask) << $shift));
    }

    public function fill(string &$bytes, int $v): void
    {
        if ($this->bits === 8) {
            $this->fillCells($bytes, chr($v & 0xFF));

            return;
        }
        parent::fill($bytes, $v);
    }

    protected function shift(int $x): int
    {
        $slot = $x % $this->per_byte;

        return ($this->order === BitOrder::MSB_FIRST ? $this->per_byte - 1 - $slot : $slot) * $this->bits;
    }
}
