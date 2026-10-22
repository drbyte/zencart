<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use Zencart\Shipping\Packing\Dimensions;
use Zencart\Shipping\Packing\PackedItem;
use Zencart\Shipping\Packing\PackingPlan;
use Zencart\Shipping\Packing\PackingRequest;
use Zencart\Shipping\Packing\Package;
use Zencart\Shipping\Packing\PlanExplainer;
use Zencart\Shipping\Packing\Vector3;
use Zencart\Shipping\Packing\Weight;

/**
 * The parts of the explainer that decide what an admin is told.
 *
 * The page itself is driven in PlanExplainerPageTest; these are the rules it renders.
 */
class PlanExplainerTest extends PackingTestCase
{
    public function testQuantitiesAreParsedInTheFormsPeopleActuallyType(): void
    {
        $quantities = PlanExplainer::parseQuantities("12 x 3\n7x2\n9\n  \n4, 5\n6:2");

        $this->assertSame([12 => 3.0, 7 => 2.0, 9 => 1.0, 4 => 5.0, 6 => 2.0], $quantities);
    }

    public function testRepeatedProductIdsAreSummedRatherThanOverwritten(): void
    {
        $this->assertSame([12 => 5.0], PlanExplainer::parseQuantities("12 x 2\n12 x 3"));
    }

    public function testUnparseableLinesAreIgnoredNotGuessedAt(): void
    {
        $this->assertSame([], PlanExplainer::parseQuantities("not a product\n-4\n0\nSELECT 1"));
    }

    public function testProductsQueryIsEmptyWithoutUsableIds(): void
    {
        $this->assertSame('', PlanExplainer::productsSql([]));
        $this->assertSame('', PlanExplainer::productsSql([0, -3]));
    }

    public function testProductsQueryAsksForEveryFieldThePackerNeeds(): void
    {
        $sql = PlanExplainer::productsSql([4, 4, 9]);

        foreach ([
            'products_weight', 'products_length', 'products_width', 'products_height',
            'products_virtual', 'product_is_always_free_shipping', 'product_ships_in_own_box',
            'products_no_stack', 'products_orientation_lock', 'products_name',
        ] as $column) {
            $this->assertStringContainsString($column, $sql, 'Query omits ' . $column);
        }
        $this->assertStringContainsString('IN (4,9)', $sql, 'Ids are deduplicated and cast.');
        $this->assertStringContainsString('language_id = 1', $sql, 'The default language applies when none is given.');
        $this->assertStringContainsString(
            'language_id = 3',
            PlanExplainer::productsSql([4], 3),
            'The admin looking at the page decides which language names the products.'
        );
    }

    public function testOrderLinesQueryIsScopedToTheOrder(): void
    {
        $sql = PlanExplainer::orderLinesSql(42);

        $this->assertStringContainsString('orders_id = 42', $sql);
        $this->assertStringContainsString('products_quantity', $sql);
    }

    public function testCartLinesCarryWhatTheAdapterReads(): void
    {
        $built = PlanExplainer::cartLines(
            [[
                'products_id' => '5',
                'products_name' => 'Widget',
                'products_model' => 'W-1',
                'products_weight' => '2.5000',
                'products_length' => '6.0000',
                'products_width' => '4.0000',
                'products_height' => '2.0000',
                'products_virtual' => '0',
                'product_is_always_free_shipping' => '0',
                'product_ships_in_own_box' => '1',
            ]],
            [5 => 3.0]
        );

        $this->assertSame([], $built['missing']);
        $line = $built['lines'][0];
        $this->assertSame(5, $line['id']);
        $this->assertSame(3.0, $line['quantity']);
        $this->assertSame(6.0, $line['length']);
        $this->assertSame(1, $line['ships_in_own_box']);
        $this->assertSame([], $line['attributes'], 'No cart means no attribute selections to know about.');
    }

    /**
     * A product that no longer exists must be named. Explaining a smaller cart than the
     * one asked about, without saying so, is the one answer worse than no answer.
     */
    public function testAQuantityForAMissingProductIsReportedNotDropped(): void
    {
        $built = PlanExplainer::cartLines([], [999 => 2.0]);

        $this->assertSame([], $built['lines']);
        $this->assertSame([999], $built['missing']);
    }

    /**
     * Fill is measured against the interior the packer was allowed to use, not the raw
     * one, or every correctly-packed box looks half empty whenever a margin is set.
     */
    public function testFillIsMeasuredAgainstTheUsableInterior(): void
    {
        $box = $this->box('M', 10.0, 10.0, 10.0);
        $item = $this->item(5.0, 10.0, 10.0, 1.0, 'half');
        $package = new Package(
            $box,
            $box->exterior,
            [new PackedItem($item, new Vector3(0.0, 0.0, 0.0), $item->dimensions)],
            new Weight(1.0),
            new Weight(0.0)
        );

        $this->assertSame(50.0, PlanExplainer::fillPercent($package, 0.0));
        $this->assertGreaterThan(
            50.0,
            PlanExplainer::fillPercent($package, 10.0),
            'A margin shrinks the space the packer could use, so the same contents fill more of it.'
        );
    }

    public function testFillIsUnknownForAParcelWithNoBox(): void
    {
        $item = $this->item(5.0, 5.0, 5.0, 1.0, 'loose');
        $package = new Package(
            null,
            $item->dimensions,
            [new PackedItem($item, new Vector3(0.0, 0.0, 0.0), $item->dimensions)],
            new Weight(1.0),
            new Weight(0.0)
        );

        $this->assertNull(PlanExplainer::fillPercent($package, 0.0));
    }

    public function testEveryReasonAPlanIsIncompleteIsReported(): void
    {
        $plan = new PackingPlan(
            [],
            [$this->item(9.0, 9.0, 9.0, 1.0, 'huge')],
            true,
            true,
            'zc-ffd-v1',
            'hash'
        );
        $request = new PackingRequest([], 2, ['7'], true, 9000);

        $this->assertSame([
            'TEXT_CAVEAT_FALLBACK',
            'TEXT_CAVEAT_APPROXIMATED',
            'TEXT_CAVEAT_CEILING',
            'TEXT_CAVEAT_MISSING_DIMENSIONS',
            'TEXT_CAVEAT_EXCLUDED',
            'TEXT_CAVEAT_UNPACKABLE',
        ], PlanExplainer::caveats($plan, $request));
    }

    public function testAnOrdinaryPlanHasNothingToApologizeFor(): void
    {
        $plan = new PackingPlan([], [], false, false, 'zc-ffd-v1', 'hash');
        $request = new PackingRequest([$this->item(1.0, 1.0, 1.0)], 0, [], false, 1);

        $this->assertSame([], PlanExplainer::caveats($plan, $request));
    }

    /**
     * Every caveat name has to exist as a language constant, or the page fatals on the
     * one cart that triggers it.
     */
    public function testEveryCaveatNameExistsInTheLanguageFile(): void
    {
        $defines = require __DIR__ . '/../../../../admin/includes/languages/english/lang.shipping_plan_explainer.php';

        $plan = new PackingPlan([], [$this->item(9.0, 9.0, 9.0)], true, true, 'c', 'h');
        foreach (PlanExplainer::caveats($plan, new PackingRequest([], 1, ['7'], true, 1)) as $key) {
            $this->assertArrayHasKey($key, $defines, $key . ' has no wording.');
        }
    }
}
