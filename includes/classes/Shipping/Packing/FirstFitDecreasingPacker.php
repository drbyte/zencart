<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Core packer: first-fit-decreasing over boxes, best-fit within a box, with
 * axis-aligned rotation and disjoint 3D residual tracking.
 *
 * Contract: conservative. The plan may use more parcels than a skilled human would,
 * and must never claim fewer, or smaller, than geometry permits. Under-quoting costs
 * the store money on every order; over-quoting costs a tunable margin that the store
 * controls through the interior margin.
 *
 * 3D bin packing with rotation is NP-hard, so this is a heuristic by necessity. What
 * makes a heuristic trustworthy is not how tightly it packs but whether its output is
 * physically realisable, which is asserted directly by the soundness test: every
 * placement inside the usable interior, and no two placements overlapping.
 *
 * Touches no database and reads no configuration. Items, boxes and context are
 * injected, which is what lets the whole algorithm be tested without a bootstrap.
 */
final class FirstFitDecreasingPacker implements PackerInterface
{
    public const CODE = 'zc-ffd-v1';

    public function code(): string
    {
        return self::CODE;
    }

    /**
     * @param PackableItem[] $items
     * @param BoxType[] $boxes
     */
    public function pack(array $items, array $boxes, PackingContext $context): PackingPlan
    {
        $items = array_values($items);
        $planHash = PlanHasher::hash($items, $boxes, $context, self::CODE);
        $available = $this->availableBoxes($boxes, $context);

        $dimensionless = [];
        $ownBox = [];
        $regular = [];
        foreach ($items as $item) {
            if (!$item->dimensions->isUsable()) {
                $dimensionless[] = $item;
            } elseif ($item->shipsInOwnBox) {
                $ownBox[] = $item;
            } else {
                $regular[] = $item;
            }
        }

        // A cart containing anything without dimensions is an all-or-nothing decision.
        // Under the legacy policy the whole cart reverts to weight-only quoting, which
        // is an exact no-op for the large population of stores that have never entered
        // dimensions. A part-dimensional plan would be less trustworthy than either
        // pure alternative.
        if ($dimensionless !== [] && $context->missingDimensionsPolicy === PackingContext::POLICY_LEGACY) {
            return PackingPlan::legacyFallback(self::CODE, $planHash);
        }

        $packages = [];
        $unpackable = [];

        // One box search per distinct item rather than per unit. Units of the same
        // line are identical, so the parcel for the second is the parcel for the
        // first; reusing it is exact, not an approximation. Without this a cart of
        // 3,000 own-box units ran 3,000 catalog searches on every quote.
        $alone = [];
        foreach ($ownBox as $item) {
            $key = $item->signature();
            if (!isset($alone[$key])) {
                $alone[$key] = $this->packAlone($item, $available, $context);
            }
            $packages[] = $alone[$key];
        }

        if ($dimensionless !== []) {
            $this->packByWeightOnly($dimensionless, $available, $context, $packages, $unpackable);
        }

        $approximated = false;
        $estimated = null;
        $sampleScale = 1.0;

        // Own-box units already consumed part of the modelling budget, so they count
        // against it. Their parcels are exact - one per unit - so they never make a
        // plan approximate; they just leave less room to model the rest.
        $budget = max(1, $context->maxUnits - count($ownBox));

        if (count($regular) > $budget) {
            $full = count($regular);
            $sample = $this->proportionalSample($regular, $budget);

            // The per-group floor of one unit means sampling cannot always reduce the
            // set - a cart of many single-unit lines samples to itself. Claiming
            // approximation there would be a lie that also costs the store its
            // per-parcel packing slip, since an approximated plan is not persistable.
            // Pack in full instead; the adapter's hard ceiling is the real bound.
            if (count($sample) < $full) {
                $regular = $sample;
                $approximated = true;
                $sampleScale = $full / count($sample);
            }
        }

        $opened = $this->packRegular($regular, $available, $context, $unpackable);

        $sampledPackages = [];
        foreach ($opened as $open) {
            $sampledPackages[] = $open->finalize($context);
        }
        $packages = array_merge($packages, $sampledPackages);

        if ($approximated) {
            // Extrapolate from what the sample actually achieved rather than assuming
            // a per-unit parcel. A naive "pack one of each and multiply by quantity"
            // is wrong by large factors - 500 units of an item that packs 100 to a box
            // would be reported as 500 parcels.
            $estimated = (int)ceil(count($sampledPackages) * $sampleScale)
                + (count($packages) - count($sampledPackages));
        }

        return new PackingPlan(
            $packages,
            $unpackable,
            false,
            $approximated,
            self::CODE,
            $planHash,
            $estimated
        );
    }

    /**
     * @param BoxType[] $boxes
     * @return BoxType[]
     */
    private function availableBoxes(array $boxes, PackingContext $context): array
    {
        $available = [];
        foreach ($boxes as $box) {
            if ($box->isAvailableFor($context)) {
                $available[] = $box;
            }
        }

        // Smallest usable interior first, so "the smallest box that fits" is a scan.
        // The code tie-break keeps two identically-sized boxes in a stable order.
        usort($available, static function (BoxType $a, BoxType $b) use ($context): int {
            return $a->usableInterior($context->interiorMarginPercent)->volume()
                <=> $b->usableInterior($context->interiorMarginPercent)->volume()
                ?: strcmp($a->code, $b->code);
        });

        return $available;
    }

    /**
     * Decreasing order: biggest first, because a large item placed late tends to need
     * a parcel of its own. Ties resolve through longest edge then identity so that the
     * same cart always produces the same plan.
     *
     * @param PackableItem[] $items
     * @return PackableItem[]
     */
    private function sortDecreasing(array $items): array
    {
        usort($items, static function (PackableItem $a, PackableItem $b): int {
            return $b->dimensions->volume() <=> $a->dimensions->volume()
                ?: $b->dimensions->longestEdge() <=> $a->dimensions->longestEdge()
                ?: strcmp($a->signature(), $b->signature());
        });

        return $items;
    }

    /**
     * @param PackableItem[] $items
     * @param BoxType[] $available
     * @param PackableItem[] $unpackable
     * @return OpenBox[]
     */
    private function packRegular(array $items, array $available, PackingContext $context, array &$unpackable): array
    {
        /** @var OpenBox[] $opened */
        $opened = [];

        foreach ($this->sortDecreasing($items) as $item) {
            foreach ($opened as $box) {
                if ($box->tryPlace($item)) {
                    continue 2;
                }
            }

            $box = $this->smallestBoxFor($item, $available, $context);
            if ($box === null) {
                $unpackable[] = $item;
                continue;
            }

            $open = new OpenBox($box, $context);
            if (!$open->tryPlace($item)) {
                // smallestBoxFor() already proved a fit, so reaching here would mean
                // the two disagree. Treat it as unpackable rather than shipping an
                // empty parcel.
                $unpackable[] = $item;
                continue;
            }
            $opened[] = $open;
        }

        return $opened;
    }

    /**
     * @param BoxType[] $available
     */
    private function smallestBoxFor(PackableItem $item, array $available, PackingContext $context): ?BoxType
    {
        foreach ($available as $box) {
            if ($item->weight->exceeds($box->maxPayload)) {
                continue;
            }

            $interior = $box->usableInterior($context->interiorMarginPercent);
            foreach ($item->orientations() as $rotation) {
                if ($rotation->fitsWithin($interior)) {
                    return $box;
                }
            }
        }

        return null;
    }

    /**
     * One parcel for an item that must not travel with anything else.
     *
     * Covers already-boxed goods, and - by reusing a flag that already means "do not
     * combine this" - hazardous items, must-not-ship-together goods and irregular
     * geometry, conservatively and with no extra schema. With no box big enough, the
     * item's own extent becomes the parcel rather than the item being dropped: it is
     * genuinely shippable, just not in a catalog box.
     *
     * @param BoxType[] $available
     */
    private function packAlone(PackableItem $item, array $available, PackingContext $context): Package
    {
        $box = $this->smallestBoxFor($item, $available, $context);
        if ($box !== null) {
            $open = new OpenBox($box, $context);
            if ($open->tryPlace($item)) {
                return $open->finalize($context);
            }
        }

        $exterior = $item->dimensions;

        return new Package(
            null,
            $exterior,
            [new PackedItem($item, new Vector3(), $exterior)],
            $item->weight,
            Weight::zero($context->weightUnits),
            Package::computeBillableWeight($exterior, $item->weight, $context->dimDivisor),
            1.0
        );
    }

    /**
     * Pack items with no usable dimensions into the configured default box using
     * weight alone, under the default_box policy.
     *
     * Dimensions are never invented for these items: they consume no modelled space,
     * so a default box is filled to its payload limit and then another is opened. With
     * no default box configured, or no payload limit on it, the items are reported as
     * unpackable rather than silently riding along.
     *
     * @param PackableItem[] $items
     * @param BoxType[] $available
     * @param Package[] $packages
     * @param PackableItem[] $unpackable
     */
    private function packByWeightOnly(
        array $items,
        array $available,
        PackingContext $context,
        array &$packages,
        array &$unpackable
    ): void {
        $default = null;
        foreach ($available as $box) {
            if ($context->defaultBoxCode !== null && strcasecmp($box->code, $context->defaultBoxCode) === 0) {
                $default = $box;
                break;
            }
        }

        if ($default === null) {
            foreach ($items as $item) {
                $unpackable[] = $item;
            }
            return;
        }

        $current = [];
        $weight = Weight::zero($context->weightUnits);
        $flush = function () use (&$current, &$weight, $default, $context, &$packages): void {
            if ($current === []) {
                return;
            }
            $gross = $weight->plus($default->tare);
            $packages[] = new Package(
                $default,
                $default->exterior,
                $current,
                $gross,
                $default->tare,
                Package::computeBillableWeight($default->exterior, $gross, $context->dimDivisor),
                0.0
            );
            $current = [];
            $weight = Weight::zero($context->weightUnits);
        };

        foreach ($items as $item) {
            if ($current !== [] && $weight->plus($item->weight)->exceeds($default->maxPayload)) {
                $flush();
            }
            if ($current === [] && $item->weight->exceeds($default->maxPayload)) {
                // A single item heavier than the default box can hold cannot be packed
                // into it at any quantity.
                $unpackable[] = $item;
                continue;
            }
            $current[] = new PackedItem($item, new Vector3(), $item->dimensions);
            $weight = $weight->plus($item->weight);
        }
        $flush();
    }

    /**
     * Reduce an oversized cart to at most $limit units while keeping the mix of item
     * kinds, so that the measured parcel density is representative of the whole.
     *
     * @param PackableItem[] $items
     * @return PackableItem[]
     */
    private function proportionalSample(array $items, int $limit): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$item->signature()][] = $item;
        }
        ksort($groups);

        $total = count($items);
        $sample = [];
        foreach ($groups as $group) {
            $take = max(1, (int)round(count($group) * ($limit / $total)));
            $sample = array_merge($sample, array_slice($group, 0, min($take, count($group))));
        }

        return $sample;
    }

}
