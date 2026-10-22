<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use Zencart\Shipping\Packing\BoxRepository;
use Zencart\Shipping\Packing\FirstFitDecreasingPacker;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\PackingPersistence;
use Zencart\Shipping\Packing\PackingPlan;
use Zencart\Shipping\Packing\Units;

/**
 * Recording a plan against an order.
 *
 * The record is not a second opinion about what shipped - it is the plan that produced
 * the quote the customer accepted. These tests pin the parts of that contract which a
 * later refactor could quietly break: parcel numbering, the box snapshot, how contents
 * map back to order lines, and the refusal to record anything the order cannot
 * corroborate.
 */
class PackingPersistenceTest extends PackingTestCase
{
    private function context(): PackingContext
    {
        return new PackingContext(interiorMarginPercent: 0.0, catalogRevision: 'p');
    }

    private function planWithTwoParcels(): PackingPlan
    {
        // Two 6x6x10 cartons cannot share a 10x10x10 interior, so this is reliably two
        // parcels without depending on heuristic tie-breaks.
        $items = $this->units(2, $this->item(6, 6, 10, 2.0, 'carton'));
        $boxes = [$this->box('CUBE', 10, 10, 10, tare: 0.5, wallThickness: 0.25)];

        return (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());
    }

    public function testParcelsAreNumberedOneOfN(): void
    {
        $plan = $this->planWithTwoParcels();
        $rows = PackingPersistence::rowsFor($plan, 1001, ['line-carton' => 55]);

        $this->assertCount(2, $rows['packages']);
        $this->assertSame([1, 2], array_column($rows['packages'], 'package_sequence'));
        $this->assertSame([2, 2], array_column($rows['packages'], 'package_count'));
        $this->assertSame([1001, 1001], array_column($rows['packages'], 'orders_id'));
    }

    /**
     * The box identity is copied onto the parcel, not referenced. A catalog row may be
     * renamed or deleted years before someone reprints the slip.
     */
    public function testBoxIdentityIsSnapshottedOntoTheParcel(): void
    {
        $rows = PackingPersistence::rowsFor($this->planWithTwoParcels(), 1001, ['line-carton' => 55]);

        foreach ($rows['packages'] as $package) {
            $this->assertSame('CUBE', $package['box_code']);
            $this->assertSame('CUBE', $package['box_title']);
        }
    }

    /**
     * The carrier-facing dimensions recorded are the box exterior, which is what was
     * quoted and what the carrier will measure - never the contents' bounding box.
     */
    public function testQuotedDimensionsAreTheBoxExterior(): void
    {
        $rows = PackingPersistence::rowsFor($this->planWithTwoParcels(), 1001, ['line-carton' => 55]);

        $this->assertSame(10.5, $rows['packages'][0]['quoted_length']);
        $this->assertSame(10.5, $rows['packages'][0]['quoted_width']);
        $this->assertSame(10.5, $rows['packages'][0]['quoted_height']);
        $this->assertSame(0.5, $rows['packages'][0]['tare_weight']);
        $this->assertSame(2.5, $rows['packages'][0]['quoted_weight'], 'Contents plus tare.');
        $this->assertSame(Units::INCHES, $rows['packages'][0]['dim_units']);
    }

    /**
     * Contents key on the order line, not the product, because two lines of the same
     * product with different attributes are physically different items.
     */
    public function testContentsMapToOrderLineIds(): void
    {
        $rows = PackingPersistence::rowsFor($this->planWithTwoParcels(), 1001, ['line-carton' => 55]);

        $this->assertCount(2, $rows['contents']);
        foreach ($rows['contents'] as $content) {
            $this->assertSame(55, $content['orders_products_id']);
            $this->assertSame(1001, $content['orders_id']);
            $this->assertSame(1.0, $content['products_quantity']);
        }
    }

    public function testQuantitiesAcrossParcelsSumToWhatWasOrdered(): void
    {
        $items = $this->units(5, $this->item(4, 4, 4, 1.0, 'widget'));
        $boxes = [$this->box('S', 8, 4, 4)];
        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $rows = PackingPersistence::rowsFor($plan, 7, ['line-widget' => 99]);

        $this->assertSame(5.0, array_sum(array_column($rows['contents'], 'products_quantity')));
    }

    /**
     * An own-box item records a parcel with no catalog box rather than inventing one.
     */
    public function testOwnBoxParcelWithoutACatalogBoxRecordsNullBoxId(): void
    {
        $items = [$this->item(40, 30, 20, 60.0, 'furniture', ownBox: true)];
        $boxes = [$this->box('S', 8, 8, 8)];
        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $rows = PackingPersistence::rowsFor($plan, 3, ['line-furniture' => 12]);

        $this->assertNull($rows['packages'][0]['box_id']);
        $this->assertSame('', $rows['packages'][0]['box_code']);
        $this->assertSame(40.0, $rows['packages'][0]['quoted_length']);
    }

    /**
     * A plan built for a cart the order does not corroborate must be visible, not
     * written against order line 0.
     */
    public function testUnmappedLinesAreReportedAndNotWritten(): void
    {
        $plan = $this->planWithTwoParcels();

        $this->assertSame(['line-carton'], PackingPersistence::unmappedLineKeys($plan, []));

        $rows = PackingPersistence::rowsFor($plan, 1001, []);
        $this->assertCount(2, $rows['packages'], 'Parcels are still described.');
        $this->assertSame([], $rows['contents'], 'But nothing is attributed to a line that does not exist.');
    }

    public function testFullyMappedPlanReportsNoUnmappedLines(): void
    {
        $this->assertSame(
            [],
            PackingPersistence::unmappedLineKeys($this->planWithTwoParcels(), ['line-carton' => 55])
        );
    }

    /**
     * Writing resolves each parcel's generated id before its contents are inserted, so
     * contents never point at a sequence number or a stale id.
     */
    public function testWriteLinksContentsToTheGeneratedParcelIds(): void
    {
        $inserted = [];
        $nextId = 500;
        $insert = function (string $table, array $row) use (&$inserted, &$nextId): int {
            $inserted[] = ['table' => $table, 'row' => $row];

            return $nextId++;
        };

        $written = PackingPersistence::write($this->planWithTwoParcels(), 1001, ['line-carton' => 55], $insert);

        $this->assertSame(2, $written);

        $packages = array_values(array_filter($inserted, static fn ($i) => str_contains($i['table'], 'orders_packages') && !str_contains($i['table'], 'products')));
        $contents = array_values(array_filter($inserted, static fn ($i) => str_contains($i['table'], 'orders_packages_products')));

        $this->assertCount(2, $packages);
        $this->assertCount(2, $contents);
        $this->assertSame(500, $contents[0]['row']['orders_packages_id'], 'First parcel id.');
        $this->assertSame(502, $contents[1]['row']['orders_packages_id'], 'Second parcel id, not a sequence number.');
    }

    /**
     * Parcel and contents rows are written in one pass, so a dying request cannot leave
     * parcels with no contents - which would render as empty packing slips. MyISAM
     * gives no transaction to lean on.
     */
    public function testEachParcelIsFollowedImmediatelyByItsContents(): void
    {
        $order = [];
        $nextId = 10;
        PackingPersistence::write(
            $this->planWithTwoParcels(),
            1001,
            ['line-carton' => 55],
            function (string $table, array $row) use (&$order, &$nextId): int {
                $order[] = str_contains($table, 'products') ? 'content' : 'parcel';

                return $nextId++;
            }
        );

        $this->assertSame(['parcel', 'content', 'parcel', 'content'], $order);
    }

    public function testOriginDefaultsToQuotedAndCanBeMarkedReconstructed(): void
    {
        $plan = $this->planWithTwoParcels();

        $quoted = PackingPersistence::rowsFor($plan, 1, ['line-carton' => 55]);
        $rebuilt = PackingPersistence::rowsFor($plan, 1, ['line-carton' => 55], PackingPersistence::ORIGIN_RECONSTRUCTED);

        $this->assertSame(PackingPersistence::ORIGIN_QUOTED, $quoted['packages'][0]['plan_origin']);
        $this->assertSame(PackingPersistence::ORIGIN_RECONSTRUCTED, $rebuilt['packages'][0]['plan_origin']);
    }

    /**
     * A plan that is not persistable must write nothing at all. A legacy fallback has
     * no parcels, and an approximated plan knows the parcel count but not which unit is
     * in which, so recording it would produce slips whose quantities do not sum.
     */
    public function testNonPersistablePlansWriteNothing(): void
    {
        $calls = 0;
        $insert = function () use (&$calls): int {
            $calls++;

            return 1;
        };

        $fallback = PackingPlan::legacyFallback('zc-ffd-v1', 'hash');
        $this->assertSame(0, PackingPersistence::write($fallback, 1001, [], $insert));

        $approximated = new PackingPlan([], [], false, true, 'zc-ffd-v1', 'hash');
        $this->assertSame(0, PackingPersistence::write($approximated, 1001, [], $insert));

        $this->assertSame(0, $calls, 'Nothing may be written for a plan that cannot be trusted.');
    }

    public function testWriteRefusesAnInvalidOrderId(): void
    {
        $calls = 0;
        $written = PackingPersistence::write(
            $this->planWithTwoParcels(),
            0,
            ['line-carton' => 55],
            function () use (&$calls): int {
                $calls++;

                return 1;
            }
        );

        $this->assertSame(0, $written);
        $this->assertSame(0, $calls);
    }

    /**
     * Every column the schema declares for a parcel must be supplied, or an insert
     * silently relies on column defaults for data the plan actually knows.
     */
    public function testParcelRowsSupplyEveryMeaningfulSchemaColumn(): void
    {
        $rows = PackingPersistence::rowsFor($this->planWithTwoParcels(), 1001, ['line-carton' => 55]);
        $row = $rows['packages'][0];

        foreach ([
            'orders_id', 'package_sequence', 'package_count', 'box_id', 'box_code', 'box_title',
            'quoted_length', 'quoted_width', 'quoted_height', 'quoted_weight',
            'quoted_billable_weight', 'tare_weight', 'dim_units', 'weight_units',
            'packer_code', 'plan_origin', 'plan_is_stale', 'packed_at',
        ] as $column) {
            $this->assertArrayHasKey($column, $row, 'Parcel row omits ' . $column);
        }

        $this->assertSame(FirstFitDecreasingPacker::CODE, $row['packer_code']);
        $this->assertSame(0, $row['plan_is_stale']);
    }

    public function testBillableWeightIsRecordedWhenADivisorIsKnown(): void
    {
        $items = $this->units(1, $this->item(6, 6, 10, 1.0, 'carton'));
        $boxes = [$this->box('CUBE', 10, 10, 10)];
        $context = new PackingContext(interiorMarginPercent: 0.0, dimDivisor: 139.0, catalogRevision: 'p');
        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $context);

        $rows = PackingPersistence::rowsFor($plan, 1, ['line-carton' => 55]);

        $this->assertNotNull($rows['packages'][0]['quoted_billable_weight']);
        $this->assertEqualsWithDelta(1000.0 / 139.0, $rows['packages'][0]['quoted_billable_weight'], 0.001);
    }
}
