<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use PHPUnit\Framework\Attributes\DataProvider;
use Zencart\Shipping\Packing\Dimensions;
use Zencart\Shipping\Packing\FirstFitDecreasingPacker;
use Zencart\Shipping\Packing\PackingContext;

/**
 * Geometric soundness: the property that makes the packer trustworthy.
 *
 * A packer can only be believed if the arrangement it reports physically exists. These
 * tests assert that directly - every placement inside the usable interior, no two
 * placements intersecting - rather than asserting parcel counts, which are a heuristic
 * outcome and would make the suite brittle without proving anything about correctness.
 *
 * The property is also what catches the one implementation mistake that matters here:
 * splitting residual space into overlapping regions (the maximal/MAXRECTS variant)
 * rather than disjoint ones. Overlapping regions double-book space, so two items get
 * placed into the same volume, and nothing but an overlap assertion notices.
 */
class PackerSoundnessTest extends PackingTestCase
{
    private function context(float $margin = 0.0, int $maxUnits = 5000): PackingContext
    {
        return new PackingContext(
            interiorMarginPercent: $margin,
            maxUnits: $maxUnits,
            catalogRevision: 'test'
        );
    }

    /**
     * Pseudo-random carts over a fixed box catalog, with a seeded generator so a
     * failure is reproducible from the seed alone.
     */
    public static function randomCartSeeds(): array
    {
        return array_map(static fn (int $seed): array => [$seed], range(1, 40));
    }

    /**
     * @dataProvider randomCartSeeds
     */
    #[DataProvider('randomCartSeeds')]
    public function testRandomCartsProduceGeometricallySoundPlans(int $seed): void
    {
        mt_srand($seed);

        $boxes = [
            $this->box('S', 6, 6, 6),
            $this->box('M', 12, 10, 8),
            $this->box('L', 20, 16, 14),
        ];

        $items = [];
        $itemCount = mt_rand(1, 25);
        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = $this->item(
                mt_rand(1, 14) / 1.0,
                mt_rand(1, 12) / 1.0,
                mt_rand(1, 10) / 1.0,
                mt_rand(1, 20) / 10.0,
                'p' . $i
            );
        }

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertPlanIsGeometricallySound($plan);
        $this->assertSame(
            $itemCount,
            $this->totalPackedUnits($plan) + count($plan->unpackable),
            'Every item must be either packed exactly once or reported unpackable.'
        );
    }

    /**
     * The canonical unsoundness of volume-based packing: two items whose volumes both
     * fit but whose footprints cannot coexist.
     *
     * Two 6x6x10 items are 720 cubic inches against a 10x10x10 box, so a packer that
     * tracks only remaining volume puts both in one carton. Geometrically only one can
     * go in: each needs a 6x6 footprint and 6 + 6 exceeds 10 on both horizontal axes,
     * while each is the full interior height so neither can sit on the other.
     */
    public function testTwoItemsThatCannotCoexistAreNotSharedOnVolumeAlone(): void
    {
        $items = $this->units(2, $this->item(6, 6, 10, 1.0, 'carton'));
        $boxes = [$this->box('CUBE', 10, 10, 10)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertPlanIsGeometricallySound($plan);
        $this->assertSame(2, $plan->packageCount(), 'Two 6x6 footprints do not coexist in a 10x10 interior.');
        $this->assertSame(2, $this->totalPackedUnits($plan));
    }

    /**
     * An item longer than every axis of every box is unpackable, however much free
     * volume the catalog appears to offer. A volume-only packer would accept a 12 inch
     * rod into a 10 inch cube because 12 cubic inches is a rounding error against 1000.
     */
    public function testAnOverlongItemIsUnpackableDespiteTrivialVolume(): void
    {
        $items = [$this->item(1, 1, 12, 0.5, 'rod')];
        $boxes = [$this->box('CUBE', 10, 10, 10)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertSame(0, $this->totalPackedUnits($plan));
        $this->assertCount(1, $plan->unpackable, 'An item that fits no box must be reported, not quietly packed.');
    }

    /**
     * The counterpart: a rod that does fit when turned must be packed, not rejected.
     * A 1x1x10 rod lies flat in a 10x10x1 tray, and three fit side by side. Guards
     * against "conservative" being implemented as a refusal to rotate.
     */
    public function testRodsThatFitWhenLaidFlatArePacked(): void
    {
        $items = $this->units(3, $this->item(1, 1, 10, 0.5, 'rod'));
        $boxes = [$this->box('TRAY', 10, 10, 1)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertPlanIsGeometricallySound($plan);
        $this->assertCount(0, $plan->unpackable);
        $this->assertSame(1, $plan->packageCount(), 'Three flat rods share one tray.');
    }

    /**
     * The case a volume cap would wrongly reject.
     *
     * Four 5x5x10 items tile a 10x10x10 box exactly. At zero margin all four must land
     * in one parcel; a packer that capped usable volume at some fraction would open a
     * second parcel for an item that demonstrably fits, penalising the carts that pack
     * most efficiently. Margin is therefore a dimensional inset, not a volume cap.
     */
    public function testPerfectTilingFitsASingleBoxAtZeroMargin(): void
    {
        $items = $this->units(4, $this->item(5, 5, 10, 1.0, 'tile'));
        $boxes = [$this->box('CUBE', 10, 10, 10)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertPlanIsGeometricallySound($plan);
        $this->assertSame(1, $plan->packageCount(), 'Four items that tile the box exactly must share one parcel.');
        $this->assertSame(4, $this->totalPackedUnits($plan));
    }

    /**
     * The same four items with a margin applied no longer tile, because the usable
     * interior has shrunk on every axis. Confirms the inset is actually enforced and
     * that the result stays sound rather than silently overflowing.
     */
    public function testInteriorMarginReducesUsableSpace(): void
    {
        $items = $this->units(4, $this->item(5, 5, 10, 1.0, 'tile'));
        $boxes = [$this->box('CUBE', 10, 10, 10)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context(5.0));

        $this->assertPlanIsGeometricallySound($plan, 5.0);
        $this->assertGreaterThan(
            0,
            $plan->packageCount() + count($plan->unpackable),
            'Margin must not silently discard items.'
        );
        $this->assertSame(
            4,
            $this->totalPackedUnits($plan) + count($plan->unpackable),
            'Every item accounted for once.'
        );
    }

    /**
     * Rotation must be considered. A 10x2x2 item cannot go into a 3x3x11 box as
     * oriented, but turns through 90 degrees without trouble.
     */
    public function testRotationIsConsidered(): void
    {
        $items = [$this->item(10, 2, 2, 1.0, 'bar')];
        $boxes = [$this->box('TALL', 3, 3, 11)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertSame(1, $plan->packageCount());
        $this->assertCount(0, $plan->unpackable);
        $this->assertPlanIsGeometricallySound($plan);
    }

    /**
     * An orientation-locked item keeps its stored height vertical, so the same bar
     * that fitted when free to rotate is now unpackable in that box.
     */
    public function testOrientationLockPreventsTippingAnItem(): void
    {
        $items = [$this->item(10, 2, 2, 1.0, 'bar', lockHeight: true)];
        $boxes = [$this->box('TALL', 3, 3, 11)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertSame(0, $plan->packageCount());
        $this->assertCount(1, $plan->unpackable, 'A "this side up" item must not be laid down to make it fit.');
    }

    /**
     * Nothing may be placed above a no-stack item. Asserted geometrically rather than
     * by parcel count: for each no-stack placement, no other item in the same parcel
     * may share its horizontal footprint at a greater height.
     */
    public function testNothingIsStackedOnANoStackItem(): void
    {
        $items = array_merge(
            [$this->item(6, 6, 2, 1.0, 'glass', noStack: true)],
            $this->units(6, $this->item(3, 3, 2, 2.0, 'hardware'))
        );
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertPlanIsGeometricallySound($plan);

        foreach ($plan->packages as $package) {
            foreach ($package->contents as $fragile) {
                if (!$fragile->item->noStack) {
                    continue;
                }
                $f = $fragile->bounds();
                foreach ($package->contents as $other) {
                    if ($other === $fragile) {
                        continue;
                    }
                    $o = $other->bounds();
                    $sharesFootprint = $f['x'][1] > $o['x'][0] + Dimensions::EPSILON
                        && $o['x'][1] > $f['x'][0] + Dimensions::EPSILON
                        && $f['y'][1] > $o['y'][0] + Dimensions::EPSILON
                        && $o['y'][1] > $f['y'][0] + Dimensions::EPSILON;
                    $isAbove = $o['z'][0] >= $f['z'][1] - Dimensions::EPSILON;
                    $this->assertFalse(
                        $sharesFootprint && $isAbove,
                        'An item was stacked on top of a no-stack item.'
                    );
                }
            }
        }
    }

    /**
     * An own-box item never shares a parcel, whatever else is in the cart. This is the
     * mechanism that makes hazardous, must-not-ship-together and irregular goods safe
     * in phase 1 without any new schema.
     */
    public function testOwnBoxItemsNeverShareAParcel(): void
    {
        $items = array_merge(
            [$this->item(4, 4, 4, 3.0, 'hazmat', ownBox: true)],
            $this->units(2, $this->item(3, 3, 3, 1.0, 'normal'))
        );
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertPlanIsGeometricallySound($plan);

        $found = false;
        foreach ($plan->packages as $package) {
            foreach ($package->contents as $packed) {
                if ($packed->item->uprid === 'hazmat') {
                    $found = true;
                    $this->assertSame(1, $package->itemCount(), 'An own-box item must travel alone.');
                }
            }
        }
        $this->assertTrue($found, 'The own-box item must appear in the plan.');
    }

    /**
     * An item too large for any catalog box still ships, in a parcel of its own
     * dimensions, rather than being dropped from the plan.
     */
    public function testOwnBoxItemLargerThanEveryBoxBecomesItsOwnParcel(): void
    {
        $items = [$this->item(40, 30, 20, 60.0, 'furniture', ownBox: true)];
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertSame(1, $plan->packageCount());
        $this->assertNull($plan->packages[0]->boxType, 'No catalog box applies, so none should be claimed.');
        $this->assertSame(40.0, $plan->packages[0]->exterior->length);
    }
}
