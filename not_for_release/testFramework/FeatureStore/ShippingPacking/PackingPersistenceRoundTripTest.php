<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\FeatureStore\ShippingPacking;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\zcInProcessFeatureTestCaseStore;
use Zencart\Shipping\Packing\BoxType;
use Zencart\Shipping\Packing\Dimensions;
use Zencart\Shipping\Packing\FirstFitDecreasingPacker;
use Zencart\Shipping\Packing\OrderPackages;
use Zencart\Shipping\Packing\PackableItem;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\PackingPersistence;
use Zencart\Shipping\Packing\PackingPlan;
use Zencart\Shipping\Packing\Units;
use Zencart\Shipping\Packing\Weight;

/**
 * The packing plan through a real database and back.
 *
 * Everything else about persistence is unit-tested against recording doubles, which
 * proves the row shapes but never executes an INSERT. This is the only test that puts
 * the plan through zen_db_perform and the actual schema, so it is what catches a column
 * name that does not exist, a DECIMAL(8,4) that cannot hold what the packer produced, or
 * a 'now()' the writer mishandles - all of which would otherwise surface on a live
 * store's first multi-parcel order.
 */
#[Group('parallel-candidate')]
class PackingPersistenceRoundTripTest extends zcInProcessFeatureTestCaseStore
{
    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    private const ORDERS_ID = 909901;

    protected function tearDown(): void
    {
        $db = $this->db();
        $db->Execute('DELETE FROM ' . TABLE_ORDERS_PACKAGES . ' WHERE orders_id = ' . self::ORDERS_ID);
        $db->Execute('DELETE FROM ' . TABLE_ORDERS_PACKAGES_PRODUCTS . ' WHERE orders_id = ' . self::ORDERS_ID);

        parent::tearDown();
    }

    private function db(): \queryFactory
    {
        if (!class_exists('queryFactory')) {
            require_once ROOTCWD . 'includes/classes/class.base.php';
            require_once ROOTCWD . 'includes/classes/db/' . DB_TYPE . '/query_factory.php';
        }
        // The writer inserts through zen_db_perform, which is the whole point of this
        // test, so the procedural database helpers have to be loaded too.
        if (!function_exists('zen_db_perform')) {
            require_once ROOTCWD . 'includes/functions/database.php';
        }
        if (!defined('USE_PCONNECT')) {
            define('USE_PCONNECT', 'false');
        }

        $db = new \queryFactory();
        $db->connect(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, USE_PCONNECT, false);

        // zen_db_perform() escapes through the global $db rather than any handle passed
        // to it, exactly as it does in production, so the global has to be the same
        // connection this test asserts against.
        $GLOBALS['db'] = $db;

        return $db;
    }

    /**
     * Two 6x6x10 cartons cannot share a 10x10x10 interior, so this is reliably two
     * parcels with one line split across them - the case the slip has to get right.
     */
    private function twoParcelPlan(): PackingPlan
    {
        $items = [];
        for ($i = 0; $i < 2; $i++) {
            $items[] = new PackableItem('77', '77', new Dimensions(6, 6, 10), new Weight(2.0));
        }
        $boxes = [new BoxType(
            null,
            'CUBE',
            'Cube Carton',
            new Dimensions(10, 10, 10),
            new Dimensions(10.25, 10.25, 10.25),
            new Weight(0.5),
            new Weight(50)
        )];

        return (new FirstFitDecreasingPacker())->pack(
            $items,
            $boxes,
            new PackingContext(interiorMarginPercent: 0.0, dimDivisor: 139.0, catalogRevision: 'feature')
        );
    }

    private function persist(PackingPlan $plan, \queryFactory $db, string $origin = PackingPersistence::ORIGIN_QUOTED): int
    {
        return PackingPersistence::write(
            $plan,
            self::ORDERS_ID,
            ['77' => 5501],
            static function (string $table, array $row) use ($db): int {
                zen_db_perform($table, $row);

                return (int)$db->insert_ID();
            },
            $origin,
            date('Y-m-d H:i:s')
        );
    }

    /**
     * @return array{packages: array<int, array<string, mixed>>, contents: array<int, array<string, mixed>>}
     */
    private function readBack(\queryFactory $db): array
    {
        $packages = [];
        $result = $db->Execute(OrderPackages::packagesSql(self::ORDERS_ID));
        while (!$result->EOF) {
            $packages[] = $result->fields;
            $result->MoveNext();
        }

        $contents = [];
        $result = $db->Execute(OrderPackages::contentsSql(self::ORDERS_ID));
        while (!$result->EOF) {
            $contents[] = $result->fields;
            $result->MoveNext();
        }

        return ['packages' => $packages, 'contents' => $contents];
    }

    public function testTheSchemaExistsWithEveryColumnTheWriterUses(): void
    {
        $db = $this->db();

        foreach ([TABLE_ORDERS_PACKAGES, TABLE_ORDERS_PACKAGES_PRODUCTS, TABLE_SHIPPING_BOXES] as $table) {
            $this->assertFalse(
                $db->Execute("SHOW TABLES LIKE '" . $table . "'")->EOF,
                $table . ' is missing: the install SQL and the snapshot fingerprint have diverged.'
            );
        }

        $columns = [];
        $result = $db->Execute('SHOW COLUMNS FROM ' . TABLE_ORDERS_PACKAGES);
        while (!$result->EOF) {
            $columns[] = $result->fields['Field'];
            $result->MoveNext();
        }

        foreach (\Zencart\Shipping\Packing\PackingSchema::tables()['orders_packages'] as $declared) {
            $this->assertContains($declared, $columns, 'orders_packages is missing declared column ' . $declared);
        }
    }

    /**
     * The test that justifies this file: a real INSERT, through zen_db_perform, of every
     * value the packer produces.
     */
    public function testAPlanSurvivesBeingWrittenAndReadBack(): void
    {
        $db = $this->db();
        $plan = $this->twoParcelPlan();

        $this->assertSame(2, $plan->packageCount());
        $written = $this->persist($plan, $db);
        $this->assertSame(2, $written);

        $stored = $this->readBack($db);
        $this->assertCount(2, $stored['packages']);
        $this->assertCount(2, $stored['contents']);

        $first = $stored['packages'][0];
        $this->assertSame(1, (int)$first['package_sequence']);
        $this->assertSame(2, (int)$first['package_count']);
        $this->assertSame('CUBE', $first['box_code']);
        $this->assertSame('Cube Carton', $first['box_title']);
        $this->assertSame(PackingPersistence::ORIGIN_QUOTED, $first['plan_origin']);
        $this->assertSame(0, (int)$first['plan_is_stale']);
        $this->assertSame(FirstFitDecreasingPacker::CODE, $first['packer_code']);
        $this->assertSame(Units::INCHES, $first['dim_units']);
        $this->assertSame(Units::POUNDS, $first['weight_units']);
        $this->assertNotEmpty($first['packed_at'], "'now()' style timestamps must survive the writer.");

        // DECIMAL(8,4) must hold what the packer produced, to the precision it produced.
        $this->assertEqualsWithDelta(10.25, (float)$first['quoted_length'], 0.0001);
        $this->assertEqualsWithDelta(2.5, (float)$first['quoted_weight'], 0.0001, 'Contents plus tare.');
        $this->assertEqualsWithDelta(
            10.25 ** 3 / 139.0,
            (float)$first['quoted_billable_weight'],
            0.001,
            'Dimensional weight must round-trip, not be truncated to the integer part.'
        );
    }

    /**
     * Contents must resolve to the order line and sum to what was ordered, since this is
     * what a picker works from.
     */
    public function testContentsLinkToTheOrderLineAndSumToTheOrderedQuantity(): void
    {
        $db = $this->db();
        $this->persist($this->twoParcelPlan(), $db);

        $stored = $this->readBack($db);

        foreach ($stored['contents'] as $row) {
            $this->assertSame(5501, (int)$row['orders_products_id']);
            $this->assertSame(self::ORDERS_ID, (int)$row['orders_id']);
        }
        $this->assertSame(
            2.0,
            array_sum(array_map(static fn ($r) => (float)$r['products_quantity'], $stored['contents'])),
            'Per-parcel quantities must sum to the two cartons ordered.'
        );
    }

    /**
     * Contents rows must point at the generated parcel ids, not at sequence numbers.
     * Getting this wrong is invisible until a slip prints the wrong parcel's contents.
     */
    public function testContentsPointAtRealParcelIds(): void
    {
        $db = $this->db();
        $this->persist($this->twoParcelPlan(), $db);

        $stored = $this->readBack($db);
        $parcelIds = array_map(static fn ($p) => (int)$p['orders_packages_id'], $stored['packages']);

        foreach ($stored['contents'] as $row) {
            $this->assertContains(
                (int)$row['orders_packages_id'],
                $parcelIds,
                'A contents row references a parcel id that does not exist.'
            );
        }
        // Compared as sets: the contents query has no ORDER BY, and does not need one -
        // fromRows() groups by parcel id when the slip is built.
        $contentsParcelIds = array_unique(array_map(static fn ($r) => (int)$r['orders_packages_id'], $stored['contents']));
        sort($contentsParcelIds);
        sort($parcelIds);
        $this->assertSame(
            $parcelIds,
            array_values($contentsParcelIds),
            'Each parcel should own one contents row for this single-line cart.'
        );
    }

    /**
     * The stored rows must rebuild into the layout the packing slip renders from, which
     * is the whole point of persisting them.
     */
    public function testStoredRowsRebuildIntoTheSlipLayout(): void
    {
        $db = $this->db();
        $this->persist($this->twoParcelPlan(), $db);
        $stored = $this->readBack($db);

        $packages = OrderPackages::fromRows($stored['packages'], $stored['contents']);
        $this->assertCount(2, $packages);
        $this->assertFalse(OrderPackages::needsCaveat($packages), 'A quoted, fresh plan needs no caveat.');

        $orderProducts = [0 => ['orders_products_id' => 5501, 'name' => 'Carton', 'qty' => 2]];
        $sequence = OrderPackages::renderSequence($packages, $orderProducts);

        $this->assertCount(2, $sequence, 'One printable row per parcel for this split line.');
        $this->assertNotNull($sequence[0]['parcel']);
        $this->assertNotNull($sequence[1]['parcel'], 'A second parcel opens its own heading.');
        $this->assertFalse(OrderPackages::hasUnassignedLines($sequence));
        $this->assertSame(
            2.0,
            array_sum(array_column($sequence, 'qty')),
            'The slip must show the ordered quantity in total, split across parcels.'
        );
    }

    public function testAReconstructedPlanIsStoredAsSuchAndRaisesTheCaveat(): void
    {
        $db = $this->db();
        $this->persist($this->twoParcelPlan(), $db, PackingPersistence::ORIGIN_RECONSTRUCTED);

        $stored = $this->readBack($db);
        $packages = OrderPackages::fromRows($stored['packages'], $stored['contents']);

        $this->assertSame(PackingPersistence::ORIGIN_RECONSTRUCTED, $stored['packages'][0]['plan_origin']);
        $this->assertTrue(
            OrderPackages::needsCaveat($packages),
            'A rebuilt plan must not be presented as what the customer was quoted.'
        );
    }

    /**
     * An own-box parcel has no catalog box, so box_id must store NULL rather than 0 -
     * otherwise it would appear to reference a real catalog row.
     */
    public function testAParcelWithNoCatalogBoxStoresANullBoxId(): void
    {
        $db = $this->db();

        $item = new PackableItem('77', '77', new Dimensions(40, 30, 20), new Weight(60.0), shipsInOwnBox: true);
        $plan = (new FirstFitDecreasingPacker())->pack(
            [$item],
            [new BoxType(null, 'S', 'Small', new Dimensions(8, 8, 8), new Dimensions(8, 8, 8), Weight::zero())],
            new PackingContext(interiorMarginPercent: 0.0, catalogRevision: 'feature')
        );

        $this->assertSame(1, $plan->packageCount());
        $this->persist($plan, $db);

        $stored = $this->readBack($db);
        $this->assertNull($stored['packages'][0]['box_id'], 'No catalog box applied, so none may be claimed.');
        $this->assertSame('', $stored['packages'][0]['box_code']);
        $this->assertEqualsWithDelta(40.0, (float)$stored['packages'][0]['quoted_length'], 0.0001);
    }

    public function testNothingIsWrittenForAPlanThatCannotBeTrusted(): void
    {
        $db = $this->db();

        $this->assertSame(0, $this->persist(PackingPlan::legacyFallback('zc-ffd-v1', 'h'), $db));

        $stored = $this->readBack($db);
        $this->assertSame([], $stored['packages'], 'A fallback plan must leave no trace on the order.');
    }
}
