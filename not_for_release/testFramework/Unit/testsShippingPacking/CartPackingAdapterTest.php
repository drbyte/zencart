<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use Zencart\Shipping\Packing\CartPackingAdapter;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\Units;

/**
 * Turning a cart into packable units.
 *
 * Every case here is a shape that real cart data actually takes: nulls where a
 * dimension was never entered, strings where an int was expected, a renamed own-box
 * column, mixed units between lines, fractional quantities, and the several kinds of
 * line that must never become a parcel.
 */
class CartPackingAdapterTest extends PackingTestCase
{
    private function context(array $overrides = []): PackingContext
    {
        return new PackingContext(
            dimUnits: $overrides['dimUnits'] ?? Units::INCHES,
            weightUnits: $overrides['weightUnits'] ?? Units::POUNDS,
            catalogRevision: 'test'
        );
    }

    /**
     * Minimal realistic line, with the string-typed numerics the cart actually yields.
     *
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'id' => '42',
            'model' => 'WIDGET-1',
            'quantity' => 2.0,
            'weight' => '1.5',
            'weight_units' => Units::POUNDS,
            'dim_units' => Units::INCHES,
            'length' => '6.0000',
            'width' => '4.0000',
            'height' => '2.0000',
            'ships_in_own_box' => '0',
            'products_virtual' => 0,
            'product_is_always_free_shipping' => 0,
        ], $overrides);
    }

    public function testQuantityIsExpandedIntoDiscreteUnits(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts([$this->line()], $this->context());

        $this->assertSame(2, $request->unitCount());
        $this->assertSame(2, $request->declaredUnitTotal);
        $this->assertSame(6.0, $request->items[0]->dimensions->length);
        $this->assertSame(1.5, $request->items[0]->weight->value);
    }

    /**
     * A fraction of a dimensioned product cannot occupy part of a parcel, so the
     * quantity rounds up. Over-reserving space is the conservative direction.
     */
    public function testFractionalQuantityRoundsUp(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['quantity' => 2.5])],
            $this->context()
        );

        $this->assertSame(3, $request->unitCount());
    }

    public function testZeroOrNegativeQuantityIsExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['quantity' => 0]), $this->line(['quantity' => -1])],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
        $this->assertSame(2, $request->excludedLineCount);
    }

    public function testVirtualProductsAreExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['products_virtual' => 1])],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
        $this->assertSame(1, $request->excludedLineCount);
    }

    public function testGiftCertificatesAreExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['model' => 'GIFT100'])],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
    }

    public function testAlwaysFreeShippingProductsAreExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['product_is_always_free_shipping' => 1])],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
    }

    /**
     * The trap in this column. A value of 2 does not mean "free shipping, more so" - it
     * means "special, requires shipping", the product-with-a-download case that still
     * has a physical carton. Excluding it would silently drop real parcels from every
     * plan, and the error would show up only as quotes that are mysteriously too low.
     */
    public function testAlwaysFreeShippingValueTwoStillShips(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['product_is_always_free_shipping' => 2, 'quantity' => 1.0])],
            $this->context()
        );

        $this->assertSame(1, $request->unitCount(), 'A "special, requires shipping" product must be packed.');
    }

    /**
     * Missing dimensions are reported, never invented. The uprid list is what the
     * admin diagnostics and the plan explainer need in order to say which products to
     * go and measure.
     */
    public function testMissingDimensionsAreReportedNotFabricated(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['id' => '7', 'length' => null, 'width' => '', 'height' => '0.0000'])],
            $this->context()
        );

        $this->assertTrue($request->hasMissingDimensions());
        $this->assertSame(['7'], $request->missingDimensionUprids);
        $this->assertFalse($request->items[0]->dimensions->isUsable());
        $this->assertSame(0.0, $request->items[0]->dimensions->length);
    }

    public function testPerLineUnitsAreNormalizedToTheContext(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line([
                'quantity' => 1.0,
                'dim_units' => Units::CENTIMETERS,
                'length' => '10.16',
                'width' => '10.16',
                'height' => '10.16',
                'weight_units' => Units::KILOGRAMS,
                'weight' => '0.4535924',
            ])],
            $this->context()
        );

        $item = $request->items[0];
        $this->assertSame(Units::INCHES, $item->dimensions->units);
        $this->assertEqualsWithDelta(4.0, $item->dimensions->length, 0.001);
        $this->assertEqualsWithDelta(1.0, $item->weight->value, 0.001);
    }

    /**
     * An unrecognised or empty unit string falls back to the store default rather than
     * throwing. Legacy rows carry all sorts of things in these columns, and a quote
     * page is the wrong place to discover that.
     */
    public function testUnknownUnitStringsFallBackToTheStoreDefault(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['quantity' => 1.0, 'dim_units' => 'cubits', 'weight_units' => ''])],
            $this->context()
        );

        $this->assertSame(Units::INCHES, $request->items[0]->dimensions->units);
        $this->assertSame(Units::POUNDS, $request->items[0]->weight->units);
    }

    public function testOwnBoxFlagIsReadPermissively(): void
    {
        $adapter = new CartPackingAdapter();
        $context = $this->context();

        $this->assertTrue(
            $adapter->fromCartProducts([$this->line(['quantity' => 1.0, 'ships_in_own_box' => '1'])], $context)
                ->items[0]->shipsInOwnBox
        );
        $this->assertFalse(
            $adapter->fromCartProducts([$this->line(['quantity' => 1.0, 'ships_in_own_box' => null])], $context)
                ->items[0]->shipsInOwnBox
        );
        $this->assertFalse(
            $adapter->fromCartProducts([$this->line(['quantity' => 1.0, 'ships_in_own_box' => '0'])], $context)
                ->items[0]->shipsInOwnBox
        );
    }

    /**
     * The no-stack and orientation-lock columns do not exist yet and get_products()
     * does not publish them, so absent keys must default to false rather than
     * tripping a notice.
     */
    public function testAbsentFutureFlagsDefaultToFalse(): void
    {
        $line = $this->line(['quantity' => 1.0]);
        unset($line['no_stack'], $line['orientation_lock']);

        $item = (new CartPackingAdapter())->fromCartProducts([$line], $this->context())->items[0];

        $this->assertFalse($item->noStack);
        $this->assertFalse($item->orientationLock);
    }

    /**
     * Above the hard ceiling the cart is declined outright rather than sampled, so
     * quoting falls back to the legacy weight calculation. Expansion itself must not
     * be allowed to exhaust memory, and declining beats compounding two separate
     * approximations.
     */
    public function testCartsAboveTheHardCeilingAreDeclined(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['quantity' => (float)(CartPackingAdapter::HARD_UNIT_CEILING + 1)])],
            $this->context()
        );

        $this->assertTrue($request->exceedsUnitCeiling);
        $this->assertFalse($request->isPackable());
        $this->assertSame([], $request->items, 'A declined cart must not leave a partial expansion behind.');
    }

    public function testUnitsOfTheSameLineShareALineKey(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['id' => '42:abc', 'quantity' => 3.0])],
            $this->context()
        );

        $keys = array_unique(array_map(static fn ($i) => $i->lineKey, $request->items));
        $this->assertSame(['42:abc'], array_values($keys));
    }

    public function testEmptyCartYieldsAnUnpackableRequest(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts([], $this->context());

        $this->assertFalse($request->isPackable());
        $this->assertFalse($request->exceedsUnitCeiling);
        $this->assertSame(0, $request->declaredUnitTotal);
    }
}
