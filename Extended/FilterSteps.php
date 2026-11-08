<?php

namespace Surface\Framebuffers\Extended;

use Surface\Contracts\Drawing\Effects\Blend;
use Surface\Contracts\Drawing\Effects\BlendMode;
use Surface\Contracts\Drawing\Effects\Blur;
use Surface\Contracts\Drawing\Effects\BlurSampling;
use Surface\Contracts\Drawing\Effects\Channel;
use Surface\Contracts\Drawing\Effects\ColorMatrix;
use Surface\Contracts\Drawing\Effects\Composite;
use Surface\Contracts\Drawing\Effects\CompositeOperator;
use Surface\Contracts\Drawing\Effects\Displace;
use Surface\Contracts\Drawing\Effects\EdgeMode;
use Surface\Contracts\Drawing\Effects\Field;
use Surface\Contracts\Drawing\Effects\FieldKind;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Drawing\Effects\Flood;
use Surface\Contracts\Drawing\Effects\Noise;
use Surface\Contracts\Drawing\Effects\NoiseType;
use Surface\Contracts\Drawing\Effects\Table;
use Surface\Framebuffers\Native\NativeFilters;

/**
 * A FilterChain as plain arrays for ext-fb's FbBuffer::filter(): one list per step, its kind first, enums as the
 * integers of their case order. A displacement's map goes in a second list as [width, height, RGBA8 bytes] and the step
 * holds its index; its band weights are worked out here, so the extension holds only the arithmetic.
 */
final class FilterSteps
{
    /** @return array{list<list<mixed>>, list<array{int, int, string}>} */
    public static function encode(FilterChain $chain): array
    {
        $steps = [];
        $maps = [];
        foreach ($chain->steps as $step) {
            $steps[] = match (true) {
                $step instanceof Blur => ['blur', $step->sampling === BlurSampling::SPIRAL ? 1 : 0, $step->x, $step->y, $step->samples, $step->edge === EdgeMode::DUPLICATE ? 1 : 0, $step->in, $step->result],
                $step instanceof ColorMatrix => ['matrix', array_map('floatval', $step->values), $step->in, $step->result],
                $step instanceof Flood => ['flood', (int) round($step->color->red * 255), (int) round($step->color->green * 255), (int) round($step->color->blue * 255), (int) round($step->color->alpha * 255), $step->result],
                $step instanceof Composite => ['composite', $step->in, $step->in2, array_search($step->operator, CompositeOperator::cases(), true), $step->k1, $step->k2, $step->k3, $step->k4, $step->result],
                $step instanceof Blend => ['blend', $step->in, $step->in2, array_search($step->mode, BlendMode::cases(), true), $step->result],
                $step instanceof Noise => ['noise', array_search($step->type, NoiseType::cases(), true), $step->frequencyX, $step->frequencyY, $step->octaves, $step->seed, $step->stitch, $step->result],
                $step instanceof Displace => self::displace($step, $maps),
                $step instanceof Field => ['field', array_search($step->kind, FieldKind::cases(), true), ...array_map('floatval', $step->box ?? [0, 0, 0, 0]), ...array_map('floatval', $step->radii), $step->from, $step->to, $step->result],
                $step instanceof Table => ['table', $step->in, ...array_map(fn (?array $t): ?string => $t === null ? null : pack('C*', ...$t), [$step->red, $step->green, $step->blue, $step->alpha]), $step->result],
            };
        }

        return [$steps, $maps];
    }

    /** @param list<array{int, int, string}> $maps */
    private static function displace(Displace $step, array &$maps): array
    {
        if (is_string($step->map)) {
            $which = $step->map;
        } else {
            $maps[] = [$step->map->viewportWidth(), $step->map->viewportHeight(), $step->map->toRgba8()];
            $which = count($maps) - 1;
        }
        $weights = $step->bands === 1 ? [] : array_merge(...array_map(fn (array $w): array => array_map('floatval', $w), $step->weights ?? NativeFilters::bandWeights($step->bands)));

        return ['displace', $step->in, $which, $step->scale, array_search($step->xChannel, Channel::cases(), true), array_search($step->yChannel, Channel::cases(), true), $step->bands, $step->spread, $weights, $step->result];
    }
}
