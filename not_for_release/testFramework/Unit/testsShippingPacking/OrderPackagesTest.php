<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use Zencart\Shipping\Packing\OrderPackages;
use Zencart\Shipping\Packing\PackingPersistence;

/**
 * Laying a recorded parcel breakdown out on a packing slip.
 *
 * The rule that matters most is that the slip can never print less than the order
 * contains. A warehouse cannot notice goods a document does not mention, so every order
 * line reaches the page whether a parcel claims it or not - and a line split across
 * parcels shows each parcel's own quantity, never the order-line total, or the picker
 * packs too many.
 */
class OrderPackagesTest extends PackingTestCase
{
    private function packageRow(array $overrides = []): array
    {
        return array_merge([
            'orders_packages_id' => 10,
            'package_sequence' => 1,
            'package_count' => 1,
            'box_id' => 3,
            'box_code' => 'M',
            'box_title' => 'Medium',
            'quoted_length' => '12.5000',
            'quoted_width' => '10.5000',
            'quoted_height' => '8.5000',
            'quoted_weight' => '4.2500',
            'dim_units' => 'inches',
            'weight_units' => 'pounds',
            'plan_origin' => PackingPersistence::ORIGIN_QUOTED,
            'plan_is_stale' => 0,
        ], $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderProducts(): array
    {
        return [
            0 => ['orders_products_id' => 101, 'name' => 'Widget', 'qty' => 3],
            1 => ['orders_products_id' => 102, 'name' => 'Gadget', 'qty' => 1],
        ];
    }

    public function testContentsAreAttachedToTheirParcel(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [
                ['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 2],
                ['orders_packages_id' => 10, 'orders_products_id' => 102, 'products_quantity' => 1],
            ]
        );

        $this->assertCount(1, $packages);
        $this->assertSame([101 => 2.0, 102 => 1.0], $packages[0]['quantities']);
        $this->assertSame('M', $packages[0]['box_code']);
        $this->assertFalse($packages[0]['plan_is_stale']);
    }

    public function testContentsForOtherOrdersAreNotAttached(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [['orders_packages_id' => 99, 'orders_products_id' => 101, 'products_quantity' => 5]]
        );

        $this->assertSame([], $packages[0]['quantities']);
    }

    /**
     * A line split across parcels must show each parcel's quantity. Showing the
     * order-line total on both would have the picker pack double.
     */
    public function testSplitLineShowsPerParcelQuantities(): void
    {
        $packages = OrderPackages::fromRows(
            [
                $this->packageRow(['orders_packages_id' => 10, 'package_sequence' => 1, 'package_count' => 2]),
                $this->packageRow(['orders_packages_id' => 11, 'package_sequence' => 2, 'package_count' => 2]),
            ],
            [
                ['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 2],
                ['orders_packages_id' => 11, 'orders_products_id' => 101, 'products_quantity' => 1],
            ]
        );

        $sequence = OrderPackages::renderSequence($packages, $this->orderProducts());

        $widgetRows = array_values(array_filter($sequence, static fn ($e) => $e['index'] === 0));
        $this->assertCount(2, $widgetRows);
        $this->assertSame([2.0, 1.0], array_column($widgetRows, 'qty'));
        $this->assertSame(3.0, array_sum(array_column($widgetRows, 'qty')), 'Per-parcel quantities sum to the line.');
    }

    /**
     * Only the first row of a parcel carries the parcel, so the page can print one
     * header per parcel rather than one per line.
     */
    public function testOnlyTheFirstRowOfAParcelOpensIt(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [
                ['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 3],
                ['orders_packages_id' => 10, 'orders_products_id' => 102, 'products_quantity' => 1],
            ]
        );

        $sequence = OrderPackages::renderSequence($packages, $this->orderProducts());

        $this->assertCount(2, $sequence);
        $this->assertNotNull($sequence[0]['parcel']);
        $this->assertNull($sequence[1]['parcel'], 'The second line continues the same parcel.');
    }

    /**
     * The case that protects the warehouse: a line no parcel claims still prints.
     *
     * A plan recorded before someone added a line in admin would otherwise leave goods
     * off the slip entirely.
     */
    public function testLinesNoParcelClaimsArePrintedAsUnassigned(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 3]]
        );

        $sequence = OrderPackages::renderSequence($packages, $this->orderProducts());

        $this->assertTrue(OrderPackages::hasUnassignedLines($sequence));
        $unassigned = array_values(array_filter($sequence, static fn ($e) => !empty($e['unassigned'])));
        $this->assertCount(1, $unassigned);
        $this->assertSame(1, $unassigned[0]['index'], 'The unclaimed Gadget line.');
        $this->assertSame(1, $unassigned[0]['qty'], 'It shows its full order quantity, as the order carries it.');
    }

    public function testEveryOrderLineAppearsAtLeastOnce(): void
    {
        $packages = OrderPackages::fromRows([$this->packageRow()], []);

        $sequence = OrderPackages::renderSequence($packages, $this->orderProducts());
        $printed = array_unique(array_filter(array_column($sequence, 'index'), static fn ($i) => $i >= 0));

        $this->assertSame([0, 1], array_values($printed), 'No order line may be omitted.');
    }

    /**
     * A parcel whose lines were all deleted in admin still existed and was paid for, so
     * it is announced rather than silently vanishing.
     */
    public function testAnEmptiedParcelIsStillAnnounced(): void
    {
        $packages = OrderPackages::fromRows([$this->packageRow()], []);

        $sequence = OrderPackages::renderSequence($packages, []);

        $this->assertCount(1, $sequence);
        $this->assertSame(-1, $sequence[0]['index'], 'No line to print...');
        $this->assertNotNull($sequence[0]['parcel'], '...but the parcel is still shown.');
    }

    public function testNoPackagesMeansEveryLinePrintsUnassignedInOrder(): void
    {
        $sequence = OrderPackages::renderSequence([], $this->orderProducts());

        $this->assertCount(2, $sequence);
        $this->assertSame([0, 1], array_column($sequence, 'index'));
        $this->assertSame([3, 1], array_column($sequence, 'qty'), 'Quantities are the order-line totals, unchanged.');
    }

    /**
     * With no recorded parcels the quantity is passed through exactly as the order
     * carries it, so an existing store's packing slips render identically. order's
     * product list already normalises a whole quantity to an int, and casting here
     * would change what every slip prints for no gain.
     */
    public function testUnassignedQuantitiesArePassedThroughUnchanged(): void
    {
        $products = [
            0 => ['orders_products_id' => 101, 'name' => 'Whole', 'qty' => 3],
            1 => ['orders_products_id' => 102, 'name' => 'Fractional', 'qty' => 2.5],
        ];

        $sequence = OrderPackages::renderSequence([], $products);

        $this->assertSame(3, $sequence[0]['qty'], 'A whole quantity stays an int.');
        $this->assertSame(2.5, $sequence[1]['qty']);
    }

    /**
     * The page's existing sort notifier must keep working, so the display order is
     * honoured within each parcel.
     */
    public function testSortOrderIsHonouredWithinAParcel(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [
                ['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 3],
                ['orders_packages_id' => 10, 'orders_products_id' => 102, 'products_quantity' => 1],
            ]
        );

        $sequence = OrderPackages::renderSequence($packages, $this->orderProducts(), [1, 0]);

        $this->assertSame([1, 0], array_column($sequence, 'index'));
    }

    public function testAnOutOfRangeSortIndexIsIgnoredRatherThanPrintingNothing(): void
    {
        $sequence = OrderPackages::renderSequence([], $this->orderProducts(), [5, 0]);

        $this->assertSame([0], array_column($sequence, 'index'));
    }

    public function testReconstructedOrStalePlansNeedACaveat(): void
    {
        $quoted = OrderPackages::fromRows([$this->packageRow()], []);
        $rebuilt = OrderPackages::fromRows(
            [$this->packageRow(['plan_origin' => PackingPersistence::ORIGIN_RECONSTRUCTED])],
            []
        );
        $stale = OrderPackages::fromRows([$this->packageRow(['plan_is_stale' => 1])], []);

        $this->assertFalse(OrderPackages::needsCaveat($quoted));
        $this->assertTrue(OrderPackages::needsCaveat($rebuilt), 'A rebuilt plan is not what was quoted.');
        $this->assertTrue(OrderPackages::needsCaveat($stale), 'A stale plan may not match the order.');
    }

    /**
     * A plan matching its order is not stale.
     */
    public function testAMatchingPlanIsNotOutOfDate(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [
                ['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 3],
                ['orders_packages_id' => 10, 'orders_products_id' => 102, 'products_quantity' => 1],
            ]
        );

        $this->assertFalse(OrderPackages::isOutOfDate($packages, $this->orderProducts()));
        $this->assertFalse(OrderPackages::needsCaveat($packages, $this->orderProducts()));
    }

    /**
     * A quantity edited in admin leaves the plan describing a different order, which is
     * exactly what the caveat is for.
     */
    public function testAnEditedQuantityMakesThePlanOutOfDate(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [
                ['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 3],
                ['orders_packages_id' => 10, 'orders_products_id' => 102, 'products_quantity' => 1],
            ]
        );

        $edited = $this->orderProducts();
        $edited[0]['qty'] = 5;

        $this->assertSame(['changed' => [101], 'missing' => [], 'extra' => []], OrderPackages::quantityMismatches($packages, $edited));
        $this->assertTrue(OrderPackages::isOutOfDate($packages, $edited));
        $this->assertTrue(OrderPackages::needsCaveat($packages, $edited));
    }

    public function testALineAddedAfterPackingIsReportedMissing(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 3]]
        );

        $mismatches = OrderPackages::quantityMismatches($packages, $this->orderProducts());

        $this->assertSame([102], $mismatches['missing'], 'The Gadget line was never packed.');
        $this->assertTrue(OrderPackages::isOutOfDate($packages, $this->orderProducts()));
    }

    /**
     * A line deleted in admin leaves the plan referencing goods the order no longer has.
     */
    public function testALineDeletedAfterPackingIsReportedExtra(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['orders_packages_id' => 10])],
            [
                ['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 3],
                ['orders_packages_id' => 10, 'orders_products_id' => 999, 'products_quantity' => 2],
            ]
        );

        $mismatches = OrderPackages::quantityMismatches($packages, $this->orderProducts());

        $this->assertSame([999], $mismatches['extra']);
        $this->assertTrue(OrderPackages::isOutOfDate($packages, $this->orderProducts()));
    }

    /**
     * Quantities split across parcels must be summed before comparing, or every
     * multi-parcel order would look stale.
     */
    public function testSplitQuantitiesAreSummedBeforeComparing(): void
    {
        $packages = OrderPackages::fromRows(
            [
                $this->packageRow(['orders_packages_id' => 10, 'package_sequence' => 1]),
                $this->packageRow(['orders_packages_id' => 11, 'package_sequence' => 2]),
            ],
            [
                ['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 2],
                ['orders_packages_id' => 11, 'orders_products_id' => 101, 'products_quantity' => 1],
                ['orders_packages_id' => 10, 'orders_products_id' => 102, 'products_quantity' => 1],
            ]
        );

        $this->assertFalse(
            OrderPackages::isOutOfDate($packages, $this->orderProducts()),
            'Three units across two parcels still match a line of three.'
        );
    }

    public function testAnOrderWithNoRecordedPlanIsNotConsideredStale(): void
    {
        $this->assertFalse(OrderPackages::isOutOfDate([], $this->orderProducts()));
        $this->assertFalse(OrderPackages::needsCaveat([], $this->orderProducts()));
    }

    /**
     * The stored flag still wins on its own, for tooling that says so explicitly at the
     * moment it edits a line.
     */
    public function testTheStoredFlagAloneRaisesTheCaveat(): void
    {
        $packages = OrderPackages::fromRows(
            [$this->packageRow(['plan_is_stale' => 1, 'orders_packages_id' => 10])],
            [['orders_packages_id' => 10, 'orders_products_id' => 101, 'products_quantity' => 3]]
        );

        $this->assertTrue(OrderPackages::needsCaveat($packages), 'No order needed when the flag is set.');
    }

    public function testMarkStaleUpdatesOnlyTheGivenOrder(): void
    {
        $captured = [];
        $db = new class($captured) {
            public function __construct(public array &$captured)
            {
            }

            public function Execute(string $sql): object
            {
                $this->captured[] = $sql;

                return new class {
                    public bool $EOF = true;
                    public array $fields = [];
                };
            }
        };

        OrderPackages::markStale($db, 4242);
        OrderPackages::markStale($db, 0);

        $this->assertCount(1, $captured, 'An invalid order id must not issue a statement.');
        $this->assertMatchesRegularExpression('/SET plan_is_stale = 1 WHERE orders_id = 4242/', $captured[0]);
    }

    public function testQueriesAreScopedToTheOrder(): void
    {
        $this->assertMatchesRegularExpression('/WHERE orders_id = 4242/', OrderPackages::packagesSql(4242));
        $this->assertMatchesRegularExpression('/ORDER BY package_sequence/', OrderPackages::packagesSql(4242));
        $this->assertMatchesRegularExpression('/WHERE orders_id = 4242/', OrderPackages::contentsSql(4242));
    }
}
