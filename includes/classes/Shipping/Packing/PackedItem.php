<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * An item together with where it was placed and how it was oriented.
 *
 * The coordinates are what make a plan verifiable: the soundness test asserts that
 * every placement lies inside the usable interior and that no two placements overlap,
 * which is the property that distinguishes a real packing from an arithmetic guess
 * about volume.
 */
final readonly class PackedItem
{
    public function __construct(
        public PackableItem $item,
        public Vector3 $placedAt,
        public Dimensions $placedAs
    ) {
    }

    /**
     * Half-open interval per axis: [min, max). Used by the overlap check, where
     * half-open is what makes two items sharing a face count as adjacent rather than
     * intersecting.
     */
    public function bounds(): array
    {
        return [
            'x' => [$this->placedAt->x, $this->placedAt->x + $this->placedAs->length],
            'y' => [$this->placedAt->y, $this->placedAt->y + $this->placedAs->width],
            'z' => [$this->placedAt->z, $this->placedAt->z + $this->placedAs->height],
        ];
    }

    public function overlaps(self $other): bool
    {
        $a = $this->bounds();
        $b = $other->bounds();

        foreach (['x', 'y', 'z'] as $axis) {
            // Separated on any axis means no intersection. The epsilon makes touching
            // faces non-overlapping despite float arithmetic in the split step.
            if ($a[$axis][1] <= $b[$axis][0] + Dimensions::EPSILON
                || $b[$axis][1] <= $a[$axis][0] + Dimensions::EPSILON) {
                return false;
            }
        }

        return true;
    }
}
