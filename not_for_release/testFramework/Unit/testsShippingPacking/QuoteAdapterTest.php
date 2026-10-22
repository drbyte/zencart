<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use Zencart\Shipping\Packing\FirstFitDecreasingPacker;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\PackingPlan;
use Zencart\Shipping\Packing\QuoteAdapter;
use Zencart\Shipping\Packing\Units;

/**
 * The handover from a packing plan to a shipping module.
 *
 * Core ships no dimensional carrier module, so every first consumer of a plan is a
 * plugin. This is the one supported path for them, which makes its edges worth pinning:
 * what counts as a usable plan, which figures a module may trust, and the deliberate
 * choice between rating each parcel and rating one envelope N times.
 */
class QuoteAdapterTest extends PackingTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS[QuoteAdapter::PLAN_GLOBAL]);
    }

    private function context(array $o = []): PackingContext
    {
        return new PackingContext(
            interiorMarginPercent: 0.0,
            dimDivisor: $o['dimDivisor'] ?? null,
            catalogRevision: 'test'
        );
    }

    private function twoParcelPlan(): PackingPlan
    {
        // Two 6x6x10 cartons cannot share a 10x10x10 interior, so this is reliably two.
        $items = $this->units(2, $this->item(6, 6, 10, 2.0, 'carton'));
        $boxes = [$this->box('CUBE', 10, 10, 10, tare: 0.5, wallThickness: 0.25)];

        return (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());
    }

    public function testNoGlobalMeansNoPlan(): void
    {
        $this->assertNull(QuoteAdapter::plan());
    }

    public function testSomethingOtherThanAPlanIsIgnored(): void
    {
        $GLOBALS[QuoteAdapter::PLAN_GLOBAL] = ['not' => 'a plan'];

        $this->assertNull(QuoteAdapter::plan(), 'A module must not have to type-check the global itself.');
    }

    /**
     * A legacy fallback is a real plan object describing no parcels. Handing it to a
     * module would have it quote a zero-sized package.
     */
    public function testLegacyFallbackIsWithheld(): void
    {
        $GLOBALS[QuoteAdapter::PLAN_GLOBAL] = PackingPlan::legacyFallback('zc-ffd-v1', 'h');

        $this->assertNull(QuoteAdapter::plan());
    }

    /**
     * A plan with unpackable items describes less than the cart, so quoting from it
     * would understate the shipment.
     */
    public function testPlanWithUnpackableItemsIsWithheld(): void
    {
        $items = array_merge(
            $this->units(1, $this->item(4, 4, 4, 1.0, 'small')),
            [$this->item(99, 99, 99, 1.0, 'enormous')]
        );
        $plan = (new FirstFitDecreasingPacker())->pack($items, [$this->box('S', 8, 8, 8)], $this->context());
        $GLOBALS[QuoteAdapter::PLAN_GLOBAL] = $plan;

        $this->assertTrue($plan->hasUnpackableItems());
        $this->assertNull(QuoteAdapter::plan());
    }

    public function testAUsablePlanIsHandedOver(): void
    {
        $plan = $this->twoParcelPlan();
        $GLOBALS[QuoteAdapter::PLAN_GLOBAL] = $plan;

        $this->assertSame($plan, QuoteAdapter::plan());
    }

    public function testPackageCountFallsBackToTheLegacyFigure(): void
    {
        $this->assertSame(7, QuoteAdapter::packageCount(null, 7));
        $this->assertSame(2, QuoteAdapter::packageCount($this->twoParcelPlan(), 7));
    }

    /**
     * An approximated plan reports its extrapolated count, not the parcels sampled, or
     * a large cart would be billed for a fraction of its real parcels.
     */
    public function testApproximatedPlanReportsTheExtrapolatedCount(): void
    {
        $items = $this->units(400, $this->item(2, 2, 2, 0.1, 'widget'));
        $plan = (new FirstFitDecreasingPacker())->pack(
            $items,
            [$this->box('M', 10, 10, 10)],
            new PackingContext(interiorMarginPercent: 0.0, maxUnits: 50, catalogRevision: 'test')
        );

        $this->assertTrue($plan->approximated);
        $this->assertSame($plan->quotablePackageCount(), QuoteAdapter::packageCount($plan, 1));
        $this->assertGreaterThan($plan->packageCount(), QuoteAdapter::packageCount($plan, 1));
    }

    public function testParcelsReportTheBoxExteriorNotTheContents(): void
    {
        $parcels = QuoteAdapter::parcels($this->twoParcelPlan());

        $this->assertCount(2, $parcels);
        $this->assertSame([1, 2], array_column($parcels, 'sequence'));
        $this->assertSame([2, 2], array_column($parcels, 'count'));
        $this->assertSame(10.5, $parcels[0]['length'], 'Exterior, which is what the carrier measures.');
        $this->assertSame(2.5, $parcels[0]['weight'], 'Contents plus tare.');
        $this->assertSame('CUBE', $parcels[0]['box_code']);
    }

    public function testParcelsConvertToTheUnitsACarrierWants(): void
    {
        $parcels = QuoteAdapter::parcels($this->twoParcelPlan(), Units::CENTIMETERS, Units::KILOGRAMS);

        $this->assertEqualsWithDelta(26.67, $parcels[0]['length'], 0.01);
        $this->assertEqualsWithDelta(1.134, $parcels[0]['weight'], 0.01);
    }

    /**
     * Each parcel is insured for what is in it. Dividing the order subtotal evenly
     * over-insures the light parcels and under-insures the heavy ones.
     *
     * Built through CartPackingAdapter rather than from hand-made items, because the
     * value lookup matches the plan's line keys against the cart's product ids and those
     * agree only because the adapter sets them so. Asserting it end to end pins that
     * coupling instead of assuming it.
     */
    public function testParcelValueComesFromItsOwnContents(): void
    {
        $cart = [[
            'id' => '77',
            'model' => 'CARTON',
            'quantity' => 2.0,
            'weight' => '2.0',
            'length' => '6.0000',
            'width' => '6.0000',
            'height' => '10.0000',
            'final_price' => 25.0,
            'products_virtual' => 0,
            'product_is_always_free_shipping' => 0,
        ]];

        $context = $this->context();
        $request = (new \Zencart\Shipping\Packing\CartPackingAdapter())->fromCartProducts($cart, $context);
        $plan = (new FirstFitDecreasingPacker())->pack(
            $request->items,
            [$this->box('CUBE', 10, 10, 10, tare: 0.5, wallThickness: 0.25)],
            $context
        );

        $this->assertSame(2, $plan->packageCount(), 'Two cartons cannot share the box.');

        $parcels = QuoteAdapter::parcels($plan, Units::INCHES, Units::POUNDS, $cart);

        $this->assertSame(25.0, $parcels[0]['value'], 'One carton at 25.00 in this parcel.');
        $this->assertSame(25.0, $parcels[1]['value']);
        $this->assertSame(50.0, array_sum(array_column($parcels, 'value')), 'Values sum to the order, not more.');
    }

    public function testParcelValueIsZeroWithoutCartPrices(): void
    {
        $this->assertSame(0.0, QuoteAdapter::parcels($this->twoParcelPlan())[0]['value']);
    }

    public function testBillableWeightIsCarriedThroughWhenKnown(): void
    {
        $items = $this->units(1, $this->item(6, 6, 10, 1.0, 'carton'));
        $plan = (new FirstFitDecreasingPacker())->pack(
            $items,
            [$this->box('CUBE', 10, 10, 10)],
            $this->context(['dimDivisor' => 139.0])
        );

        $parcels = QuoteAdapter::parcels($plan);

        $this->assertNotNull($parcels[0]['billable_weight']);
        $this->assertEqualsWithDelta(1000.0 / 139.0, $parcels[0]['billable_weight'], 0.01);
    }

    public function testBillableWeightIsNullWhenNoDivisorWasSupplied(): void
    {
        $this->assertNull(QuoteAdapter::parcels($this->twoParcelPlan())[0]['billable_weight']);
    }

    /**
     * The envelope bounds every parcel on every axis, so a module that rates it N times
     * can over-quote a mixed plan but never under-quote one.
     */
    public function testEnvelopeBoundsEveryParcelOnEveryAxis(): void
    {
        $items = [
            $this->item(9, 2, 2, 2.0, 'rod'),
            $this->item(2, 9, 2, 9.0, 'plank'),
            $this->item(2, 2, 9, 1.0, 'post'),
        ];
        // Each item needs its own box, so the parcels differ in nothing but orientation
        // and weight - which is exactly the case the envelope has to bound.
        $plan = (new FirstFitDecreasingPacker())->pack(
            $items,
            [$this->box('SNUG', 9, 9, 9)],
            $this->context()
        );

        $envelope = QuoteAdapter::envelope($plan);

        $this->assertNotNull($envelope);
        foreach (QuoteAdapter::parcels($plan) as $parcel) {
            $this->assertGreaterThanOrEqual($parcel['length'], $envelope['length']);
            $this->assertGreaterThanOrEqual($parcel['width'], $envelope['width']);
            $this->assertGreaterThanOrEqual($parcel['height'], $envelope['height']);
            $this->assertGreaterThanOrEqual($parcel['weight'], $envelope['weight']);
        }
        $this->assertSame($plan->quotablePackageCount(), $envelope['count']);
    }

    public function testEnvelopeOfASingleParcelIsThatParcel(): void
    {
        $items = $this->units(1, $this->item(6, 6, 6, 3.0, 'thing'));
        $plan = (new FirstFitDecreasingPacker())->pack($items, [$this->box('M', 10, 10, 10, tare: 0.5)], $this->context());

        $envelope = QuoteAdapter::envelope($plan);
        $parcel = QuoteAdapter::parcels($plan)[0];

        $this->assertSame($parcel['length'], $envelope['length']);
        $this->assertSame($parcel['weight'], $envelope['weight']);
        $this->assertSame(1, $envelope['count']);
    }

    /**
     * A plan whose parcels have no usable size yields no envelope, so a module falls
     * back rather than asking a carrier to rate a zero-sized package.
     */
    public function testEnvelopeIsNullWhenNoParcelHasAUsableSize(): void
    {
        $plan = new PackingPlan([], [], false, false, 'zc-ffd-v1', 'h');

        $this->assertNull(QuoteAdapter::envelope($plan));
    }

    /**
     * The documented contract: consuming a plan must not disturb the shared box count,
     * because shipping::quote() does not save and restore it between modules.
     */
    public function testConsumingAPlanLeavesTheSharedBoxCountAlone(): void
    {
        $GLOBALS['shipping_num_boxes'] = 1;
        $GLOBALS[QuoteAdapter::PLAN_GLOBAL] = $this->twoParcelPlan();

        $plan = QuoteAdapter::plan();
        QuoteAdapter::packageCount($plan, (int)$GLOBALS['shipping_num_boxes']);
        QuoteAdapter::parcels($plan);
        QuoteAdapter::envelope($plan);

        $this->assertSame(1, $GLOBALS['shipping_num_boxes']);
    }
}
