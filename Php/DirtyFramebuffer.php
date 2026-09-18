<?php

namespace Surface\Framebuffers\Php;

use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\Region;

/**
 * Records every write as a Region. Touching regions merge on the way in;
 * more than 16 rects collapses the record to one bounding box. damage() snaps
 * to the packing's granularity and merges again, since snapping makes
 * neighbours touch.
 */
class DirtyFramebuffer extends PackedGrid implements DamageTrackingFramebuffer
{
    /** @var list<Region> */
    protected array $written = [];

    protected bool $collapsed = false;

    public function beginEpoch(): void
    {
        $this->written = [];
        $this->collapsed = false;
    }

    public function damage(): array
    {
        $g = $this->damageGranularity();
        $snapped = [];
        foreach ($this->written as $r) {
            $snapped = self::mergeInto($snapped, $r->snap($g));
        }

        return $snapped;
    }

    protected function touched(Region $region): void
    {
        if ($this->collapsed) {
            $this->written = [$this->written[0]->union($region)];

            return;
        }
        $this->written = self::mergeInto($this->written, $region);
        if (count($this->written) > 16) {
            $box = array_shift($this->written);
            foreach ($this->written as $r) {
                $box = $box->union($r);
            }
            $this->written = [$box];
            $this->collapsed = true;
        }
    }

    /**
     * Append $r to $list, absorbing every entry it touches (and every entry the
     * grown result then touches). The absorbed entries' slot order is lost; the
     * merged region goes on the end.
     *
     * @param list<Region> $list
     * @return list<Region>
     */
    protected static function mergeInto(array $list, Region $r): array
    {
        do {
            $grew = false;
            $rest = [];
            foreach ($list as $existing) {
                if ($existing->touches($r)) {
                    $r = $r->union($existing);
                    $grew = true;
                } else {
                    $rest[] = $existing;
                }
            }
            $list = $rest;
        } while ($grew);
        $list[] = $r;

        return $list;
    }
}
