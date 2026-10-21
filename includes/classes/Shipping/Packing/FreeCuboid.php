<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * A rectangular region of unused space inside a box, at a known position.
 *
 * The list of these on an open box is the packer's model of remaining capacity, and
 * it is maintained strictly disjoint. That is the whole correctness argument: a
 * remaining-volume counter cannot tell that three 1x1x10 rods will not go into a
 * 10x10x1 box, because the arithmetic says 30 of 100 is free. A disjoint set of
 * positioned regions can, because no region is ever 10 deep.
 */
final readonly class FreeCuboid
{
    public function __construct(
        public Vector3 $origin,
        public Dimensions $size
    ) {
    }

    public function volume(): float
    {
        return $this->size->volume();
    }

    public function top(): float
    {
        return $this->origin->z + $this->size->height;
    }

    /**
     * Split this region around an item placed at its origin corner.
     *
     * Returns up to three regions which are mutually disjoint and whose union, with
     * the placed item, is exactly this region:
     *
     *   beside  x in [ox+l, ox+L)
     *   beyond  x in [ox, ox+l), y in [oy+w, oy+W)
     *   above   x in [ox, ox+l), y in [oy, oy+w), z in [oz+h, oz+H)
     *
     * Disjointness is load-bearing, not an optimisation. The maximal (MAXRECTS-style)
     * alternative - (L-l,W,H), (L,W-w,H), (L,W,H-h) - produces overlapping regions,
     * and two items placed into two overlapping regions physically collide. It also
     * breaks the pruning step: discarding a region is only conservative when regions
     * do not overlap, because with overlap the discarded space is still double-booked
     * by its neighbours.
     *
     * $suppressAbove drops the region directly over the item, which is how a
     * no-stack item is guaranteed nothing on top of it.
     *
     * @return self[]
     */
    public function splitAround(Dimensions $placed, bool $suppressAbove = false): array
    {
        $units = $this->size->units;
        $parts = [];

        $besideLength = $this->size->length - $placed->length;
        if ($besideLength > Dimensions::EPSILON) {
            $parts[] = new self(
                $this->origin->translate($placed->length, 0.0, 0.0),
                new Dimensions($besideLength, $this->size->width, $this->size->height, $units)
            );
        }

        $beyondWidth = $this->size->width - $placed->width;
        if ($beyondWidth > Dimensions::EPSILON) {
            $parts[] = new self(
                $this->origin->translate(0.0, $placed->width, 0.0),
                new Dimensions($placed->length, $beyondWidth, $this->size->height, $units)
            );
        }

        $aboveHeight = $this->size->height - $placed->height;
        if (!$suppressAbove && $aboveHeight > Dimensions::EPSILON) {
            $parts[] = new self(
                $this->origin->translate(0.0, 0.0, $placed->height),
                new Dimensions($placed->length, $placed->width, $aboveHeight, $units)
            );
        }

        return $parts;
    }

    public function overlaps(self $other): bool
    {
        $pairs = [
            [$this->origin->x, $this->origin->x + $this->size->length, $other->origin->x, $other->origin->x + $other->size->length],
            [$this->origin->y, $this->origin->y + $this->size->width, $other->origin->y, $other->origin->y + $other->size->width],
            [$this->origin->z, $this->top(), $other->origin->z, $other->top()],
        ];

        foreach ($pairs as [$aMin, $aMax, $bMin, $bMax]) {
            if ($aMax <= $bMin + Dimensions::EPSILON || $bMax <= $aMin + Dimensions::EPSILON) {
                return false;
            }
        }

        return true;
    }
}
