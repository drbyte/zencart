<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use Zencart\Shipping\Packing\BoxType;
use Zencart\Shipping\Packing\FirstFitDecreasingPacker;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\Units;

/**
 * Policy, determinism, limits and units - the behaviour a store owner can observe and
 * configure, as opposed to the geometry covered by PackerSoundnessTest.
 *
 * The missing-dimensions cases matter most. Most existing stores have never entered
 * product dimensions, so the fallback behaviour is what they will actually experience
 * on upgrade, and it has to be an exact no-op rather than a surprise.
 */
class PackerPolicyTest extends PackingTestCase
{
    private function context(array $overrides = []): PackingContext
    {
        return new PackingContext(
            isDomestic: $overrides['isDomestic'] ?? true,
            interiorMarginPercent: $overrides['margin'] ?? 0.0,
            maxGirth: $overrides['maxGirth'] ?? 0.0,
            dimDivisor: $overrides['dimDivisor'] ?? null,
            carrierCode: $overrides['carrierCode'] ?? null,
            missingDimensionsPolicy: $overrides['policy'] ?? PackingContext::POLICY_LEGACY,
            defaultBoxCode: $overrides['defaultBoxCode'] ?? null,
            maxUnits: $overrides['maxUnits'] ?? 5000,
            catalogRevision: $overrides['catalogRevision'] ?? 'test'
        );
    }

    public function testOneDimensionlessItemMakesTheWholeCartFallBack(): void
    {
        $items = [
            $this->item(4, 4, 4, 1.0, 'measured'),
            $this->item(0, 0, 0, 2.0, 'unmeasured'),
        ];
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertTrue($plan->legacyFallback);
        $this->assertTrue($plan->isEmpty(), 'A fallback plan must not offer a partial packing.');
        $this->assertFalse($plan->isQuotable());
        $this->assertFalse($plan->isPersistable());
    }

    /**
     * A fully-measured cart must never fall back, or stores would see their quotes
     * flip models for no visible reason.
     */
    public function testFullyMeasuredCartDoesNotFallBack(): void
    {
        $items = $this->units(2, $this->item(4, 4, 4, 1.0, 'measured'));
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertFalse($plan->legacyFallback);
        $this->assertTrue($plan->isQuotable());
        $this->assertTrue($plan->isPersistable());
    }

    public function testDefaultBoxPolicyPacksDimensionlessItemsByWeight(): void
    {
        $items = $this->units(3, $this->item(0, 0, 0, 4.0, 'unmeasured'));
        $boxes = [$this->box('DEF', 12, 12, 12, maxPayload: 10.0)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context([
            'policy' => PackingContext::POLICY_DEFAULT_BOX,
            'defaultBoxCode' => 'DEF',
        ]));

        $this->assertFalse($plan->legacyFallback);
        $this->assertPayloadRespected($plan);
        $this->assertSame(2, $plan->packageCount(), '12 lb of goods into a 10 lb box is two parcels.');
        $this->assertSame(3, $this->totalPackedUnits($plan));
    }

    public function testDefaultBoxPolicyWithNoConfiguredBoxReportsItemsUnpackable(): void
    {
        $items = $this->units(2, $this->item(0, 0, 0, 1.0, 'unmeasured'));
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context([
            'policy' => PackingContext::POLICY_DEFAULT_BOX,
            'defaultBoxCode' => 'DOES-NOT-EXIST',
        ]));

        $this->assertCount(2, $plan->unpackable);
        $this->assertFalse($plan->isQuotable(), 'Unpackable items must make a plan non-quotable.');
    }

    public function testPayloadLimitSplitsParcelsEvenWhenSpaceRemains(): void
    {
        $items = $this->units(4, $this->item(2, 2, 2, 30.0, 'anvil'));
        $boxes = [$this->box('M', 20, 20, 20, maxPayload: 50.0)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertPlanIsGeometricallySound($plan);
        $this->assertPayloadRespected($plan);
        $this->assertSame(4, $plan->packageCount(), 'Two anvils already exceed the limit, so each ships alone.');
    }

    /**
     * A box whose own exterior breaches the carrier's girth limit can never produce a
     * shippable parcel, so it must be excluded before any placement work.
     */
    public function testGirthLimitExcludesOversizeBoxes(): void
    {
        $items = [$this->item(30, 30, 30, 5.0, 'bulky')];
        $boxes = [$this->box('HUGE', 40, 40, 40)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context(['maxGirth' => 130.0]));

        $this->assertCount(1, $plan->unpackable, 'A box over the girth limit is not a usable box.');
    }

    /**
     * Sort order is the store's preference between boxes it considers interchangeable,
     * and that is all it is: it decides a tie, never the choice itself.
     */
    public function testSortOrderDecidesBetweenTwoBoxesOfTheSameSize(): void
    {
        $items = [$this->item(4, 4, 4, 1.0, 'thing')];
        $packer = new FirstFitDecreasingPacker();

        $cheapFirst = $packer->pack(
            $items,
            [$this->box('PREMIUM', 12, 12, 12, sortOrder: 20), $this->box('CHEAP', 12, 12, 12, sortOrder: 10)],
            $this->context()
        );
        $premiumFirst = $packer->pack(
            $items,
            [$this->box('PREMIUM', 12, 12, 12, sortOrder: 10), $this->box('CHEAP', 12, 12, 12, sortOrder: 20)],
            $this->context()
        );

        $this->assertSame('CHEAP', $cheapFirst->packages[0]->boxType->code);
        $this->assertSame(
            'PREMIUM',
            $premiumFirst->packages[0]->boxType->code,
            'Reversing the preference must reverse the choice, not just the listing.'
        );
    }

    /**
     * The guard against the obvious misreading of the field: a store owner who puts a
     * big box at the top of the list must not be charged for it on small orders.
     */
    public function testSortOrderCannotPutALargerBoxAheadOfOneThatFits(): void
    {
        $items = [$this->item(4, 4, 4, 1.0, 'thing')];
        $boxes = [$this->box('HUGE', 24, 24, 24, sortOrder: 1), $this->box('SMALL', 6, 6, 6, sortOrder: 99)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $this->assertSame('SMALL', $plan->packages[0]->boxType->code, 'Volume decides, not preference.');
    }

    public function testCarrierSpecificBoxesAreOnlyOfferedToThatCarrier(): void
    {
        $items = [$this->item(4, 4, 4, 1.0, 'thing')];
        $boxes = [$this->box('FLATRATE', 12, 12, 12, carrierCode: 'usps')];
        $packer = new FirstFitDecreasingPacker();

        $wrongCarrier = $packer->pack($items, $boxes, $this->context(['carrierCode' => 'ups']));
        $rightCarrier = $packer->pack($items, $boxes, $this->context(['carrierCode' => 'usps']));

        $this->assertCount(1, $wrongCarrier->unpackable);
        $this->assertSame(1, $rightCarrier->packageCount());
    }

    /**
     * A box with no carrier of its own is generic stock and must be offered to every
     * carrier, including one quoting under its own code. Only carrier-owned packaging -
     * a flat-rate envelope - is restricted.
     */
    public function testBoxesWithoutACarrierAreOfferedToEveryCarrier(): void
    {
        $items = [$this->item(4, 4, 4, 1.0, 'thing')];
        $boxes = [$this->box('GENERIC', 12, 12, 12)];
        $packer = new FirstFitDecreasingPacker();

        foreach ([null, 'usps', 'ups'] as $carrier) {
            $plan = $packer->pack($items, $boxes, $this->context(['carrierCode' => $carrier]));
            $this->assertSame(
                1,
                $plan->packageCount(),
                'A generic box must be usable when quoting ' . ($carrier ?? 'no specific carrier') . '.'
            );
            $this->assertCount(0, $plan->unpackable);
        }
    }

    public function testInternationalScopeExcludesDomesticOnlyBoxes(): void
    {
        $items = [$this->item(4, 4, 4, 1.0, 'thing')];
        $boxes = [$this->box('DOM', 12, 12, 12, scope: BoxType::SCOPE_DOMESTIC)];
        $packer = new FirstFitDecreasingPacker();

        $this->assertCount(1, $packer->pack($items, $boxes, $this->context(['isDomestic' => false]))->unpackable);
        $this->assertSame(1, $packer->pack($items, $boxes, $this->context(['isDomestic' => true]))->packageCount());
    }

    /**
     * Cart ordering must not change the plan, or the memoized hash would miss and the
     * record persisted against an order could differ from the quote the customer saw.
     */
    public function testPlanIsDeterministicRegardlessOfInputOrder(): void
    {
        $items = [
            $this->item(5, 4, 3, 1.0, 'a'),
            $this->item(2, 2, 2, 0.5, 'b'),
            $this->item(8, 6, 4, 3.0, 'c'),
            $this->item(1, 1, 9, 0.2, 'd'),
        ];
        $boxes = [$this->box('S', 6, 6, 6), $this->box('M', 12, 10, 8)];
        $packer = new FirstFitDecreasingPacker();
        $context = $this->context();

        $forward = $packer->pack($items, $boxes, $context);
        $reversed = $packer->pack(array_reverse($items), $boxes, $context);

        $this->assertSame($forward->planHash, $reversed->planHash);
        $this->assertSame($forward->packageCount(), $reversed->packageCount());
        $this->assertSame(
            $this->describe($forward),
            $this->describe($reversed),
            'Identical inputs in a different order must produce an identical plan.'
        );
    }

    public function testCatalogRevisionChangesThePlanHash(): void
    {
        $items = [$this->item(4, 4, 4, 1.0, 'thing')];
        $boxes = [$this->box('M', 12, 12, 12)];
        $packer = new FirstFitDecreasingPacker();

        $before = $packer->pack($items, $boxes, $this->context(['catalogRevision' => '2026-10-01 10:00:00']));
        $after = $packer->pack($items, $boxes, $this->context(['catalogRevision' => '2026-10-02 11:00:00']));

        $this->assertNotSame(
            $before->planHash,
            $after->planHash,
            'Editing a box must invalidate a memoized plan.'
        );
    }

    /**
     * Above the unit guard the packer samples the cart proportionally and extrapolates
     * the parcel count. The estimate has to be in the region of a full pack, and the
     * plan must declare itself unfit to persist, since it knows how many parcels there
     * will be but not which unit is in which.
     */
    public function testOversizedCartIsApproximatedRatherThanPackedInFull(): void
    {
        $items = $this->units(400, $this->item(2, 2, 2, 0.1, 'widget'));
        $boxes = [$this->box('M', 10, 10, 10)];
        $packer = new FirstFitDecreasingPacker();

        $full = $packer->pack($items, $boxes, $this->context(['maxUnits' => 5000]));
        $guarded = $packer->pack($items, $boxes, $this->context(['maxUnits' => 50]));

        $this->assertFalse($full->approximated);
        $this->assertTrue($guarded->approximated);
        $this->assertFalse($guarded->isPersistable(), 'An approximated plan is rating-only.');

        $this->assertGreaterThan(0, $guarded->quotablePackageCount());
        $this->assertLessThanOrEqual(
            $full->packageCount() * 2,
            $guarded->quotablePackageCount(),
            'The extrapolation must be in the region of a full pack, not an order of magnitude out.'
        );
    }

    /**
     * The guard must not claim an approximation it did not make.
     *
     * Sampling keeps at least one unit per distinct line, so a cart of many
     * single-unit lines samples to itself and no reduction is possible. Flagging that
     * as approximated would be false, and expensive: an approximated plan is not
     * persistable, so a cart that was in fact packed completely and exactly would
     * silently lose its per-parcel packing slip - the feature's main deliverable.
     */
    public function testManySingleUnitLinesArePackedInFullAndNotFlagged(): void
    {
        $items = [];
        for ($i = 0; $i < 120; $i++) {
            $items[] = $this->item(2, 2, 2, 0.1, 'sku' . $i);
        }
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context(['maxUnits' => 50]));

        $this->assertFalse(
            $plan->approximated,
            'Sampling could not reduce a set of distinct single-unit lines, so nothing was approximated.'
        );
        $this->assertTrue($plan->isPersistable(), 'A fully packed cart must keep its packing slip.');
        $this->assertSame(120, $this->totalPackedUnits($plan) + count($plan->unpackable));
        $this->assertSame($plan->packageCount(), $plan->quotablePackageCount());
    }

    /**
     * Own-box units are each their own parcel, which is exact, so even a large own-box
     * cart is never flagged as approximated and keeps its packing slip.
     */
    public function testOwnBoxUnitsAreExactNotEstimated(): void
    {
        $items = $this->units(300, $this->item(4, 4, 4, 1.0, 'crate', ownBox: true));
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context(['maxUnits' => 50]));

        $this->assertFalse($plan->approximated, 'One parcel per own-box unit is exact, not an estimate.');
        $this->assertTrue($plan->isPersistable());
        $this->assertSame(300, $plan->packageCount());
        $this->assertSame(300, $this->totalPackedUnits($plan));
    }

    /**
     * Own-box units consume the modelling budget, so they cannot leave the guard blind
     * to the rest of the cart.
     *
     * 45 own-box units against a budget of 50 leave room to model only 5 more, so the
     * 20 remaining items must be sampled. Before own-box units were counted, the guard
     * compared 20 against the full 50 and modelled everything, so a cart could do an
     * unbounded amount of work while appearing to be within its limit.
     */
    public function testOwnBoxUnitsConsumeTheModellingBudget(): void
    {
        $items = array_merge(
            $this->units(45, $this->item(4, 4, 4, 1.0, 'crate', ownBox: true)),
            $this->units(20, $this->item(2, 2, 2, 0.2, 'widget'))
        );
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context(['maxUnits' => 50]));

        $this->assertTrue(
            $plan->approximated,
            'With the budget spent on own-box parcels, the remaining items must be sampled.'
        );
        $this->assertGreaterThan($plan->packageCount(), $plan->quotablePackageCount());
    }

    /**
     * Identical own-box units must resolve to the same box without re-searching the
     * catalog for each one, and every parcel must still carry exactly one unit.
     */
    public function testIdenticalOwnBoxUnitsReuseTheSameBoxChoice(): void
    {
        $items = $this->units(50, $this->item(4, 4, 4, 1.0, 'crate', ownBox: true));
        $boxes = [$this->box('S', 5, 5, 5), $this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context());

        $codes = [];
        foreach ($plan->packages as $package) {
            $this->assertSame(1, $package->itemCount(), 'An own-box unit travels alone.');
            $codes[$package->boxType->code ?? 'none'] = true;
        }
        $this->assertSame(['S' => true], $codes, 'All identical units take the same smallest fitting box.');
    }

    public function testMixedUnitCartsNormalizeBeforePacking(): void
    {
        $inches = $this->item(4, 4, 4, 1.0, 'imperial');
        $metric = $this->item(10.16, 10.16, 10.16, 0.4535924, 'metric', dimUnits: Units::CENTIMETERS, weightUnits: Units::KILOGRAMS);

        $normalized = $metric->withUnits(Units::INCHES, Units::POUNDS);

        $this->assertEqualsWithDelta(4.0, $normalized->dimensions->length, 0.001);
        $this->assertEqualsWithDelta(1.0, $normalized->weight->value, 0.001);

        $boxes = [$this->box('M', 12, 12, 12)];
        $plan = (new FirstFitDecreasingPacker())->pack([$inches, $normalized], $boxes, $this->context());

        $this->assertPlanIsGeometricallySound($plan);
        $this->assertSame(2, $this->totalPackedUnits($plan));
    }

    /**
     * Billable weight is the greater of actual and dimensional weight. A light, bulky
     * parcel must bill on its size, which is the whole reason carriers introduced it.
     */
    public function testBillableWeightUsesDimensionalWeightWhenItIsGreater(): void
    {
        $items = [$this->item(10, 10, 10, 1.0, 'pillow')];
        $boxes = [$this->box('M', 12, 12, 12)];

        $plan = (new FirstFitDecreasingPacker())->pack($items, $boxes, $this->context(['dimDivisor' => 139.0]));

        $package = $plan->packages[0];
        $this->assertNotNull($package->billableWeight);
        $this->assertEqualsWithDelta(12.0 ** 3 / 139.0, $package->billableWeight->value, 0.001);
        $this->assertGreaterThan($package->weight->value, $package->billableWeight->value);
    }

    public function testBillableWeightIsNullWithoutADivisor(): void
    {
        $plan = (new FirstFitDecreasingPacker())->pack(
            [$this->item(4, 4, 4, 1.0, 'thing')],
            [$this->box('M', 12, 12, 12)],
            $this->context()
        );

        $this->assertNull($plan->packages[0]->billableWeight);
    }

    /**
     * The carrier is told the exterior of the box, not the bounding box of what is
     * inside it, because the exterior is what gets measured at the counter.
     */
    public function testCarrierFacingSizeIsTheBoxExteriorNotTheContents(): void
    {
        $plan = (new FirstFitDecreasingPacker())->pack(
            [$this->item(4, 4, 4, 1.0, 'thing')],
            [$this->box('M', 12, 12, 12, wallThickness: 0.25)],
            $this->context()
        );

        $this->assertEqualsWithDelta(12.5, $plan->packages[0]->exterior->length, 0.0001);
    }

    public function testParcelContentsAggregateToLineQuantities(): void
    {
        $items = $this->units(3, $this->item(2, 2, 2, 0.5, 'widget'));
        $plan = (new FirstFitDecreasingPacker())->pack($items, [$this->box('M', 12, 12, 12)], $this->context());

        $totals = [];
        foreach ($plan->packages as $package) {
            foreach ($package->quantitiesByLine() as $lineKey => $qty) {
                $totals[$lineKey] = ($totals[$lineKey] ?? 0.0) + $qty;
            }
        }

        $this->assertSame(['line-widget' => 3.0], $totals, 'Per-parcel quantities must sum to what was ordered.');
    }

    /**
     * Rendered for comparison in the determinism test: parcel-by-parcel box code and
     * placements, which is a stronger equality check than parcel count alone.
     */
    private function describe(\Zencart\Shipping\Packing\PackingPlan $plan): string
    {
        $lines = [];
        foreach ($plan->packages as $package) {
            $parts = [];
            foreach ($package->contents as $packed) {
                $parts[] = $packed->item->uprid . '@' . implode(',', $packed->placedAt->toArray())
                    . '/' . $packed->placedAs->signature();
            }
            sort($parts);
            $lines[] = ($package->boxType->code ?? 'none') . ':' . implode(' ', $parts);
        }
        sort($lines);

        return implode("\n", $lines);
    }
}
