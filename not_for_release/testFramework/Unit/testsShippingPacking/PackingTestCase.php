<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use PHPUnit\Framework\TestCase;
use Zencart\Shipping\Packing\BoxType;
use Zencart\Shipping\Packing\Dimensions;
use Zencart\Shipping\Packing\PackableItem;
use Zencart\Shipping\Packing\Package;
use Zencart\Shipping\Packing\PackingPlan;
use Zencart\Shipping\Packing\Units;
use Zencart\Shipping\Packing\Weight;

/**
 * Shared builders and geometric assertions for the packing tests.
 *
 * The packer takes no database and reads no configuration, so every test here builds
 * its own items and box catalog as plain objects. That is the point of the design: the
 * algorithm is verifiable without a bootstrap.
 */
abstract class PackingTestCase extends TestCase
{
    protected function item(
        float $l,
        float $w,
        float $h,
        float $weight = 1.0,
        string $uprid = 'p1',
        bool $ownBox = false,
        bool $noStack = false,
        bool $lockHeight = false,
        string $dimUnits = Units::INCHES,
        string $weightUnits = Units::POUNDS
    ): PackableItem {
        return new PackableItem(
            $uprid,
            'line-' . $uprid,
            new Dimensions($l, $w, $h, $dimUnits),
            new Weight($weight, $weightUnits),
            $ownBox,
            $noStack,
            $lockHeight
        );
    }

    /**
     * @return PackableItem[]
     */
    protected function units(int $count, PackableItem $prototype): array
    {
        return array_fill(0, $count, $prototype);
    }

    protected function box(
        string $code,
        float $l,
        float $w,
        float $h,
        ?float $maxPayload = null,
        float $tare = 0.0,
        float $wallThickness = 0.0,
        string $scope = BoxType::SCOPE_BOTH,
        ?string $carrierCode = null,
        int $sortOrder = 0
    ): BoxType {
        return new BoxType(
            null,
            $code,
            $code,
            new Dimensions($l, $w, $h),
            new Dimensions($l + 2 * $wallThickness, $w + 2 * $wallThickness, $h + 2 * $wallThickness),
            new Weight($tare),
            $maxPayload === null ? null : new Weight($maxPayload),
            $scope,
            $carrierCode,
            null,
            $sortOrder
        );
    }

    /**
     * The soundness assertion the whole design rests on.
     *
     * For every parcel that has a box: each placement lies within the usable interior,
     * and no two placements intersect. A packer that satisfies this is describing an
     * arrangement that physically exists; one that does not is making an arithmetic
     * claim about volume, which is how a three-rods-in-a-flat-box answer arises.
     */
    protected function assertPlanIsGeometricallySound(PackingPlan $plan, float $marginPercent = 0.0): void
    {
        foreach ($plan->packages as $index => $package) {
            if ($package->boxType === null) {
                continue;
            }

            $interior = $package->boxType->usableInterior($marginPercent);

            foreach ($package->contents as $packed) {
                $bounds = $packed->bounds();
                $this->assertGreaterThanOrEqual(
                    -Dimensions::EPSILON,
                    min($bounds['x'][0], $bounds['y'][0], $bounds['z'][0]),
                    sprintf('Parcel %d: placement starts outside the interior origin.', $index)
                );
                $this->assertLessThanOrEqual(
                    $interior->length + Dimensions::EPSILON,
                    $bounds['x'][1],
                    sprintf('Parcel %d: placement exceeds interior length.', $index)
                );
                $this->assertLessThanOrEqual(
                    $interior->width + Dimensions::EPSILON,
                    $bounds['y'][1],
                    sprintf('Parcel %d: placement exceeds interior width.', $index)
                );
                $this->assertLessThanOrEqual(
                    $interior->height + Dimensions::EPSILON,
                    $bounds['z'][1],
                    sprintf('Parcel %d: placement exceeds interior height.', $index)
                );
            }

            $count = count($package->contents);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $this->assertFalse(
                        $package->contents[$i]->overlaps($package->contents[$j]),
                        sprintf('Parcel %d: items %d and %d occupy the same space.', $index, $i, $j)
                    );
                }
            }
        }
    }

    /**
     * No parcel may exceed the payload its box declares. Checked against contents
     * weight, since the gross figure on the parcel includes tare.
     */
    protected function assertPayloadRespected(PackingPlan $plan): void
    {
        foreach ($plan->packages as $index => $package) {
            if ($package->boxType?->maxPayload === null) {
                continue;
            }
            $contents = $this->contentsWeight($package);
            $this->assertLessThanOrEqual(
                $package->boxType->maxPayload->value + Weight::EPSILON,
                $contents,
                sprintf('Parcel %d exceeds its box payload limit.', $index)
            );
        }
    }

    protected function contentsWeight(Package $package): float
    {
        $total = 0.0;
        foreach ($package->contents as $packed) {
            $total += $packed->item->weight->value;
        }

        return $total;
    }

    protected function totalPackedUnits(PackingPlan $plan): int
    {
        $total = 0;
        foreach ($plan->packages as $package) {
            $total += $package->itemCount();
        }

        return $total;
    }
}
