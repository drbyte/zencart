<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * A box being filled. The only mutable object in the subsystem; it becomes an
 * immutable Package when the plan is finished.
 *
 * Invariant maintained throughout: $free holds mutually disjoint regions, all inside
 * the usable interior. Every placement replaces one region with the up-to-three
 * disjoint regions left around the item, so the invariant is preserved by
 * construction rather than checked.
 */
final class OpenBox
{
    /**
     * Upper bound on tracked free regions per box.
     *
     * Splitting can fragment space faster than it is consumed, and each extra region
     * multiplies the placement search. When the cap is hit the smallest regions are
     * dropped: because regions are disjoint, forgetting one only loses the chance to
     * use that space, which errs toward more parcels rather than fewer - the safe
     * direction.
     */
    public const MAX_FREE_REGIONS = 64;

    private Dimensions $usableInterior;

    /** @var FreeCuboid[] */
    private array $free;

    /** @var PackedItem[] */
    private array $placed = [];

    private Weight $contentsWeight;

    private float $placedVolume = 0.0;

    public function __construct(
        private readonly BoxType $type,
        PackingContext $context
    ) {
        $this->usableInterior = $type->usableInterior($context->interiorMarginPercent);
        $this->free = [new FreeCuboid(new Vector3(), $this->usableInterior)];
        $this->contentsWeight = Weight::zero($context->weightUnits);
    }

    public function boxType(): BoxType
    {
        return $this->type;
    }

    public function usableInterior(): Dimensions
    {
        return $this->usableInterior;
    }

    /**
     * @return PackedItem[]
     */
    public function placedItems(): array
    {
        return $this->placed;
    }

    /**
     * Attempt to place one item, choosing the tightest region that will hold it.
     *
     * Best-fit by leftover volume, with the region index and the orientation's own
     * signature as tie-breaks so that equally good candidates resolve the same way on
     * every run - determinism the plan hash depends on.
     */
    public function tryPlace(PackableItem $item): bool
    {
        if ($this->contentsWeight->plus($item->weight)->exceeds($this->type->maxPayload)) {
            return false;
        }

        $bestRegion = null;
        $bestRotation = null;
        $bestWaste = null;
        $bestKey = '';

        foreach ($this->free as $index => $region) {
            // A no-stack item may only go in a region that reaches the top of the
            // usable interior. Suppressing the region above the item is not on its own
            // enough: a region created by an earlier split elsewhere could still sit
            // over it. Requiring the region's ceiling to be the box's ceiling removes
            // that possibility entirely.
            if ($item->noStack && $region->top() < $this->usableInterior->height - Dimensions::EPSILON) {
                continue;
            }

            foreach ($item->orientations() as $rotation) {
                if (!$rotation->fitsWithin($region->size)) {
                    continue;
                }

                $waste = $region->volume() - $rotation->volume();
                $key = $rotation->signature();
                if ($bestWaste === null
                    || $waste < $bestWaste - Dimensions::EPSILON
                    || (abs($waste - $bestWaste) <= Dimensions::EPSILON && $key < $bestKey)) {
                    $bestWaste = $waste;
                    $bestRegion = $index;
                    $bestRotation = $rotation;
                    $bestKey = $key;
                }
            }
        }

        if ($bestRegion === null || $bestRotation === null) {
            return false;
        }

        $this->commit($bestRegion, $item, $bestRotation);

        return true;
    }

    private function commit(int $regionIndex, PackableItem $item, Dimensions $rotation): void
    {
        $region = $this->free[$regionIndex];
        unset($this->free[$regionIndex]);
        $this->free = array_values($this->free);

        $this->placed[] = new PackedItem($item, $region->origin, $rotation);
        $this->contentsWeight = $this->contentsWeight->plus($item->weight);
        $this->placedVolume += $rotation->volume();

        foreach ($region->splitAround($rotation, $item->noStack) as $part) {
            $this->free[] = $part;
        }

        $this->pruneRegions();
    }

    /**
     * Drop regions too small to be useful, then enforce the cap by discarding the
     * smallest. Both are safe only because regions are disjoint.
     */
    private function pruneRegions(): void
    {
        $this->free = array_values(array_filter(
            $this->free,
            static fn (FreeCuboid $region): bool => $region->volume() > Dimensions::EPSILON
        ));

        if (count($this->free) <= self::MAX_FREE_REGIONS) {
            return;
        }

        usort($this->free, static function (FreeCuboid $a, FreeCuboid $b): int {
            return $b->volume() <=> $a->volume()
                ?: $a->origin->z <=> $b->origin->z
                ?: $a->origin->y <=> $b->origin->y
                ?: $a->origin->x <=> $b->origin->x;
        });

        $this->free = array_slice($this->free, 0, self::MAX_FREE_REGIONS);
    }

    /**
     * Freeze into the parcel that goes on the plan.
     *
     * The carrier-facing size is the box exterior, never the contents' bounding box:
     * the carrier measures and bills the box it receives.
     */
    public function finalize(PackingContext $context): Package
    {
        $gross = $this->contentsWeight->plus($this->type->tare);
        $interiorVolume = $this->usableInterior->volume();

        return new Package(
            $this->type,
            $this->type->exterior,
            $this->placed,
            $gross,
            $this->type->tare,
            Package::computeBillableWeight($this->type->exterior, $gross, $context->dimDivisor),
            $interiorVolume > 0.0 ? $this->placedVolume / $interiorVolume : 0.0
        );
    }
}
