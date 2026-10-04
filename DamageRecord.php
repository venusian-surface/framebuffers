<?php

namespace Surface\Framebuffers;

use Surface\Contracts\Framebuffers\DamageGranularity;
use Surface\Contracts\Framebuffers\Region;

/**
 * What has been written, as rects. Touching rects merge on the way in; more
 * than LIMIT rects collapse to one bounding box, and stay one from then on.
 */
final class DamageRecord
{
    public const int LIMIT = 16;

    /** @var list<Region> */
    private array $written = [];

    private bool $collapsed = false;

    public function add(Region $region): void
    {
        if ($this->collapsed) {
            $this->written = [$this->written[0]->union($region)];

            return;
        }

        $this->written = self::mergeInto($this->written, $region);
        if (count($this->written) > self::LIMIT) {
            $box = array_shift($this->written);
            foreach ($this->written as $r) {
                $box = $box->union($r);
            }
            $this->written = [$box];
            $this->collapsed = true;
        }
    }

    public function clear(): void
    {
        $this->written = [];
        $this->collapsed = false;
    }

    /** @return list<Region> As recorded, not snapped. */
    public function written(): array
    {
        return $this->written;
    }

    /**
     * Snapped to the granularity and merged again, since snapping makes neighbours touch.
     *
     * @return list<Region>
     */
    public function regions(DamageGranularity $granularity): array
    {
        $snapped = [];
        foreach ($this->written as $r) {
            $snapped = self::mergeInto($snapped, $r->snap($granularity));
        }

        return $snapped;
    }

    /**
     * Append $r to $list, absorbing every entry it touches (and every entry the
     * grown result then touches). The merged region goes on the end.
     *
     * @param  list<Region>  $list
     * @return list<Region>
     */
    private static function mergeInto(array $list, Region $r): array
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
